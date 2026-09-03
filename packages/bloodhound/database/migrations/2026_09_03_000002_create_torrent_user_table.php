<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Spec #99 (the announce ledger), CP4.
//
// Per-user-per-torrent accounting, which did not exist anywhere. Deltas were
// aggregated onto the user (all torrents combined) and onto Redis swarm totals
// (all users combined) — the intersection was computed on every announce and
// discarded. That made hit-and-run enforcement impossible to build and left
// users.uploaded with no finer-grained truth to reconcile against.
//
// This table also absorbs `snatches`, which had two defects. Nothing ever read
// it, and its updateOrCreate write overwrote completed_at on a redownload, so
// a January completion re-completed in July left one row dated July and the
// original was gone. A rule like "seed for 72 hours after completing" would
// have measured from the wrong date.
//
// Rows here are a PROJECTION of the ledger. CP5 wires the cursor that fills
// them; nothing writes the byte columns yet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('torrent_user', function (Blueprint $table) {
            $table->id();

            // No ->constrained(): torrents lives in trove and the user model is
            // whatever the host app configured, so a schema-level foreign key
            // would couple this table to both. Same reasoning as announce_log.
            $table->foreignId('user_id');
            $table->foreignId('torrent_id');

            // Bytes this user moved on this torrent, summed from ledger deltas.
            $table->unsignedBigInteger('uploaded')->default(0);
            $table->unsignedBigInteger('downloaded')->default(0);

            // Seconds spent seeding this torrent — the number hit-and-run rules
            // need, and which nothing recorded before.
            $table->unsignedBigInteger('seedtime')->default(0);

            // first_completed_at is written once and never again. That is the
            // whole point: a redownload updates last_completed_at and bumps the
            // counter, and the original date survives.
            $table->timestamp('first_completed_at')->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->unsignedInteger('times_completed')->default(0);

            $table->timestamps();

            $table->unique(['user_id', 'torrent_id']);
            $table->index(['torrent_id', 'first_completed_at']);
        });

        $this->carryOverSnatches();

        Schema::dropIfExists('snatches');
    }

    /**
     * Move existing snatch rows into the new table.
     *
     * Only the completion columns can be carried: snatches recorded one date
     * per user+torrent and no byte counts, so uploaded/downloaded start at 0.
     * Per-torrent history genuinely does not exist pre-migration and inventing
     * it would be worse than admitting the gap.
     *
     * The single surviving date populates BOTH first and last — it is the only
     * completion the old table could represent, and times_completed is 1 for
     * the same reason. A user who really completed a torrent three times looks
     * like one completion here, because that is all the old data ever said.
     */
    private function carryOverSnatches(): void
    {
        if (! Schema::hasTable('snatches')) {
            return;
        }

        DB::table('snatches')->orderBy('id')->chunk(500, function ($snatches) {
            $rows = [];

            foreach ($snatches as $snatch) {
                $rows[] = [
                    'user_id' => $snatch->user_id,
                    'torrent_id' => $snatch->torrent_id,
                    'uploaded' => 0,
                    'downloaded' => 0,
                    'seedtime' => 0,
                    'first_completed_at' => $snatch->completed_at,
                    'last_completed_at' => $snatch->completed_at,
                    'times_completed' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if ($rows !== []) {
                DB::table('torrent_user')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        // Recreate snatches so a rollback leaves the schema as it was found.
        // Completion data is carried back; byte counts and seedtime are lost,
        // because the old table had nowhere to put them.
        if (! Schema::hasTable('snatches')) {
            Schema::create('snatches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('torrent_id')->constrained()->cascadeOnDelete();
                $table->string('ip', 45);
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('completed_at');

                $table->unique(['user_id', 'torrent_id']);
                $table->index(['torrent_id', 'completed_at']);
            });

            DB::table('torrent_user')
                ->whereNotNull('first_completed_at')
                ->orderBy('id')
                ->chunk(500, function ($rows) {
                    $snatches = [];

                    foreach ($rows as $row) {
                        $snatches[] = [
                            'user_id' => $row->user_id,
                            'torrent_id' => $row->torrent_id,
                            'ip' => '0.0.0.0',
                            'user_agent' => null,
                            'completed_at' => $row->first_completed_at,
                        ];
                    }

                    if ($snatches !== []) {
                        DB::table('snatches')->insert($snatches);
                    }
                });
        }

        Schema::dropIfExists('torrent_user');
    }
};
