<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Spec #121: seconds spent leeching, beside seconds spent seeding, at both
// levels seedtime is carried. Hit-and-run rules want both halves — how long
// someone took to finish as well as how long they seeded afterwards.
//
// A new migration, deliberately. Neither shipped users-table migration is
// touched: both have run in production databases, and editing a migration
// that has run is how you destroy one.
return new class extends Migration
{
    public function up(): void
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');
        $usersTable = (new $userModel)->getTable();

        if (! Schema::hasColumn($usersTable, 'leechtime')) {
            Schema::table($usersTable, function (Blueprint $table) {
                $table->unsignedBigInteger('leechtime')->default(0)->after('seedtime');
            });
        }

        if (! Schema::hasColumn('torrent_user', 'leechtime')) {
            Schema::table('torrent_user', function (Blueprint $table) {
                $table->unsignedBigInteger('leechtime')->default(0)->after('seedtime');
            });
        }
    }

    public function down(): void
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');
        $usersTable = (new $userModel)->getTable();

        Schema::table($usersTable, function (Blueprint $table) {
            $table->dropColumn('leechtime');
        });

        Schema::table('torrent_user', function (Blueprint $table) {
            $table->dropColumn('leechtime');
        });
    }
};
