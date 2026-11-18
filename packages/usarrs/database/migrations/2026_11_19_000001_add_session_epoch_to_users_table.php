<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The member's session epoch (#11154). Every session is stamped with it at
 * sign-in; "sign out every other browser" moves it on, and a session whose
 * stamp no longer matches is ended. The password is never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'session_epoch')) {
                $table->unsignedInteger('session_epoch')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('session_epoch');
        });
    }
};
