<?php

declare(strict_types=1);

namespace Marque\Bosun\Store;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marque\Bosun\Models\StaffActionEntry;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Support\StaffAction;
use Throwable;

/**
 * Stores what packages record through trove's StaffActionsInterface.
 *
 * **Fails open.** A failure to store a record never blocks the staff action
 * itself: the ban still happens, and the gap shows as an error in the log.
 * Callers record from inside their own transaction, and on PostgreSQL a single
 * failed statement aborts the whole transaction, so catching alone wouldn't do.
 * The write runs in its own transaction, which inside the caller's is a
 * savepoint: a failed insert rolls back to it and the caller's carries on.
 */
final class DatabaseStaffActions implements StaffActionsInterface
{
    public function record(StaffAction $action): void
    {
        // Integer user ids only: the Staff screen counts and joins by them. A
        // string or UUID id is refused here, the same on every engine, rather
        // than left to the database (PostgreSQL and MySQL reject it, SQLite
        // would store a row no staff filter can ever find).
        if (! is_int($action->actorId) && ! ctype_digit($action->actorId)) {
            Log::error('bosun: a staff action could not be recorded; bosun stores integer user ids only. The action itself went ahead.', [
                'action' => $action->action,
                'actor_id' => $action->actorId,
            ]);

            return;
        }

        try {
            DB::connection((new StaffActionEntry)->getConnectionName())->transaction(function () use ($action): void {
                StaffActionEntry::query()->create([
                    'actor_id' => $action->actorId,
                    'action' => $action->action,
                    'package' => $action->package(),
                    'subject_type' => $action->subjectType,
                    'subject_id' => (string) $action->subjectId,
                    'reason' => $action->reason,
                    'changes' => $action->changes === [] ? null : $action->changes,
                    'created_at' => $action->at,
                ]);
            });
        } catch (Throwable $e) {
            Log::error('bosun: a staff action could not be recorded; the action itself went ahead.', [
                'action' => $action->action,
                'actor_id' => $action->actorId,
                'subject' => $action->subjectType.'#'.$action->subjectId,
                'error' => $e->getMessage(),
            ]);

            report($e);
        }
    }
}
