<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// bloodhound 9.0.0 (#10732, Job #255): where a member stands against the ratio
// rule. A row exists only for a member on watch, restricted, or exempted by an
// admin; everyone else is ok, which keeps the table to the members it is about.
//
// state is derived (bloodhound:enforce-ratio writes it from the totals). The
// exemption columns are an admin's decision, and the enforcer never touches
// them. No foreign key to users, like seedboxes: the user model is whatever
// the host app configured.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_standings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('state', 16)->default('ok');
            $table->timestamp('watch_started_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('restricted_at')->nullable();
            $table->timestamp('exempted_at')->nullable();
            $table->unsignedBigInteger('exempted_by')->nullable();
            $table->string('exempt_reason', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_standings');
    }
};
