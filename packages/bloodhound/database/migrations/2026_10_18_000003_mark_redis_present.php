<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Redis;

// bloodhound 8.0.0: recovery mode opens when Redis's sentinel key is missing
// while the ledger shows recent announces, which is what a lost Redis looks
// like. An install upgrading from 7.x has a whole swarm in Redis and no
// sentinel yet, and would read as lost. Set it here, while the swarm is known
// to be there. Unreachable Redis is not this migration's to judge: the first
// announces then decide, as on any install.
return new class extends Migration
{
    public function up(): void
    {
        try {
            Redis::connection(config('threepio.redis.connection', 'default'))
                ->set(config('threepio.redis.prefix', 'marque:').'durability_sentinel', '1');
        } catch (Throwable) {
            // See above.
        }
    }

    public function down(): void
    {
        // Nothing to undo: the sentinel only says Redis has not been lost.
    }
};
