<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Replaces the `visible` flag added in 2026_09_02_000001 with real seeder and
// leecher counts.
//
// `visible` was only ever written in one place — bloodhound's AnnounceService
// flipped it to true when a seeder announced — and nothing ever set it back to
// false. No reaper, no expiry path. Every torrent started true (the column
// default) and could only go true, so the guard reading it was unreachable and
// the column carried no information.
//
// Live peer state is Redis; these columns are a queryable projection of it, so
// "dead" is `seeders = 0` rather than a separate boolean nothing maintained.
// They also give the listing sortable swarm columns, which every tracker UI
// wants regardless.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('torrents', function (Blueprint $table) {
            if (! Schema::hasColumn('torrents', 'seeders')) {
                $table->unsignedInteger('seeders')->default(0)->after('file_count');
            }

            if (! Schema::hasColumn('torrents', 'leechers')) {
                $table->unsignedInteger('leechers')->default(0)->after('seeders');
            }
        });

        // Indexed separately rather than as a composite: the listing filters on
        // seeders and may sort on either, and SQLite cannot add an index inside
        // the same closure that adds the column on some versions.
        Schema::table('torrents', function (Blueprint $table) {
            $table->index('seeders');
        });

        Schema::table('torrents', function (Blueprint $table) {
            if (Schema::hasColumn('torrents', 'visible')) {
                $table->dropColumn('visible');
            }
        });
    }

    public function down(): void
    {
        Schema::table('torrents', function (Blueprint $table) {
            $table->dropIndex(['seeders']);
        });

        Schema::table('torrents', function (Blueprint $table) {
            $table->dropColumn(['seeders', 'leechers']);
        });

        Schema::table('torrents', function (Blueprint $table) {
            if (! Schema::hasColumn('torrents', 'visible')) {
                $table->boolean('visible')->default(true)->after('times_completed');
            }
        });
    }
};
