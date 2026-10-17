<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// bloodhound 8.0.0 (Job #193): time is credited at announce time, like bytes,
// and written on the row that credits it. A total is then a sum of rows, so
// nothing has to pair announces after the fact to work out how long anyone
// was connected.
//
// seed_seconds / leech_seconds: what this row credits. One interval, at most
// peer_expiry, so 32 bits is ample. prior_at: when the interval began (the
// previous announce's time), null with the other priors when there was no
// baseline.
//
// Rows written before this column existed keep 0 and null: their time is
// recorded, if the operator wants it, as carry by bloodhound:backfill-ledger,
// never by rewriting the rows.
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('bloodhound.announce_log.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->table('announce_log', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('announce_log', 'seed_seconds')) {
                $table->unsignedInteger('seed_seconds')->default(0)->after('download_delta');
            }

            if (! $schema->hasColumn('announce_log', 'leech_seconds')) {
                $table->unsignedInteger('leech_seconds')->default(0)->after('seed_seconds');
            }

            if (! $schema->hasColumn('announce_log', 'prior_at')) {
                $table->timestamp('prior_at')->nullable()->after('prior_down');
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('announce_log', function (Blueprint $table) {
            $table->dropColumn(['seed_seconds', 'leech_seconds', 'prior_at']);
        });
    }
};
