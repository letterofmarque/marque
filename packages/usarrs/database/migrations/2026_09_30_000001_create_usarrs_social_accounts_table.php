<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An OAuth identity linked to an account (Spec #142).
 *
 * An OAuth login resolves through (provider, provider_user_id) and nothing
 * else — the provider's email plays no part, which is what closes the takeover
 * where a provider asserting someone's address was signed in as them.
 *
 * Prefixed `usarrs_` because `social_accounts` is a name a host app may well
 * already use.
 */
return new class extends Migration
{
    public function up(): void
    {
        $userModel = config('trove.user_model', 'App\\Models\\User');

        Schema::create('usarrs_social_accounts', function (Blueprint $table) use ($userModel) {
            $table->id();
            $table->foreignIdFor($userModel, 'user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('provider_user_id', 191);
            $table->timestamps();

            // One account per provider identity, and one identity per provider
            // per user.
            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usarrs_social_accounts');
    }
};
