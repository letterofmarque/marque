<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Console\Commands;

use Illuminate\Console\Command;
use Marque\Bloodhound\Services\RedisDurability;

/**
 * Is the tracker's Redis keeping what it holds?
 *
 * Run before upgrading, and whenever the Redis behind the tracker changes. In
 * production bloodhound refuses announces while this fails.
 */
class CheckRedis extends Command
{
    protected $signature = 'bloodhound:check-redis
        {--recover : Open a recovery window by hand: for twice peer_expiry, a peer Redis does not know is looked up in the ledger}';

    protected $description = 'Check that the tracker\'s Redis persists (AOF) and never evicts';

    public function handle(RedisDurability $durability): int
    {
        if ($this->option('recover')) {
            $durability->openRecoveryWindow();
            $this->info('Recovery window open: baselines missing from Redis are read from the ledger for '.(2 * (int) config('threepio.peer_expiry', 3600)).' seconds.');
        }

        $verdict = $durability->verify();

        $this->table(['Setting', 'Found', 'Needed'], [
            ['appendonly (INFO persistence: aof_enabled)', $verdict->aofEnabled === null ? 'unreadable' : ($verdict->aofEnabled ? 'yes' : 'no'), $verdict->attested ? 'attested' : 'yes'],
            ['maxmemory-policy (INFO memory)', $verdict->evictionPolicy ?? 'unreadable', 'noeviction'],
        ]);

        foreach ($verdict->warnings as $warning) {
            $this->warn($warning);
        }

        if ($verdict->ok()) {
            $this->info('The tracker\'s Redis is durable.');

            return self::SUCCESS;
        }

        foreach ($verdict->problems as $problem) {
            $this->error($problem);
        }

        $this->line('In redis.conf: appendonly yes, appendfsync everysec, maxmemory-policy noeviction. On a managed service, the same in its parameter group.');

        return self::FAILURE;
    }
}
