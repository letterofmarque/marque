<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Marque\Bloodhound\Models\HitAndRunStrike;
use Marque\Bloodhound\Models\TorrentUser;
use Marque\Trove\Contracts\SeedingObligationsInterface;
use Marque\Trove\Contracts\UserInterface;
use Marque\Trove\Enums\ObligationState;
use Marque\Trove\Support\SeedingObligation;

/**
 * trove's SeedingObligationsInterface, answered by the hit-and-run rule
 * (Job #256): what a member still owes seeding on, and their strikes, for a
 * frontend to show.
 *
 * The same obligations HitAndRunEnforcer judges: activity since the rule
 * began, more than the buffer downloaded, short of both the seeding time and
 * the ratio. A torrent whose strike lapsed or was cleared is left out: it is
 * never struck again, so there is nothing left to owe.
 */
class SeedingObligations implements SeedingObligationsInterface
{
    private const ORDER = [
        ObligationState::Struck->value => 0,
        ObligationState::Prewarned->value => 1,
        ObligationState::Open->value => 2,
    ];

    public function __construct(private readonly HitAndRunEnforcer $rule) {}

    public function obligationsFor(UserInterface $user): array
    {
        $userId = (int) $user->getAuthIdentifier();

        if (! $this->rule->applies() || $this->rule->isExempt($userId)) {
            return [];
        }

        $rows = TorrentUser::query()
            ->join('torrents', 'torrents.id', '=', 'torrent_user.torrent_id')
            ->where('torrent_user.user_id', $userId)
            ->where('torrent_user.created_at', '>=', $this->rule->since())
            ->where('torrent_user.seedtime', '<', $this->rule->requiredSeconds())
            ->whereRaw('torrent_user.downloaded * 100 > torrents.size * ?', [max(0, (int) config('bloodhound.hit_and_run.buffer_percent', 10))])
            ->get(['torrent_user.torrent_id', 'torrent_user.uploaded', 'torrent_user.downloaded', 'torrent_user.seedtime', 'torrents.name']);

        $strikes = HitAndRunStrike::query()->where('user_id', $userId)->get()->keyBy('torrent_id');
        $obligations = [];

        foreach ($rows as $row) {
            if ($this->rule->met((int) $row->seedtime, (int) $row->uploaded, (int) $row->downloaded)) {
                continue;
            }

            $strike = $strikes->get($row->torrent_id);

            // Struck once and no longer active: lapsed or cleared, never again.
            if ($strike?->struck_at !== null && ! $strike->isActive()) {
                continue;
            }

            $obligations[] = new SeedingObligation(
                torrentId: (int) $row->torrent_id,
                torrentName: (string) $row->name,
                seededSeconds: (int) $row->seedtime,
                requiredSeconds: $this->rule->requiredSeconds(),
                ratio: (int) $row->downloaded === 0 ? null : (int) $row->uploaded / (int) $row->downloaded,
                requiredRatio: $this->rule->requiredRatio(),
                state: match (true) {
                    $strike?->struck_at !== null => ObligationState::Struck,
                    $strike?->prewarned_at !== null => ObligationState::Prewarned,
                    default => ObligationState::Open,
                },
                until: $strike?->struck_at !== null ? $strike->expires_at : null,
            );
        }

        usort($obligations, fn (SeedingObligation $a, SeedingObligation $b) => [self::ORDER[$a->state->value], -$a->remainingSeconds()]
            <=> [self::ORDER[$b->state->value], -$b->remainingSeconds()]);

        return $obligations;
    }
}
