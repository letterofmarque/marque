<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Runs on config('bloodhound.announce_log.connection') — an operator who's
    // pointed announce_log at a separate database gets this table created
    // there, not on the app's default connection. See Spec #98's "Storage: a
    // DB table, on a swappable connection" — AnnounceLog::getConnectionName()
    // reads the same config key, so model and schema always agree on where
    // this table actually lives.
    public function getConnection(): ?string
    {
        return config('bloodhound.announce_log.connection');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('announce_log', function (Blueprint $table) {
            $table->id();

            // No ->constrained() — a real foreign key requires user_id/torrent_id
            // to live on the SAME connection as this table, which breaks the
            // whole point of the swappable-connection design the moment an
            // operator points announce_log at a separate database. Referential
            // integrity here is the application's job (the row is written
            // alongside a real announce that already validated the user/torrent
            // exist), not the schema's.
            $table->foreignId('user_id');
            $table->foreignId('torrent_id');
            $table->string('peer_id', 20);
            $table->string('event', 20); // AnnounceEvent: started|stopped|completed|regular
            $table->string('ip', 45); // IPv6-safe
            $table->unsignedInteger('port');
            $table->string('user_agent', 255)->nullable();

            // Cumulative totals as reported by the client this announce.
            $table->unsignedBigInteger('uploaded');
            $table->unsignedBigInteger('downloaded');
            $table->unsignedBigInteger('left');

            // Delta since the peer's last announce — the number that actually
            // matters for ratio math, computed once by PeerService::upsertPeer()
            // and carried through rather than recalculated. See Spec #98.
            $table->unsignedBigInteger('upload_delta');
            $table->unsignedBigInteger('download_delta');

            $table->boolean('anti_cheat_flagged')->default(false);
            $table->string('anti_cheat_reason')->nullable();

            // Append-only: no updated_at, a row is never modified after write.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['torrent_id', 'created_at']);
            $table->index(['ip', 'created_at']);
            $table->index(['anti_cheat_flagged']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('announce_log');
    }
};
