<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Updates user upload/download stats.
 *
 * This is queued to batch database updates and reduce load
 * during high-traffic announce periods.
 */
class UpdateUserStats implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly int $uploadDelta,
        public readonly int $downloadDelta,
    ) {}

    public function handle(): void
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');

        // Use raw DB update for atomicity
        DB::table((new $userModel)->getTable())
            ->where('id', $this->userId)
            ->update([
                'uploaded' => DB::raw("uploaded + {$this->uploadDelta}"),
                'downloaded' => DB::raw("downloaded + {$this->downloadDelta}"),
            ]);
    }
}
