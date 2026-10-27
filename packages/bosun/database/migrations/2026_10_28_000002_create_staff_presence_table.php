<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each staff member was last on the site. Staff only: members are never
 * stamped. No foreign key, like staff_actions, so it can't block deleting a
 * user; a row for a deleted user is simply never shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_presence', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->timestamp('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_presence');
    }
};
