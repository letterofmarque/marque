<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// bloodhound 10.1.0 (#11112): the addresses a client announced besides the one
// it connected from (BEP 7's ipv4= and ipv6=), which the tracker then hands to
// other peers. Recorded because trusting them is what makes a member
// answerable for them. Null when the client sent none, or when
// trust_announced_addresses is off.
//
// Nullable with no default: an instant change on MySQL 8, MariaDB and
// PostgreSQL, so a large announce_log is not rewritten.
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('bloodhound.announce_log.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasColumn('announce_log', 'announced_ipv4')) {
            return;
        }

        $schema->table('announce_log', function (Blueprint $table) {
            $table->string('announced_ipv4', 15)->nullable();
            $table->string('announced_ipv6', 39)->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasColumn('announce_log', 'announced_ipv4')) {
            $schema->table('announce_log', function (Blueprint $table) {
                $table->dropColumn(['announced_ipv4', 'announced_ipv6']);
            });
        }
    }
};
