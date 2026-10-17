<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Models\LedgerCursor;
use Marque\Bloodhound\Services\LedgerAggregator;

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

        // The opening-balance migration (5.0) defers its fold when the schema
        // the aggregator needs is not there yet: an install migrating from
        // before 5.0 straight to 8.0. Its members' totals were zeroed when the
        // balance was recorded, so fold now, before anyone reads a zero ratio.
        $unfolded = DB::connection($this->getConnection())->table('announce_log')
            ->where('event', AnnounceLog::EVENT_OPENING_BALANCE)
            ->where('id', '>', LedgerCursor::positionFor(LedgerAggregator::STREAM))
            ->exists();

        if ($unfolded) {
            app(LedgerAggregator::class)->run();
        }
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('announce_log', function (Blueprint $table) {
            $table->dropColumn(['seed_seconds', 'leech_seconds', 'prior_at']);
        });
    }
};
