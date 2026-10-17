<?php

declare(strict_types=1);

namespace Marque\Bloodhound;

use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use Marque\Bloodhound\Console\Commands\AggregateLedger;
use Marque\Bloodhound\Console\Commands\AuditLedger;
use Marque\Bloodhound\Console\Commands\PruneAnnounceLog;
use Marque\Bloodhound\Console\Commands\RebuildTotals;
use Marque\Bloodhound\Console\Commands\ReconcileLedger;
use Marque\Bloodhound\Console\Commands\RecordPrunedHistory;
use Marque\Bloodhound\Console\Commands\SyncSwarmCounts;
use Marque\Bloodhound\Contracts\AnnounceLogServiceInterface;
use Marque\Bloodhound\Events\TorrentCompleted;
use Marque\Bloodhound\Listeners\IssueAnnounceKeyOnVerification;
use Marque\Bloodhound\Listeners\RecordCompletion;
use Marque\Bloodhound\Contracts\SeedboxRegistry;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Support\NoSeedboxes;
use Marque\Bloodhound\Services\AnnounceLogService;
use Marque\Bloodhound\Services\AnnounceService;
use Marque\Bloodhound\Services\AntiCheatService;
use Marque\Bloodhound\Services\ClientValidationService;
use Marque\Bloodhound\Services\TorrentFilePolicy;
use Marque\Bloodhound\Services\TrackerStatsService;
use Marque\Threepio\Services\PeerService;
use Marque\Trove\Contracts\TorrentFilePolicyInterface;
use Marque\Trove\Contracts\TrackerStatsInterface;

class BloodhoundServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bloodhound.php', 'bloodhound');

        // Register services as singletons
        $this->app->singleton(ClientValidationService::class);
        $this->app->singleton(AntiCheatService::class);
        $this->app->singleton(AnnounceService::class);

        // Bound to the interface (matching trove's TorrentServiceInterface
        // pattern) so a consumer can swap the query implementation — e.g. to
        // read the log from a warehouse rather than the table itself.
        $this->app->bind(AnnounceLogServiceInterface::class, AnnounceLogService::class);

        // trove declares the contract; bloodhound fulfils it (Spec #119). This
        // binding IS the capability: without bloodhound nothing binds, and
        // consumers asking app()->bound() get a clean "no tracker here".
        // Unconditional on purpose — the figures exist in every ratio_mode.
        $this->app->singleton(TrackerStatsInterface::class, TrackerStatsService::class);

        // What downloads and uploads need from this tracker (#10947): the
        // member's keyed announce URL, and private torrents by default.
        $this->app->singleton(TorrentFilePolicyInterface::class, TorrentFilePolicy::class);

        // Which announces are held to the seedbox speed cap. The default knows
        // no seedboxes; bloodhound's registered-seedbox store replaces it.
        $this->app->singletonIf(SeedboxRegistry::class, NoSeedboxes::class);

        // Teach threepio's peer store to recover a lost baseline from the
        // ledger (Spec #99 CP3).
        //
        // threepio cannot do this itself: bloodhound depends on threepio, so
        // threepio has no access to announce_log and no notion of a durable
        // record. It exposes the seam; the private tracker fills it. hound
        // leaves it unfilled and keeps the old behaviour, which is right —
        // a public tracker has no ledger and nothing to recover from.
        $this->app->extend(PeerService::class, function (PeerService $peers) {
            $peers->resolveBaselineUsing($this->recoverBaselineFromLedger(...));

            return $peers;
        });
    }

    /**
     * Recover a peer's last known cumulative counters from the ledger.
     *
     * Called only when Redis has no record of the peer, so this is the outage
     * path, not the hot path — a warm session never reaches it. Scoped to the
     * exact (user, torrent, peer): a different peer_id is a different client
     * session, a different torrent is unrelated, and another member's
     * counters are never this member's baseline (F15).
     *
     * The baseline is the last row that took part in the session: a refusal
     * credited nothing and diffed against nothing, so it is skipped (F5: its
     * counters were never accepted), and an opening balance is not an
     * announce. If that row is a stop, the session is over and there is no
     * baseline: the next announce starts a new one.
     *
     * @return array{uploaded: int, downloaded: int, left: int, last_action: int}|null
     */
    protected function recoverBaselineFromLedger(int $torrentId, string $peerId, ?int $userId = null): ?array
    {
        if (! config('bloodhound.announce_log.enabled', true)) {
            return null;
        }

        $last = AnnounceLog::query()
            ->where('torrent_id', $torrentId)
            ->where('peer_id', $peerId)
            ->where('user_id', $userId)
            ->where('event', '!=', AnnounceLog::EVENT_OPENING_BALANCE)
            ->where(fn ($query) => $query->where('anti_cheat_flagged', false)->orWhereNotNull('prior_up'))
            ->orderByDesc('id')
            ->first();

        if ($last === null || $last->event === 'stopped') {
            return null;
        }

        return [
            'uploaded' => $last->uploaded,
            'downloaded' => $last->downloaded,
            'left' => $last->left,
            'last_action' => $last->created_at->getTimestamp(),
        ];
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/tracker.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerEventListeners();
        $this->registerConsole();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/bloodhound.php' => config_path('bloodhound.php'),
            ], 'bloodhound-config');

            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'bloodhound-migrations');
        }
    }

    /**
     * Register event listeners.
     */
    protected function registerEventListeners(): void
    {
        Event::listen(TorrentCompleted::class, RecordCompletion::class);
        Event::listen(Verified::class, IssueAnnounceKeyOnVerification::class);
    }

    /**
     * Register Artisan commands and their schedules.
     *
     * The prune schedule is registered unconditionally rather than gated on
     * announce_log.enabled: config can change after boot (and an operator who
     * disables logging still wants the existing rows aged out), and the
     * command already no-ops when there's no retention window. A scheduled
     * task that decides at run time is more predictable than one whose
     * existence depends on boot-time config.
     */
    protected function registerConsole(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            AggregateLedger::class,
            AuditLedger::class,
            PruneAnnounceLog::class,
            RebuildTotals::class,
            RecordPrunedHistory::class,
            ReconcileLedger::class,
            SyncSwarmCounts::class,
        ]);

        // Every minute. This is the fallback that makes the queue optional
        // rather than load-bearing: even if every queued job were lost, this
        // tick folds the ledger and the totals catch up. Cheap when there is
        // nothing pending — one indexed lookup against the cursor.
        Schedule::command('bloodhound:aggregate-ledger')->everyMinute()->withoutOverlapping();

        Schedule::command('bloodhound:prune-announce-log')->daily();

        // Hourly, not daily: this corrects torrents whose peers expired without
        // a stopped announce, and until it runs they show a swarm they no
        // longer have. A day of that is a catalogue full of torrents claiming
        // seeders that left yesterday.
        Schedule::command('bloodhound:sync-swarm-counts')->hourly();

        // Daily. This is the mechanism that turns a wrong number from
        // permanent-and-invisible into something an operator is told about,
        // so it runs unconditionally rather than being something to enable.
        Schedule::command('bloodhound:reconcile-ledger')->daily();
    }
}
