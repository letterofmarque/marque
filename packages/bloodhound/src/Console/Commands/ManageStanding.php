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
        {--by= : The deciding admin\'s user id}';

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

        $by = $this->option('by');
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
