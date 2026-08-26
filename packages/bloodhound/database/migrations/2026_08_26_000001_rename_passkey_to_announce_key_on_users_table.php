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

        if (! Schema::hasColumn($tableName, 'passkey') || Schema::hasColumn($tableName, 'announce_key')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->renameColumn('passkey', 'announce_key');
        });
    }

    public function down(): void
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');
        $tableName = (new $userModel)->getTable();

        if (! Schema::hasColumn($tableName, 'announce_key') || Schema::hasColumn($tableName, 'passkey')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->renameColumn('announce_key', 'passkey');
        });
    }
};
