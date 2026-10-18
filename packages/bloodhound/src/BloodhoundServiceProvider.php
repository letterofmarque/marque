<?php

declare(strict_types=1);

namespace Marque\Bloodhound;

use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Marque\Bloodhound\Console\Commands\AggregateLedger;
use Marque\Bloodhound\Console\Commands\AuditLedger;
use Marque\Bloodhound\Console\Commands\BackfillLedger;
use Marque\Bloodhound\Console\Commands\CheckRedis;
use Marque\Bloodhound\Console\Commands\ManageSeedboxes;
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
use Marque\Bloodhound\Exceptions\RemovedConfiguration;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Services\RedisDurability;
use Marque\Bloodhound\Support\PeerIdColumn;
use Marque\Bloodhound\Services\Seedboxes;
use Marque\Bloodhound\Services\AnnounceLogService;
use Marque\Bloodhound\Services\AnnounceService;
use Marque\Bloodhound\Services\AntiCheatService;
use Marque\Bloodhound\Services\ClientValidationService;
use Marque\Bloodhound\Services\TorrentFilePolicy;
use Marque\Bloodhound\Services\TrackerStatsService;
use Marque\Threepio\Services\PeerService;
use Marque\Trove\Contracts\TorrentFilePolicyInterface;
use Marque\Trove\Contracts\SeedboxesInterface;
use Marque\Trove\Contracts\TrackerStatsInterface;
use Throwable;

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

        // Members' seedboxes (Job #193): one store, two faces. trove's
        // SeedboxesInterface is what a frontend manages them through;
        // SeedboxRegistry is the question the announce path asks.
        $this->app->singleton(Seedboxes::class);
        $this->app->singleton(SeedboxesInterface::class, fn ($app) => $app->make(Seedboxes::class));
        $this->app->singleton(SeedboxRegistry::class, fn ($app) => $app->make(Seedboxes::class));

        // Teach threepio's peer store to recover a lost baseline from the
        // ledger (Spec #99 CP3).
        //
        // threepio cannot do this itself: bloodhound depends on threepio, so
        // threepio has no access to announce_log and no notion of a durable
        // record. It exposes the seam; the private tracker fills it. hound
        // leaves it unfilled and keeps the old behaviour, which is right —
        // a public tracker has no ledger and nothing to recover from.
        //
        // Since Job #193 Redis is a required, durable store, so the ledger is
        // read for a baseline only in a recovery window, opened when Redis has
        // actually been lost (RedisDurability::recovering()). And an expired
        // peer's record is kept as its baseline for baseline_retention, so a
        // member back from a crash without having sent `stopped` is credited
        // what they moved in between.
        $this->app->singleton(RedisDurability::class);

        $this->app->extend(PeerService::class, function (PeerService $peers) {
            $peers->retainBaselinesFor((int) config('bloodhound.baseline_retention', 7 * 86_400));
            $peers->resolveBaselineUsing(fn (int $torrentId, string $peerId, ?int $userId = null) => $this->app->make(RedisDurability::class)->recovering()
                ? $this->recoverBaselineFromLedger($torrentId, $peerId, $userId)
                : null);

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
        $last = AnnounceLog::query()
            ->where('torrent_id', $torrentId)
            ->where('peer_id', PeerIdColumn::encode($peerId))
            ->where('user_id', $userId)
            ->where('event', '!=', AnnounceLog::EVENT_OPENING_BALANCE)
            ->where(fn ($query) => $query->where('anti_cheat_flagged', false)->orWhereNotNull('prior_up'))
            // No further back than a retained baseline lasts: a peer away
            // longer than that starts a new session, recovery or not.
            ->where('created_at', '>=', now()->subSeconds((int) config('bloodhound.baseline_retention', 7 * 86_400)))
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
        $this->refuseRemovedConfiguration();

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
     * Stop a stale published config from silently changing what the tracker does.
     *
     * announce_log.enabled was removed in 8.0.0: credit is written only to
     * the ledger, so a tracker without it credits nobody anything (F4). A
     * config that still turns it off is refused outright. One that says true
     * is the old default and harmless.
     *
     * max_connections_per_torrent and max_connections_per_ip were replaced
     * (by location limits, and by a per-member client cap on each torrent).
     * Ignoring them changes nothing dangerous, so they are reported once a
     * day, not refused.
     * The retired queue keys are ignored: nothing ever read them in 7.x either.
     */
    public function refuseRemovedConfiguration(): void
    {
        if (config('bloodhound.announce_log.enabled') === false) {
            throw new RemovedConfiguration(
                'bloodhound.announce_log.enabled was removed in 8.0.0: the ledger is always on, because every credit '
                .'is written to it and nowhere else. Delete the key (or BLOODHOUND_ANNOUNCE_LOG) from your config.'
            );
        }

        $replaced = [
            'max_connections_per_torrent' => 'max_seeding_locations_per_torrent and max_leeching_locations_per_torrent',
            'max_connections_per_ip' => 'max_clients_per_torrent (a member\'s clients on one torrent; how many torrents one address seeds is no longer limited)',
        ];
        $stale = array_intersect_key($replaced, (array) config('bloodhound.anti_cheat'));

        if ($stale !== [] && $this->firstToday('bloodhound:removed-config-reported')) {
            foreach ($stale as $key => $instead) {
                Log::warning("bloodhound.anti_cheat.{$key} was removed in 8.0.0 and is ignored. Use {$instead}.");
            }
        }
    }

    /**
     * Whether this is the first time today, by the cache. With the cache down,
     * yes: an extra log line beats a boot that fails on a cache outage.
     */
    private function firstToday(string $key): bool
    {
        try {
            return Cache::add($key, true, 86_400);
        } catch (Throwable) {
            return true;
        }
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
            BackfillLedger::class,
            CheckRedis::class,
            ManageSeedboxes::class,
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
