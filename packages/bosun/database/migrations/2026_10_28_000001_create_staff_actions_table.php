<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What staff did, as packages record it through trove's StaffActionsInterface.
 *
 * No foreign key to users, on purpose: an audit log has to outlive the account
 * it's about, and a cascade would erase a banned admin's history with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id');
            $table->string('action', 128);
            $table->string('package', 64);
            $table->string('subject_type', 191);
            $table->string('subject_id', 64);
            $table->text('reason')->nullable();
            $table->json('changes')->nullable();
            $table->timestamp('created_at');

            $table->index(['actor_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_actions');
    }
};
