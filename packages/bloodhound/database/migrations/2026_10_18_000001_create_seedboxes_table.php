<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// bloodhound 8.0.0 (Job #193): networks members register as seedboxes. An
// approved one's announces are held to the seedbox speed cap instead of the
// ordinary one.
//
// The network is stored normalised (CIDR, network address), and matched in
// PHP against a member's cached approved networks, so the announce path asks
// the database nothing. No foreign key to users, like torrent_user: the user
// model is whatever the host app configured.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seedboxes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('network', 43);
            $table->string('label', 100)->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'network']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seedboxes');
    }
};
