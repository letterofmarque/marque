<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-torrent access control: the minimum role required to see or download a
// torrent. Null — the default — means everyone, so existing rows keep their
// current behaviour and nothing is hidden by upgrading.
//
// Deliberately a single ranked value rather than a group/permission system.
// Trove's Role enum is ranked (user < uploader < moderator < admin), and the
// restriction it needs to express is "uploader-level and above", which
// hasRoleAtLeast() answers directly. Sideways membership — roles that are
// distinct but not ordered — would need a different model entirely, and is not
// what this is for.
//
// Stored as the enum's string value rather than its rank, so the column stays
// readable and the ranking can be reordered without a data migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('torrents', function (Blueprint $table) {
            $table->string('min_role')->nullable()->after('user_id');
        });

        Schema::table('torrents', function (Blueprint $table) {
            $table->index('min_role');
        });
    }

    public function down(): void
    {
        Schema::table('torrents', function (Blueprint $table) {
            $table->dropIndex(['min_role']);
        });

        Schema::table('torrents', function (Blueprint $table) {
            $table->dropColumn('min_role');
        });
    }
};
