<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands\Concerns;

/**
 * What accept-totals and adjust-totals share (Job #213): a reason and an
 * author who exists, checked before anything is shown or asked, and a
 * confirmation that --force answers for a script.
 */
trait RecordsLedgerAdjustments
{
    private function reason(): ?string
    {
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->error('Say why: --reason="...". It is kept with the adjustment.');

            return null;
        }

        return $reason;
    }

    /**
     * Who decided, so the record is never blank.
     */
    private function by(): ?int
    {
        $by = $this->option('by');

        if ($by === null) {
            $this->error('Name the deciding admin: --by=<your user id>.');

            return null;
        }

        $userModel = config('trove.user_model', 'App\\Models\\User');

        if (! ctype_digit((string) $by) || ! $userModel::query()->whereKey((int) $by)->exists()) {
            $this->error("--by must be the deciding admin's user id; no user has id [{$by}].");

            return null;
        }

        return (int) $by;
    }

    private function agreed(string $question): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if ($this->confirm($question)) {
            return true;
        }

        $this->line('Nothing written.'.($this->input->isInteractive() ? '' : ' Pass --force to write without asking.'));

        return false;
    }
}
