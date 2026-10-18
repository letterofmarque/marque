<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A peer ID that is not UTF-8 text is stored hex-encoded, as `x:` and 40 hex
// digits (see PeerIdColumn): wider than the 20 characters the column held.
// Existing rows are not changed; every peer ID they hold was stored as it is.
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('bloodhound.announce_log.connection');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table('announce_log', function (Blueprint $table) {
            $table->string('peer_id', 64)->change();
        });
    }

    public function down(): void
    {
        // Not narrowed back: an encoded peer ID would no longer fit.
    }
};
