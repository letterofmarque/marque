<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Spec #99 (the announce ledger), CP1.
//
// announce_log already stores the client's reported cumulative totals and the
// delta we credited. It does not store the baseline that delta was computed
// against, which means a row cannot be checked without reference to the row
// before it — and if the baseline was wrong (a Redis loss, a bug), nothing in
// the table shows it.
//
// Storing prior alongside reported and delta makes every credit carry its own
// arithmetic proof: `delta == reported - prior` is checkable per row, and
// `this row's prior == the previous row's reported` is checkable per peer
// chain. A broken chain is the exact signature of a lost baseline, and it
// becomes visible rather than silent. CP7 builds the audit that uses this.
//
// Nullable because a first announce from a peer genuinely has no baseline —
// that is a real state, not missing data.
return new class extends Migration
{
    // Same connection resolution as the table's own migration — an operator who
    // has pointed announce_log at a separate database gets this ALTER there.
    public function getConnection(): ?string
    {
        return config('bloodhound.announce_log.connection');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table('announce_log', function (Blueprint $table) {
            if (! Schema::connection($this->getConnection())->hasColumn('announce_log', 'prior_up')) {
                $table->unsignedBigInteger('prior_up')->nullable()->after('download_delta');
            }

            if (! Schema::connection($this->getConnection())->hasColumn('announce_log', 'prior_down')) {
                $table->unsignedBigInteger('prior_down')->nullable()->after('prior_up');
            }
        });

        // Supports the Redis-miss fallback in CP3: "the last row for this peer
        // on this torrent". The existing (torrent_id, created_at) index does
        // not serve it — that one is for a torrent's activity over time, and
        // cannot seek to a single peer.
        Schema::connection($this->getConnection())->table('announce_log', function (Blueprint $table) {
            $table->index(['torrent_id', 'peer_id', 'id'], 'announce_log_peer_baseline_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('announce_log', function (Blueprint $table) {
            $table->dropIndex('announce_log_peer_baseline_index');
        });

        Schema::connection($this->getConnection())->table('announce_log', function (Blueprint $table) {
            $table->dropColumn(['prior_up', 'prior_down']);
        });
    }
};
