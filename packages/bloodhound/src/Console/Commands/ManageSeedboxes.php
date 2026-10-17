<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Marque\Trove\Contracts\SeedboxesInterface;
use Marque\Trove\Support\Seedbox;

/**
 * Members' seedboxes from the console: for an install with no admin screen,
 * or an operator who would rather.
 *
 * Approving and revoking record who did it, so --by names the admin.
 */
class ManageSeedboxes extends Command
{
    protected $signature = 'bloodhound:seedbox
        {action : list, approve or revoke}
        {id? : The seedbox, for approve and revoke}
        {--status= : For list: pending, approved or revoked}
        {--by= : For approve and revoke: the user id of the admin deciding}';

    protected $description = 'List, approve or revoke members\' seedboxes';

    public function handle(SeedboxesInterface $seedboxes): int
    {
        return match ($this->argument('action')) {
            'list' => $this->list($seedboxes),
            'approve', 'revoke' => $this->decide($seedboxes, (string) $this->argument('action')),
            default => $this->usage(),
        };
    }

    private function list(SeedboxesInterface $seedboxes): int
    {
        $rows = array_map(fn (Seedbox $seedbox) => [
            $seedbox->id,
            $seedbox->userId,
            $seedbox->network,
            $seedbox->label ?? '',
            $seedbox->status,
            $seedbox->registeredAt->format('Y-m-d H:i'),
        ], $seedboxes->all($this->option('status') ?: null));

        $rows === []
            ? $this->info('No seedboxes.')
            : $this->table(['ID', 'User', 'Network', 'Label', 'Status', 'Registered'], $rows);

        return self::SUCCESS;
    }

    private function decide(SeedboxesInterface $seedboxes, string $action): int
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');
        $admin = $this->option('by') !== null ? $userModel::query()->find($this->option('by')) : null;

        if ($this->argument('id') === null || $admin === null) {
            $this->error("{$action} needs a seedbox id and --by=<the admin's user id>.");

            return self::FAILURE;
        }

        $seedbox = $seedboxes->{$action}((int) $this->argument('id'), $admin);
        $this->info(ucfirst($action === 'approve' ? 'approved' : 'revoked')." {$seedbox->network} for user {$seedbox->userId}.");

        return self::SUCCESS;
    }

    private function usage(): int
    {
        $this->error('Action must be list, approve or revoke.');

        return self::FAILURE;
    }
}
