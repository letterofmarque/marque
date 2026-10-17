<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Marque\Bloodhound\Models\AnnounceLog;
use Marque\Bloodhound\Services\LedgerAggregator;
use Marque\Bloodhound\Services\OpeningBalance;

// Spec #99 (the announce ledger), CP8.
//
// An install upgrading into the ledger already has user totals that people
// were held to, but nothing explaining them. Reconciliation would report every
// one as drift, on every run, forever — and alerting that is wrong from day
// one is alerting nobody reads.
//
// One synthetic ledger row per user gives reconciliation a known starting
// point. It asserts the old total was correct as of migration; it does not
// assert it was ever right, and nothing could — that history was never kept,
// which is precisely why the ledger now exists.
return new class extends Migration
{
    public function up(): void
    {
        // A fresh install has no pre-ledger history to carry, and an install
        // that already has announces has been running the ledger — in both
        // cases there is nothing to open a balance for. Guarding on this makes
        // the migration a no-op for everyone except the upgrade path it exists
        // for, which is the only case where synthesising rows is defensible.
        if (AnnounceLog::query()->exists()) {
            return;
        }

        $recorded = app(OpeningBalance::class)->record();

        // Recording a balance zeroes the column it copied, so the aggregator
        // can add it back without doubling it. Folding immediately closes that
        // window: without this, every user reads as having zero ratio between
        // the migration finishing and the next scheduled tick — which on a
        // private tracker is the difference between "upgraded" and "everyone
        // is suddenly below the ratio threshold".
        //
        // But the aggregator is today's code, and it reads and writes columns
        // later migrations add (leechtime, seed_seconds). An install migrating
        // from before 5.0 straight to a later release runs this first, before
        // those exist. So the fold happens here only when the schema it needs
        // is already in place; otherwise the 8.0 time-columns migration does it
        // the moment it is (Job #193).
        if ($recorded > 0 && Schema::connection(config('bloodhound.announce_log.connection'))->hasColumn('announce_log', 'seed_seconds')) {
            app(LedgerAggregator::class)->run();
        }
    }

    public function down(): void
    {
        AnnounceLog::query()
            ->where('event', AnnounceLog::EVENT_OPENING_BALANCE)
            ->delete();
    }
};
