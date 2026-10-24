<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Marque\Bloodhound\Events\MemberRestricted;
use Marque\Bloodhound\Events\RatioWatchStarted;
use Marque\Bloodhound\Events\StandingRestored;
use Marque\Bloodhound\Models\HitAndRunStrike;
use Marque\Bloodhound\Models\MemberStanding;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Threepio\Services\PeerService;
use Marque\Trove\Enums\Role;
use Marque\Trove\Enums\StandingState;
use Marque\Trove\Support\TrackerStanding;

/**
 * A member's standing against the tracker's rules, decided and read (#10732).
 *
 * Two rules can restrict a member, and they share one standing:
 *
 * - the ratio rule (Job #255): below min_ratio, past the grace allowance,
 *   goes on watch with a deadline; still below at the deadline, restricted;
 * - hit-and-run (Job #256): max_strikes active strikes, restricted at once
 *   (the strikes themselves were the warnings; see HitAndRunEnforcer).
 *
 * Restricted means new downloading is paused; seeding never is, since it is
 * how a member recovers. A member restricted by both stays restricted until
 * both clear. When a hit-and-run restriction lifts mid-watch, the watch
 * resumes with its own deadline. Exempt members (role, or an admin's
 * exemption) are never watched or restricted.
 *
 * run() decides, hourly, from the folded totals and the strikes. Everything
 * else (the announce path, the download gate, statsFor) only reads what it
 * decided, so judging a member costs a request nothing.
 *
 * The ratio rule applies under ratio_mode 'full'; hit-and-run under
 * 'seedtime', or 'full' with hit_and_run.enabled; neither under 'off'. Every
 * mode records the same figures.
 */
class RatioEnforcer
{
    /** grace_bytes as 9.0 and 9.1 shipped it, and so every config published then: 5 GiB. */
    public const OLD_DEFAULT_GRACE_BYTES = 5 * 1024 ** 3;

    public function __construct(
        private readonly PeerService $peers,
        private readonly HitAndRunEnforcer $hitAndRun,
    ) {}

    /**
     * Whether the ratio rule applies.
     */
    public function enforcing(): bool
    {
        return config('bloodhound.ratio_mode', 'full') === 'full';
    }

    /**
     * Whether any rule can restrict a member.
     */
    public function restricting(): bool
    {
        return $this->enforcing() || $this->hitAndRun->applies();
    }

    /**
     * Judge every member a rule could apply to, and clear everyone none does.
     *
     * @return array{watched: int, restricted: int, restored: int}
     */
    public function run(int $chunk = 1000): array
    {
        $counts = ['watched' => 0, 'restricted' => 0, 'restored' => 0];
        $userModel = $this->userModel();
        $key = $userModel->getKeyName();
        $ids = [];

        if ($this->enforcing()) {
            $grammar = $userModel->newQuery()->getQuery()->getGrammar();

            // Only members below the ratio can be put on watch, so only they
            // are found, not every member hydrated every hour. A prefilter:
            // settle() decides in PHP. The ratio is cast, because PDO binds it
            // as a string and PostgreSQL then reads it as the bigint it is
            // multiplied with ('0.5' is not one).
            foreach ($userModel->newQuery()
                ->where('downloaded', '>=', $this->graceBytes())
                ->where('downloaded', '>', 0)
                ->whereRaw($grammar->wrap('uploaded').' < '.$grammar->wrap('downloaded').' * CAST(? AS DECIMAL(12,6))', [$this->required()])
                ->pluck($key) as $id) {
                $ids[(int) $id] = true;
            }
        }

        if ($this->hitAndRun->applies()) {
            foreach (HitAndRunStrike::query()->counting($this->hitAndRun->since())
                ->groupBy('user_id')
                ->havingRaw('COUNT(*) >= ?', [$this->maxStrikes()])
                ->pluck('user_id') as $id) {
                $ids[(int) $id] = true;
            }
        }

        // And everyone the last run left on watch or restricted, to restore.
        foreach (MemberStanding::query()
            ->whereIn('state', [StandingState::Watch->value, StandingState::Restricted->value])
            ->pluck('user_id') as $id) {
            $ids[(int) $id] = true;
        }

        foreach (array_chunk(array_keys($ids), max(1, $chunk)) as $batch) {
            $users = $userModel->newQuery()->whereIn($key, $batch)->get([$key, 'uploaded', 'downloaded', 'role'])->keyBy(fn ($u) => (int) $u->getKey());
            $standings = MemberStanding::query()->whereIn('user_id', $batch)->get()->keyBy('user_id');
            $strikes = $this->hitAndRun->applies()
                ? HitAndRunStrike::query()->counting($this->hitAndRun->since())->whereIn('user_id', $batch)->groupBy('user_id')
                    ->selectRaw('user_id, COUNT(*) as strikes')->pluck('strikes', 'user_id')
                : collect();

            foreach ($batch as $id) {
                $this->settle($id, $users->get($id), $standings->get($id), (int) ($strikes[$id] ?? 0), $counts);
            }
        }

        return $counts;
    }

    /**
     * Where this member stands, as last decided. Reads, never decides.
     */
    public function standingFor(Model|int $user): TrackerStanding
    {
        if (! $this->restricting()) {
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

        return match ($standing?->state) {
            StandingState::Watch->value => TrackerStanding::watch($standing->deadline_at ?? now(), $this->watchReason($this->ratioOf($user), $standing->deadline_at)),
            StandingState::Restricted->value => TrackerStanding::restricted($this->restrictionReason($user)),
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
        if (! $this->restricting()) {
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

        if ($user === null) {
            return $this->ratioRestrictionReason(0.0);
        }

        // Honoured only while a rule that applies now still explains it: a
        // mode switched off, or a ratio already back up, lifts it at once
        // rather than at the next run.
        $ratioBelow = $this->enforcing() && $this->ratioOf($user) < $this->required();
        $struckOut = $this->hitAndRun->applies() && $this->hitAndRun->activeStrikes((int) $userId) >= $this->maxStrikes();

        return $ratioBelow || $struckOut ? $this->restrictionReason($user) : null;
    }

    /**
     * Take a member out of the rules, with the admin and reason on record.
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
     * Put a member back under the rules. They are judged at the next run.
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
     * Decide one member's standing from both rules, and record any change.
     *
     * @param  array{watched: int, restricted: int, restored: int}  $counts
     */
    private function settle(int $id, ?Model $user, ?MemberStanding $standing, int $strikes, array &$counts): void
    {
        $previous = StandingState::tryFrom((string) $standing?->state) ?? StandingState::Ok;
        $exempt = $user === null || $this->exemptByRole($user) || $standing?->isExempted();
        $ratio = $user === null ? INF : $this->ratioOf($user);

        $ratioBelow = ! $exempt && $this->enforcing()
            && (int) $user->getAttribute('downloaded') >= $this->graceBytes()
            && $ratio < $this->required();
        $struckOut = ! $exempt && $this->hitAndRun->applies() && $strikes >= $this->maxStrikes();

        // The watch keeps its deadline while the ratio stays below, whatever
        // else happens; a fresh one starts when it first falls below.
        $deadline = $ratioBelow ? $standing?->deadline_at : null;
        $watchStarts = $ratioBelow && $deadline === null;
        $deadline ??= $ratioBelow ? now()->addDays($this->watchDays()) : null;

        $state = match (true) {
            $struckOut, $ratioBelow && now()->gte($deadline) => StandingState::Restricted,
            $ratioBelow => StandingState::Watch,
            default => StandingState::Ok,
        };

        if ($state === StandingState::Ok && ! $standing?->isExempted()) {
            $standing?->delete();
        } elseif ($standing !== null || $state !== StandingState::Ok) {
            ($standing ?? new MemberStanding(['user_id' => $id]))->fill([
                'state' => $state->value,
                'watch_started_at' => $ratioBelow ? ($watchStarts ? now() : $standing?->watch_started_at) : null,
                'deadline_at' => $deadline,
                'restricted_at' => $state === StandingState::Restricted ? ($previous === StandingState::Restricted ? $standing?->restricted_at : now()) : null,
            ])->save();
        }

        if ($watchStarts) {
            RatioWatchStarted::dispatch($id, $ratio, $this->required(), $deadline);
            $counts['watched']++;
        }

        if ($state === StandingState::Restricted && $previous !== StandingState::Restricted) {
            MemberRestricted::dispatch($id, is_finite($ratio) ? $ratio : 0.0, $this->required(), $struckOut ? $strikes : 0);
            $counts['restricted']++;
        }

        // Out of restriction (downloads resume, though a ratio watch may go
        // on), or off a watch with nothing left against them.
        if (($previous === StandingState::Restricted && $state !== StandingState::Restricted)
            || ($previous === StandingState::Watch && $state === StandingState::Ok)) {
            StandingRestored::dispatch($id, $previous);
            $counts['restored']++;
        }
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

    /**
     * Why a restricted member is restricted, naming whichever rules apply now.
     */
    private function restrictionReason(Model $user): string
    {
        $ratio = $this->ratioOf($user);
        $strikes = $this->hitAndRun->activeStrikes((int) $user->getKey());
        $ratioBelow = $this->enforcing() && $ratio < $this->required();
        $struckOut = $this->hitAndRun->applies() && $strikes >= $this->maxStrikes();

        if ($struckOut && ! $ratioBelow) {
            return sprintf(
                'New downloads are paused: you have %d hit-and-run strikes, and this tracker allows %d. Seed the struck torrents to the requirement to clear them; downloads resume within the hour once you are under the limit.',
                $strikes,
                $this->maxStrikes() - 1,
            );
        }

        if ($struckOut) {
            return sprintf(
                'New downloads are paused: your ratio is %.2f and this tracker asks for at least %.2f, and you have %d hit-and-run strikes. Keep seeding; downloads resume within the hour once both are back in line.',
                $ratio,
                $this->required(),
                $strikes,
            );
        }

        return $this->ratioRestrictionReason($ratio);
    }

    private function ratioRestrictionReason(float $ratio): string
    {
        return sprintf(
            'New downloads are paused: your ratio is %.2f and this tracker asks for at least %.2f. Keep seeding; downloads resume within the hour once it is back up.',
            $ratio,
            $this->required(),
        );
    }

    private function maxStrikes(): int
    {
        return max(1, (int) config('bloodhound.hit_and_run.max_strikes', 3));
    }

    /**
     * The grace allowance in bytes. Set in GB (grace_gb), as the tracker
     * displays sizes: 1024-based. A grace_gb that isn't a number falls back to
     * the default.
     *
     * grace_bytes, the pre-9.2 setting, still wins when an operator chose it,
     * so their number doesn't move; it's reported as deprecated. Its 9.0/9.1
     * default, which every config published then carries, is not a choice and
     * is ignored: it equals grace_gb's default, so nothing moves either way,
     * and BLOODHOUND_RATIO_GRACE_GB works without editing the file.
     */
    private function graceBytes(): int
    {
        $bytes = config('bloodhound.enforcement.grace_bytes');

        if (is_numeric($bytes) && (int) $bytes !== self::OLD_DEFAULT_GRACE_BYTES) {
            return max(0, (int) $bytes);
        }

        $gb = config('bloodhound.enforcement.grace_gb', 5);

        return max(0, (int) round((is_numeric($gb) ? (float) $gb : 5.0) * 1024 ** 3));
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
