<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Job #187 (#11021): credit whose ledger rows have been pruned.
//
// Pruning deleted aggregated rows and kept nothing of them, so the ledger
// stopped explaining the totals it had produced. Reconcile then reported
// every pruned user as drift, and the rebuild it recommended erased their
// credit. A carry row is that credit, kept: per (user, torrent), what the
// deleted rows contributed. The ledger is its rows plus its carry.
//
// On announce_log's connection, not the app default. The carry is part of
// the ledger, and a prune must write it in the same transaction as the
// delete it accounts for, which one connection makes possible.
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('bloodhound.announce_log.connection');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('ledger_carry', function (Blueprint $table) {
            $table->id();

            // No ->constrained(), for the reason announce_log gives: on a
            // separate connection a foreign key cannot reach users or torrents.
            $table->foreignId('user_id');
            $table->foreignId('torrent_id');

            $table->unsignedBigInteger('uploaded')->default(0);
            $table->unsignedBigInteger('downloaded')->default(0);
            $table->unsignedBigInteger('seedtime')->default(0);
            $table->unsignedBigInteger('leechtime')->default(0);

            $table->timestamps();

            $table->unique(['user_id', 'torrent_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('ledger_carry');
    }
};
