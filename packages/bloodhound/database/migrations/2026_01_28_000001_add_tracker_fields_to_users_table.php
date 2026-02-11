<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');
        $tableName = (new $userModel)->getTable();

        if (Schema::hasColumn($tableName, 'passkey')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            // Passkey for tracker authentication
            $table->string('passkey', 32)->nullable()->unique()->after('remember_token');

            // Transfer stats (in bytes)
            $table->unsignedBigInteger('uploaded')->default(0)->after('passkey');
            $table->unsignedBigInteger('downloaded')->default(0)->after('uploaded');

            // Seeding time (in seconds) for ratioless tracking
            $table->unsignedBigInteger('seedtime')->default(0)->after('downloaded');

            // Index for passkey lookups (announce uses this)
            $table->index('passkey');
        });
    }

    public function down(): void
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');
        $table = (new $userModel)->getTable();

        Schema::table($table, function (Blueprint $table) {
            $table->dropIndex(['passkey']);
            $table->dropColumn(['passkey', 'uploaded', 'downloaded', 'seedtime']);
        });
    }
};
