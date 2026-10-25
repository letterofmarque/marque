<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Marque\Bloodhound\Models\MemberStanding;
use Marque\Bloodhound\Services\RatioEnforcer;

/**
 * A member's standing against the ratio rule: show it, or exempt them from it
 * (with a reason, on record) and back (#10732).
 */
class ManageStanding extends Command
{
    protected $signature = 'bloodhound:standing
        {action : show, exempt or unexempt}
        {user : The member\'s user id}
        {--reason= : Why they are exempt (required to exempt)}
        {--by= : The deciding admin\'s user id (required from bloodhound 10)}';

    protected $description = 'Show a member\'s standing against the ratio rule, or exempt them from it';

    public function handle(RatioEnforcer $enforcer): int
    {
        $userId = (int) $this->argument('user');

        return match ($this->argument('action')) {
            'show' => $this->show($enforcer, $userId),
            'exempt' => $this->exempt($enforcer, $userId),
            'unexempt' => $this->unexempt($enforcer, $userId),
            default => $this->refuse('The action is show, exempt or unexempt.'),
        };
    }

    private function show(RatioEnforcer $enforcer, int $userId): int
    {
        $standing = $enforcer->standingFor($userId);
        $row = MemberStanding::query()->where('user_id', $userId)->first();

        $this->line("Member {$userId}: {$standing->state->value}");

        if ($standing->until !== null) {
            $this->line('Deadline: '.$standing->until->toDateTimeString());
        }

        if ($standing->reason !== null) {
            $this->line($standing->reason);
        }

        if ($row?->isExempted()) {
            $this->line(sprintf(
                'Exempted %s by %s: %s',
                $row->exempted_at?->toDateTimeString(),
                $row->exempted_by === null ? 'unknown' : "user {$row->exempted_by}",
                $row->exempt_reason,
            ));
        }

        return self::SUCCESS;
    }

    private function exempt(RatioEnforcer $enforcer, int $userId): int
    {
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            return $this->refuse('An exemption needs a reason: --reason="..."');
        }

        // Who granted it, so the audit trail is never blank (TouchUp #57). A
        // --by that names nobody is refused. Leaving it out still works, as
        // the 9.x docs showed it, with a warning: it is required from 10.0.
        $by = $this->option('by');

        if ($by !== null) {
            $userModel = config('trove.user_model', 'App\\Models\\User');

            if (! ctype_digit((string) $by) || ! $userModel::query()->whereKey((int) $by)->exists()) {
                return $this->refuse("--by must be the deciding admin's user id; no user has id [{$by}].");
            }
        } else {
            $this->warn('No --by: the exemption records no admin. Pass --by=<your user id>; it will be required from bloodhound 10.');
        }

        $enforcer->exempt($userId, $by === null ? null : (int) $by, $reason);
        $this->info("Member {$userId} is exempt from the ratio rule.");

        return self::SUCCESS;
    }

    private function unexempt(RatioEnforcer $enforcer, int $userId): int
    {
        $enforcer->unexempt($userId);
        $this->info("Member {$userId} is under the ratio rule again, judged at the next bloodhound:enforce-ratio.");

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
