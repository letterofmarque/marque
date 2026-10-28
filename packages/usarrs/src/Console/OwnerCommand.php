<?php

declare(strict_types=1);

namespace Marque\Usarrs\Console;

use Illuminate\Console\Command;
use Marque\Trove\Enums\Role;
use Marque\Usarrs\Services\UserAdminService;

/**
 * Make a user an owner, from the console.
 *
 * Only an owner grants Admin or Owner, so a tracker without one can never make
 * another admin: a fresh install, or one upgraded from before trove 5 had an
 * Owner role. This sets the first. Whoever can run it already controls the
 * server, so no actor rules apply. More owners are made from the user screen.
 */
class OwnerCommand extends Command
{
    protected $signature = 'usarrs:owner {email : The address of the user to make an owner}';

    protected $description = 'Make a user an owner of the tracker';

    public function handle(UserAdminService $service): int
    {
        $email = (string) $this->argument('email');
        $model = config('trove.user_model', 'App\\Models\\User');
        $user = $model::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user has the address {$email}.");

            return self::FAILURE;
        }

        if ($user->role === Role::Owner) {
            $this->info("{$email} is already an owner.");

            return self::SUCCESS;
        }

        $service->setRole($user, Role::Owner);

        // Read back rather than trusted: a role that didn't stick, reported as
        // set, is worse than an error.
        if ($user->fresh()?->role !== Role::Owner) {
            $this->error("{$email} could not be made an owner: the role didn't save.");

            return self::FAILURE;
        }

        $this->info("{$email} is now an owner. Owners grant and remove Admin from the user screen.");

        return self::SUCCESS;
    }
}
