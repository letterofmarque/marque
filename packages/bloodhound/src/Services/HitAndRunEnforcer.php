<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Marque\Bloodhound\Events\HitAndRunCleared;
use Marque\Bloodhound\Events\HitAndRunPrewarned;
use Marque\Bloodhound\Events\HitAndRunStrike;
use Marque\Bloodhound\Models\HitAndRunStrike as Strike;
use Marque\Bloodhound\Models\MemberStanding;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Threepio\Services\PeerService;
use Marque\Trove\Enums\Role;

/**
 * The hit-and-run rule (Job #256).
 *
 * A torrent a member downloaded more than buffer_percent of is an obligation:
 * seed it min_seedtime in all, or reach hit_and_run.ratio on it (null: time
 * only), whichever is first. A member away from it while short (no live peer
 * of theirs on it, last seen prewarn_days ago) is pre-warned; still away at
 * grace_days, with the pre-warning at least prewarn_days old, struck. Seeding
 * it to the requirement clears the strike; a strike lapses after expire_days;
 * a torrent is struck once, ever. Defaults are UNIT3D's, with 72 hours of
 * seeding rather than its 7 days.
 *
 * It applies under ratio_mode 'seedtime', or 'full' with hit_and_run.enabled,
 * and only to activity after it began: switching it on never reaches back
 * over a member's history. Switched off and on, it begins again.
 *
 * run() decides, hourly. What a strike costs the member (max_strikes active
 * restricts them) is the ratio enforcer's: one restricted standing for both.
 */
class HitAndRunEnforcer
{
    /** The ledger_cursors stream that marks when the rule began. */
    public const STREAM = 'hit_and_run';

    public function __construct(private readonly PeerService $peers) {}

    public function applies(): bool
    {
        return match (config('bloodhound.ratio_mode', 'full')) {
            'seedtime' => true,
            'full' => (bool) config('bloodhound.hit_and_run.enabled', false),
            default => false,
        };
    }

    /**
     * @return array{prewarned: int, struck: int, cleared: int}
     */
    public function run(int $chunk = 1000): array
    {
        $counts = ['prewarned' => 0, 'struck' => 0, 'cleared' => 0];

        if (! $this->applies()) {
            DB::table('ledger_cursors')->where('stream', self::STREAM)->delete();

            return $counts;
        }

        $since = $this->since();
        $this->clearMet($counts);

        TorrentUser::query()
            ->join('torrents', 'torrents.id', '=', 'torrent_user.torrent_id')
            ->where('torrent_user.created_at', '>=', $since)
            ->where('torrent_user.seedtime', '<', $this->requiredSeconds())
            // More than the buffer of the torrent: whole numbers on both
            // sides, the same on every engine.
            ->whereRaw('torrent_user.downloaded * 100 > torrents.size * ?', [$this->bufferPercent()])
            ->select(['torrent_user.id', 'torrent_user.user_id', 'torrent_user.torrent_id', 'torrent_user.uploaded',
                'torrent_user.downloaded', 'torrent_user.seedtime', 'torrent_user.last_seen', 'torrent_user.updated_at'])
            // A closure, not an arrow fn: an arrow fn captures $counts by value.
            ->chunkById($chunk, function (Collection $rows) use (&$counts): void {
                $this->judge($rows, $counts);
            }, 'torrent_user.id', 'id');

        return $counts;
    }

    /**
     * Strikes that count against the member now.
     */
    public function activeStrikes(int $userId): int
    {
        return $this->applies() ? Strike::query()->where('user_id', $userId)->active()->count() : 0;
    }

    /**
     * Whether the rule passes this member by: staff at or above the exempt
     * role, or exempted by an admin. The same exemptions as the ratio rule.
     */
    public function isExempt(int $userId): bool
    {
        return isset($this->exemptAmong([$userId])[$userId]);
    }

    public function requiredSeconds(): int
    {
        return max(0, (int) config('bloodhound.min_seedtime', 72 * 3600));
    }

    /**
     * The ratio on a torrent that also meets it, or null for time only. An
     * empty, 'off' or non-positive value is time only.
     */
    public function requiredRatio(): ?float
    {
        $ratio = config('bloodhound.hit_and_run.ratio', 1.0);

        return is_numeric($ratio) && (float) $ratio > 0 ? (float) $ratio : null;
    }

    public function met(int $seedtime, int $uploaded, int $downloaded): bool
    {
        if ($seedtime >= $this->requiredSeconds()) {
            return true;
        }

        $ratio = $this->requiredRatio();

        return $ratio !== null && ($downloaded === 0 || $uploaded / $downloaded >= $ratio);
    }

    /**
     * When the rule began counting, set the first time it runs.
     */
    public function since(): Carbon
    {
        DB::table('ledger_cursors')->insertOrIgnore([
            'stream' => self::STREAM,
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Carbon::parse(DB::table('ledger_cursors')->where('stream', self::STREAM)->value('created_at'));
    }

    /**
     * @param  Collection<int, TorrentUser>  $rows
     * @param  array{prewarned: int, struck: int, cleared: int}  $counts
     */
    private function judge(Collection $rows, array &$counts): void
    {
        $rows = $rows->reject(fn (TorrentUser $row) => $this->met($row->seedtime, $row->uploaded, $row->downloaded));
        $userIds = $rows->pluck('user_id')->unique()->values();

        if ($userIds->isEmpty()) {
            return;
        }

        $exempt = $this->exemptAmong($userIds->all());
        $strikes = Strike::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('torrent_id', $rows->pluck('torrent_id')->unique())
            ->get()
            ->keyBy(fn (Strike $s) => $s->user_id.':'.$s->torrent_id);
        $present = [];

        foreach ($rows as $row) {
            if (isset($exempt[$row->user_id])) {
                continue;
            }

            // Here right now: never away, whatever the last fold says.
            $present[$row->user_id] ??= array_flip(array_column($this->peers->peersOfUser($row->user_id), 'torrent_id'));

            $strike = $strikes->get($row->user_id.':'.$row->torrent_id);
            $away = isset($present[$row->user_id][$row->torrent_id]) ? 0 : $this->secondsAway($row);

            $this->step($row, $strike, $away, $counts);
        }
    }

    /**
     * @param  array{prewarned: int, struck: int, cleared: int}  $counts
     */
    private function step(TorrentUser $row, ?Strike $strike, int $away, array &$counts): void
    {
        // Struck once (cleared, lapsed or not): never again for this torrent.
        if ($strike?->struck_at !== null) {
            return;
        }

        $prewarnAfter = $this->days('prewarn_days', 1);
        $graceAfter = $this->days('grace_days', 3);

        // Back before the grace ran out: the pre-warning is forgotten.
        if ($away < $prewarnAfter) {
            $strike?->delete();

            return;
        }

        if ($strike === null) {
            Strike::query()->create(['user_id' => $row->user_id, 'torrent_id' => $row->torrent_id, 'prewarned_at' => now()]);
            HitAndRunPrewarned::dispatch($row->user_id, $row->torrent_id, $row->seedtime, $this->requiredSeconds());
            $counts['prewarned']++;

            return;
        }

        // A strike needs the pre-warning to have had time to be read.
        if ($away >= $graceAfter && $strike->prewarned_at !== null && $strike->prewarned_at->lte(now()->subSeconds($prewarnAfter))) {
            $expires = now()->addSeconds($this->days('expire_days', 14));
            $strike->fill(['struck_at' => now(), 'expires_at' => $expires])->save();

            HitAndRunStrike::dispatch($row->user_id, $row->torrent_id, $expires, $this->activeStrikes($row->user_id));
            $counts['struck']++;
        }
    }

    /**
     * A strike or pre-warning whose obligation is now met: cleared.
     *
     * @param  array{prewarned: int, struck: int, cleared: int}  $counts
     */
    private function clearMet(array &$counts): void
    {
        Strike::query()
            ->whereNull('cleared_at')
            ->orderBy('id')
            ->each(function (Strike $strike) use (&$counts): void {
                $row = TorrentUser::query()->where('user_id', $strike->user_id)->where('torrent_id', $strike->torrent_id)->first();

                if ($row !== null && ! $this->met($row->seedtime, $row->uploaded, $row->downloaded)) {
                    return;
                }

                if ($strike->struck_at === null) {
                    $strike->delete();

                    return;
                }

                $strike->fill(['cleared_at' => now()])->save();
                HitAndRunCleared::dispatch($strike->user_id, $strike->torrent_id);
                $counts['cleared']++;
            });
    }

    private function secondsAway(TorrentUser $row): int
    {
        $seen = $row->last_seen ?? $row->updated_at?->getTimestamp() ?? now()->getTimestamp();

        return max(0, now()->getTimestamp() - (int) $seen);
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, true>
     */
    private function exemptAmong(array $userIds): array
    {
        $exempt = array_fill_keys(
            MemberStanding::query()->whereIn('user_id', $userIds)->whereNotNull('exempted_at')->pluck('user_id')->all(),
            true,
        );

        $class = config('trove.user_model', 'App\\Models\\User');
        /** @var Model $model */
        $model = new $class;
        $floor = Role::tryFrom((string) config('bloodhound.enforcement.exempt_role', 'moderator'));

        foreach ($model->newQuery()->whereIn($model->getKeyName(), $userIds)->get([$model->getKeyName(), 'role']) as $user) {
            $role = $user->getAttribute('role');
            $role = $role instanceof Role ? $role : Role::tryFrom((string) $role);

            if ($role !== null && $floor !== null && $role->isAtLeast($floor)) {
                $exempt[(int) $user->getKey()] = true;
            }
        }

        return $exempt;
    }

    private function bufferPercent(): int
    {
        return max(0, (int) config('bloodhound.hit_and_run.buffer_percent', 10));
    }

    private function days(string $key, int $default): int
    {
        return max(0, (int) config("bloodhound.hit_and_run.{$key}", $default)) * 86_400;
    }
}
