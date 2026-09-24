<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Announce keys move out of the consumer's users table into one bloodhound
// owns (Spec #119).
//
// Neither shipped users-table migration is touched: both have already run in
// production databases, and editing a migration that has run is how you
// destroy one. users.announce_key keeps its data and is simply never read
// again — no drop, no rename. A consumer who wants it gone can drop it; we
// never do that to a table we do not own.
//
// The key column is 255 wide, not 32. key_pattern is configurable and a
// tracker migrating onto Marque keeps whatever keys it already issued, which
// users.announce_key's varchar(32) could never hold on MySQL, MariaDB or
// PostgreSQL. SQLite ignores varchar length, which is how that went unseen.

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announce_keys', function (Blueprint $table) {
            $table->id();
            // No ->constrained(), for the same reason as torrent_user: the
            // user model is whatever the host app configured, so a
            // schema-level foreign key would bind this package to a table
            // name it cannot know.
            $table->foreignId('user_id')->unique();
            $table->string('key', 255)->unique();
            $table->timestamp('created_at')->nullable();
        });

        $userModel = config('trove.user_model', 'App\\Models\\User');
        $usersTable = (new $userModel)->getTable();

        if (! Schema::hasColumn($usersTable, 'announce_key')) {
            return;
        }

        // INSERT ... SELECT: one statement however many users there are.
        // created_at is left null for backfilled rows — the date they were
        // issued was never recorded, and inventing one would be worse.
        DB::table('announce_keys')->insertUsing(
            ['user_id', 'key'],
            fn (Builder $query) => $query
                ->from($usersTable)
                ->select(['id', 'announce_key'])
                ->whereNotNull('announce_key')
                ->where('announce_key', '!=', ''),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('announce_keys');
    }
};
