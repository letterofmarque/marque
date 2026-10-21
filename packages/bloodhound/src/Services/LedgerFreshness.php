<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marque\Bloodhound\Models\AnnounceLog;
use Throwable;

/**
 * Is the ledger being folded into totals?
 *
 * Every credit is a ledger row, and totals move only when
 * bloodhound:aggregate-ledger folds them, every minute on the scheduler. A
 * tracker with no schedule:run credits nobody anything and says nothing
 * (#11070: twentyt's production box). A check on the schedule cannot catch a
 * schedule that never runs, so the announce path asks instead, at most once in
 * five minutes: is the oldest row the aggregator hasn't folded older than ten?
 */
class LedgerFreshness
{
    /** Seconds an unfolded row may wait. Folds run every minute, a row trails by one or two. */
    private const STALE_AFTER = 600;

    /** Seconds between checks from the announce path. */
    private const CHECK_EVERY = 300;

    /**
     * When the oldest unfolded row was written, if it has waited too long.
     */
    public function stalledSince(): ?Carbon
    {
        $position = (int) DB::table('ledger_cursors')
            ->where('stream', LedgerAggregator::STREAM)
            ->value('position');

        $oldest = AnnounceLog::query()
            ->where('id', '>', $position)
            ->orderBy('id')
            ->value('created_at');

        if ($oldest === null) {
            return null;
        }

        $oldest = Carbon::parse($oldest);

        return $oldest->lte(now()->subSeconds(self::STALE_AFTER)) ? $oldest : null;
    }

    /**
     * Called on every announce; does any work once in five minutes, and never
     * throws: an announce must not fail because this check did.
     */
    public function reportFromAnnounce(): void
    {
        try {
            if (! Cache::add('bloodhound:ledger-freshness-checked', true, self::CHECK_EVERY)) {
                return;
            }

            $since = $this->stalledSince();

            if ($since !== null) {
                Log::error("bloodhound: announces since {$since->toDateTimeString()} have not been folded into members' totals, "
                    .'so nobody has been credited for them. bloodhound:aggregate-ledger runs on the scheduler: '
                    .'check that `php artisan schedule:run` runs every minute (cron or schedule:work).');
            }
        } catch (Throwable) {
            // Nothing to do: the next window asks again.
        }
    }
}
