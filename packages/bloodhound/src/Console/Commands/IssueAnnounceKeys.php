<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Marque\Bloodhound\Models\AnnounceKey;
use Marque\Trove\Contracts\TrackerStatsInterface;
use Marque\Trove\Contracts\UserInterface;

/**
 * Issue an announce key to every user who should have one and doesn't.
 *
 * Keys are issued as users are created or verified, through Eloquent. A user
 * inserted any other way (a bulk import, a raw INSERT, a migration from
 * another tracker) never passes through either, so has no key and cannot
 * announce (#11070). Run this after such an import.
 *
 * "Should have one" is the same rule as at creation: verified, or the user
 * model has no verification at all. An unverified address still waits for
 * verification (usarrs #10879). An existing key is never touched, because
 * rotating it makes its owner re-download every .torrent they run.
 */
class IssueAnnounceKeys extends Command
{
    protected $signature = 'bloodhound:issue-keys
        {--dry-run : Report how many keys would be issued, change nothing}
        {--chunk=500 : Users to load per batch}';

    protected $description = 'Issue an announce key to every verified user without one';

    public function handle(TrackerStatsInterface $tracker): int
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');
        $user = new $userModel;
        $dryRun = (bool) $this->option('dry-run');
        $issued = 0;

        $query = $userModel::query()
            ->whereNotIn($user->getKeyName(), AnnounceKey::query()->select('user_id'));

        if ($user instanceof MustVerifyEmail) {
            $query->whereNotNull('email_verified_at');
        }

        $query->chunkById(max(1, (int) $this->option('chunk')), function ($users) use ($tracker, $dryRun, &$issued): void {
            foreach ($users as $user) {
                if (! $user instanceof UserInterface) {
                    continue;
                }

                if (! $dryRun) {
                    $tracker->regenerateAnnounceKey($user);
                }

                $issued++;
            }
        });

        $noun = $issued === 1 ? 'announce key' : 'announce keys';
        $this->info($dryRun ? "Would issue {$issued} {$noun}." : "Issued {$issued} {$noun}.");

        return self::SUCCESS;
    }
}
