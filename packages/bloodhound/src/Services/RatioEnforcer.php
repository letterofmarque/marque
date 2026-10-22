<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Marque\Bloodhound\Events\MemberRestricted;
use Marque\Bloodhound\Events\RatioWatchStarted;
use Marque\Bloodhound\Events\StandingRestored;
use Marque\Bloodhound\Models\MemberStanding;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Threepio\Services\PeerService;
use Marque\Trove\Enums\Role;
use Marque\Trove\Enums\StandingState;
use Marque\Trove\Support\TrackerStanding;

/**
 * The ratio rule, applied (#10732, Job #255).
 *
 * A member below min_ratio who has downloaded at least the grace allowance,
 * and isn't exempt, goes on watch with a deadline. Still below at the
 * deadline, new downloading is paused (restricted). Back at or above, or
 * exempt, or with enforcement off, it all clears. Seeding is never paused:
 * it is how a member recovers.
 *
 * run() decides, on the scheduler, from the folded totals. Everything else
 * (the announce path, the download gate, statsFor) only reads what it decided,
 * so judging a member costs a request nothing.
 *
 * Only ratio_mode 'full' enforces. 'seedtime' will enforce per-torrent
 * hit-and-run rules, in their own Job; until then it, like 'off', enforces
 * nothing. Every mode records the same figures.
 */
class RatioEnforcer
{
    public function __construct(private readonly PeerService $peers) {}

    public function enforcing(): bool
    {
        return config('bloodhound.ratio_mode', 'full') === 'full';
    }

    /**
     * Judge every member the rule could apply to, and clear everyone it no
     * longer does.
     *
     * @return array{watched: int, restricted: int, restored: int}
     */
    public function run(int $chunk = 1000): array
    {
        $counts = ['watched' => 0, 'restricted' => 0, 'restored' => 0];
        $judged = [];

        if ($this->enforcing()) {
            $userModel = $this->userModel();
            $key = $userModel->getKeyName();
            $grammar = $userModel->newQuery()->getQuery()->getGrammar();

            // Only members below the rule can be put on watch or restricted,
            // so only they are loaded, not every member hydrated every hour.
            // A prefilter: judgeBelow() checks again in PHP. The ratio is cast,
            // because PDO binds it as a string and PostgreSQL then reads it as
            // the bigint it is multiplied with ('0.5' is not one).
            $userModel->newQuery()
                ->select([$key, 'uploaded', 'downloaded', 'role'])
                ->where('downloaded', '>=', $this->graceBytes())
                ->where('downloaded', '>', 0)
                ->whereRaw($grammar->wrap('uploaded').' < '.$grammar->wrap('downloaded').' * CAST(? AS DECIMAL(12,6))', [$this->required()])
                ->chunkById($chunk, function ($users) use (&$counts, &$judged, $key): void {
                    $standings = MemberStanding::query()
                        ->whereIn('user_id', $users->pluck($key))
                        ->get()
                        ->keyBy('user_id');

                    foreach ($users as $user) {
                        $id = (int) $user->getKey();
                        if ($this->judgeBelow($id, $user, $standings->get($id), $counts)) {
                            $judged[$id] = true;
                        }
                    }
                }, $key);
        }

        // Everyone on watch or restricted who wasn't below the rule just now
        // has recovered, fallen under the grace allowance, or enforcement is
        // off: they are all restored.
        MemberStanding::query()
            ->whereIn('state', [StandingState::Watch->value, StandingState::Restricted->value])
            ->orderBy('id')
            ->each(function (MemberStanding $standing) use (&$counts, $judged): void {
                if (! isset($judged[$standing->user_id]) && $this->restore($standing)) {
                    $counts['restored']++;
                }
            });

        return $counts;
    }

    /**
     * Where this member stands, as last decided. Reads, never decides.
     */
    public function standingFor(Model|int $user): TrackerStanding
    {
        if (! $this->enforcing()) {
            return TrackerStanding::ok();
        }

        $user = $user instanceof Model ? $user : $this->userModel()->newQuery()->find($user);

        if ($user === null) {
            return TrackerStanding::ok();
        }

        $standing = MemberStanding::query()->where('user_id', $user->getKey())->first();

        if ($this->exemptByRole($user) || $standing?->isExempted()) {
            return TrackerStanding::exempt();
        }

        $ratio = $this->ratioOf($user);

        return match ($standing?->state) {
            StandingState::Watch->value => TrackerStanding::watch($standing->deadline_at ?? now(), $this->watchReason($ratio, $standing->deadline_at)),
            StandingState::Restricted->value => TrackerStanding::restricted($this->restrictionReason($ratio)),
            default => TrackerStanding::ok(),
        };
    }

    /**
     * Why this member may not start leeching this torrent, or null if they
     * may. Asked by the announce path (for a leech, left > 0) and by the
     * download gate. One indexed lookup for a member who isn't restricted,
     * which is nearly everyone.
     *
     * Only new leeching is refused. A torrent the member is already in is
     * theirs to finish, and its .torrent theirs to download again: a
     * torrent_user row the ledger has folded, or, before the fold, a live
     * peer of theirs on it, from any of their clients.
     */
    public function leechRefusal(int|string $userId, int $torrentId): ?string
    {
        if (! $this->enforcing()) {
            return null;
        }

        $restricted = MemberStanding::query()
            ->where('user_id', $userId)
            ->where('state', StandingState::Restricted->value)
            ->whereNull('exempted_at')
            ->exists();

        if (! $restricted || $this->alreadyIn((int) $userId, $torrentId)) {
            return null;
        }

        $user = $this->userModel()->newQuery()->find($userId);

        return $this->restrictionReason($user === null ? 0.0 : $this->ratioOf($user));
    }

    /**
     * Asked only for a restricted member, so only they cost the Redis reads.
     */
    private function alreadyIn(int $userId, int $torrentId): bool
    {
        if (TorrentUser::query()->where('user_id', $userId)->where('torrent_id', $torrentId)->exists()) {
            return true;
        }

        // Live and theirs: peersOfUser() checks both against the swarm hash.
        foreach ($this->peers->peersOfUser($userId) as $peer) {
            if ($peer['torrent_id'] === $torrentId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Take a member out of the rule, with the admin and reason on record.
     * Lifts a watch or restriction at once rather than at the next run.
     */
    public function exempt(int $userId, ?int $by, string $reason): void
    {
        $standing = MemberStanding::query()->firstOrNew(['user_id' => $userId]);
        $previous = StandingState::tryFrom((string) $standing->state);

        $standing->fill([
            'state' => StandingState::Ok->value,
            'watch_started_at' => null,
            'deadline_at' => null,
            'restricted_at' => null,
            'exempted_at' => now(),
            'exempted_by' => $by,
            'exempt_reason' => $reason,
        ])->save();

        if (in_array($previous, [StandingState::Watch, StandingState::Restricted], true)) {
            StandingRestored::dispatch($userId, $previous);
        }
    }

    /**
     * Put a member back under the rule. They are judged at the next run.
     */
    public function unexempt(int $userId): void
    {
        $standing = MemberStanding::query()->where('user_id', $userId)->first();

        if ($standing === null) {
            return;
        }

        $standing->fill(['exempted_at' => null, 'exempted_by' => null, 'exempt_reason' => null]);

        $standing->state === StandingState::Ok->value ? $standing->delete() : $standing->save();
    }

    public function required(): float
    {
        return (float) config('bloodhound.min_ratio', 0.5);
    }

    /**
     * A member the query found below the rule: start a watch, or restrict
     * once it runs out, unless they are exempt. False when PHP finds them not
     * below after all, leaving them to the restore pass.
     *
     * @param  array{watched: int, restricted: int, restored: int}  $counts
     */
    private function judgeBelow(int $id, Model $user, ?MemberStanding $standing, array &$counts): bool
    {
        if ($this->exemptByRole($user) || $standing?->isExempted()) {
            if ($standing !== null && $this->restore($standing)) {
                $counts['restored']++;
            }

            return true;
        }

        $state = $standing?->state ?? StandingState::Ok->value;
        $ratio = $this->ratioOf($user);

        // The query only narrowed it down; the rule is decided here. Not
        // below after all: left to the restore pass, like anyone else above.
        if ($ratio >= $this->required()) {
            return false;
        }

        if ($state === StandingState::Ok->value) {
            $deadline = now()->addDays($this->watchDays());

            MemberStanding::query()->updateOrCreate(['user_id' => $id], [
                'state' => StandingState::Watch->value,
                'watch_started_at' => now(),
                'deadline_at' => $deadline,
                'restricted_at' => null,
            ]);

            RatioWatchStarted::dispatch($id, $ratio, $this->required(), $deadline);
            $counts['watched']++;

            return true;
        }

        if ($state === StandingState::Watch->value && $standing?->deadline_at !== null && now()->gte($standing->deadline_at)) {
            $standing->fill(['state' => StandingState::Restricted->value, 'restricted_at' => now()])->save();

            MemberRestricted::dispatch($id, $ratio, $this->required());
            $counts['restricted']++;
        }

        return true;
    }

    /**
     * Back to ok. The row goes, unless it holds an admin's exemption.
     */
    private function restore(MemberStanding $standing): bool
    {
        $previous = StandingState::tryFrom((string) $standing->state);

        if (! in_array($previous, [StandingState::Watch, StandingState::Restricted], true)) {
            return false;
        }

        if ($standing->isExempted()) {
            $standing->fill([
                'state' => StandingState::Ok->value,
                'watch_started_at' => null,
                'deadline_at' => null,
                'restricted_at' => null,
            ])->save();
        } else {
            $standing->delete();
        }

        StandingRestored::dispatch($standing->user_id, $previous);

        return true;
    }

    private function exemptByRole(Model $user): bool
    {
        // trove's HasRoles casts it to the enum; a model without the cast
        // holds the string.
        $role = $user->getAttribute('role');
        $role = $role instanceof Role ? $role : Role::tryFrom((string) $role);
        $floor = Role::tryFrom((string) config('bloodhound.enforcement.exempt_role', 'moderator'));

        return $role !== null && $floor !== null && $role->isAtLeast($floor);
    }

    private function ratioOf(Model $user): float
    {
        $downloaded = (int) $user->getAttribute('downloaded');

        return $downloaded === 0 ? INF : (int) $user->getAttribute('uploaded') / $downloaded;
    }

    private function watchReason(float $ratio, ?Carbon $deadline): string
    {
        return sprintf(
            'Your ratio is %.2f and this tracker asks for at least %.2f. Seed to raise it by %s, or new downloads will be paused until you do.',
            $ratio,
            $this->required(),
            ($deadline ?? now())->toFormattedDayDateString(),
        );
    }

    private function restrictionReason(float $ratio): string
    {
        return sprintf(
            'New downloads are paused: your ratio is %.2f and this tracker asks for at least %.2f. Keep seeding; downloads resume within the hour once it is back up.',
            $ratio,
            $this->required(),
        );
    }

    private function graceBytes(): int
    {
        return max(0, (int) config('bloodhound.enforcement.grace_bytes', 5 * 1024 ** 3));
    }

    private function watchDays(): int
    {
        return max(0, (int) config('bloodhound.enforcement.watch_days', 14));
    }

    private function userModel(): Model
    {
        $class = config('trove.user_model', 'App\\Models\\User');

        return new $class;
    }
}
