<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Passkeys\Passkeys;

// Reproduces laravel/passkeys' own migration (vendor/laravel/passkeys/
// database/migrations) under usarrs' own migration set, per Spec #92 Open
// Question 2 — usarrs owns its migrations rather than delegating schema to
// a dependency whose own migrations are never loaded.
//
// IMPORTANT — laravel/passkeys v0.2.1 hardcodes the 'user_id' column name on
// BOTH sides of the relationship inconsistently: Passkey::user() calls
// belongsTo($model, 'user_id') explicitly, but PasskeyAuthenticatable::
// passkeys() calls hasMany() with no explicit foreign key, which makes
// Eloquent derive it from the consumer's model class name (e.g.
// 'test_user_id' for a class named TestUser). The two only agree when the
// consumer's user model class is literally named `User` — this migration
// keeps the upstream-matching 'user_id' column for that reason, and usarrs'
// docs must say plainly: passkeys require your user model class to be named
// `User` (any namespace), regardless of what trove.user_model points at.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('passkeys')) {
            return;
        }

        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Passkeys::userModel(), 'user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
