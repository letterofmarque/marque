<?php

declare(strict_types=1);

namespace Marque\Trove\Contracts;

use Marque\Trove\Support\StaffAction;

/**
 * Where staff actions are recorded: a ban, a role change, a torrent edited or
 * deleted by someone who isn't its uploader.
 *
 * Declared here and implemented by whatever stores them (bosun, planned). Unlike
 * the tracker contracts, **don't ask the container whether this exists**: trove
 * binds a recorder that does nothing (NullStaffActions), so a package records
 * every staff action unconditionally and inject this like any other service.
 * Installing a store starts the record from that day; removing it stops it.
 * Nothing else changes either way.
 *
 * Record only what one person does to another's thing. Automatic enforcement
 * (a ratio watch starting, a hit-and-run strike) isn't a staff action.
 *
 * ## For an implementation: what a failure does
 *
 * Callers record from inside their own service, and often inside its database
 * transaction, so a rolled-back action is never recorded. That puts two
 * obligations on an implementation, and it must say which one it meets:
 *
 * - **Fail open** (the default, and what bosun will do): never throw from
 *   record(), and never leave an enclosing transaction unusable. On PostgreSQL
 *   one failed statement aborts the whole transaction, so catching your own
 *   exception is not enough. Run your writes in a savepoint (DB::transaction()
 *   inside the caller's transaction makes one), catch, and report. Then the
 *   staff action goes ahead and the gap shows in your record.
 * - **Fail closed**: throw, on purpose, and the caller's action fails with it.
 *   For an operator who must have no staff action without its record.
 *
 * trove catches nothing. Whether a failed record blocks the action is the
 * implementation's policy, and a catch here couldn't keep a PostgreSQL
 * transaction alive anyway.
 *
 * Public API from trove 5.0 (VERSIONING.md).
 */
interface StaffActionsInterface
{
    public function record(StaffAction $action): void;
}
