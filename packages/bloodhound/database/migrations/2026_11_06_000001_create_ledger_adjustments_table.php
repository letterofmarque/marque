<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Job #213: an operator's correction for totals the ledger can't explain.
//
// Some totals are real and no ledger row accounts for them: a 4.1.0 install's
// unexplained bytes (#11051), figures imported from an earlier tracker, a speed
// forfeit an operator decides was wrong. Reconcile read those as drift and the
// rebuild it recommends erased them. An adjustment is the operator saying, on
// the record, how much and why. The ledger is its rows, its carry and its
// adjustments.
//
// Carry is bookkeeping the tracker does for itself (a prune moving rows into
// it). An adjustment is a person's decision, so it is kept apart: one row per
// decision, never summed into another, never edited, never pruned.
//
// On announce_log's connection, as the carry is: reconcile and rebuild read
// all three together.
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('bloodhound.announce_log.connection');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('ledger_adjustments', function (Blueprint $table) {
            $table->id();

            // No ->constrained(): on a separate connection a foreign key
            // cannot reach users or torrents (see announce_log).
            $table->foreignId('user_id');

            // 0 is the member as a whole, not a torrent: what their own totals
            // hold beyond their torrents', the precedent the opening balances set.
            $table->foreignId('torrent_id')->default(0);

            // Signed: an adjustment can take credit away as well as give it,
            // and a wrong one is answered by another signed the other way.
            $table->bigInteger('uploaded')->default(0);
            $table->bigInteger('downloaded')->default(0);
            $table->bigInteger('seedtime')->default(0);
            $table->bigInteger('leechtime')->default(0);

            // Who decided, and why. Both required: a correction nobody can
            // account for is the silent change the ledger exists to prevent.
            $table->text('reason');
            $table->foreignId('made_by');

            // Made once, never changed, so there is no updated_at.
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'torrent_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('ledger_adjustments');
    }
};
