<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Marque\Bloodhound\Events\CheatDetected;
use Marque\Bloodhound\Services\LocationSharingDetector;

/**
 * Report the account-sharing patterns the ledger shows (#11133).
 *
 * Scheduled daily over the last day. Each finding fires CheatDetected
 * (`location_sharing_suspected`) and is logged; nothing is refused and no
 * credit changes. A finding is evidence for staff, not a verdict: a VPN
 * switched on and off looks like an alternating peer.
 */
class DetectLocationSharing extends Command
{
    protected $signature = 'bloodhound:detect-location-sharing {--hours=24 : How far back to read the ledger}';

    protected $description = 'Report peers that look like one account used from more than one place';

    public function handle(LocationSharingDetector $detector): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $findings = $detector->detect(Carbon::now()->subHours($hours));

        if ($findings === []) {
            $this->info("No location sharing suspected in the last {$hours} hour(s).");

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $reason = match ($finding['kind']) {
                'alternating_peer' => "peer {$finding['peer_id']} went back to a network it had left {$finding['count']} time(s): ".implode(', ', $finding['places']),
                default => "peer {$finding['peer_id']} linked ".implode(' and ', $finding['places'])." as one place, switched between them only {$finding['count']} time(s) since, and the member has other peers at both",
            };

            $line = "user {$finding['user_id']} on torrent {$finding['torrent_id']}: {$reason}";
            $this->line("  {$line}");
            Log::warning("bloodhound location sharing suspected — {$line}");

            event(new CheatDetected('location_sharing_suspected', $finding['user_id'], $finding['torrent_id'], "{$finding['kind']}: {$reason}"));
        }

        $this->newLine();
        $this->warn(count($findings).' finding(s). These are for staff to look at, not verdicts.');

        return self::SUCCESS;
    }
}
