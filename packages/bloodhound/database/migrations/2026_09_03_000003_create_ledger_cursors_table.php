<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Spec #99 (the announce ledger), CP5.
//
// How far each projection has consumed the ledger. One row per stream, so a
// second projection added later reads the same ledger independently without
// disturbing this one.
//
// The cursor is advanced inside the same transaction that writes the
// projection it belongs to. That is the entire crash-safety mechanism: a
// worker that dies mid-batch rolls back both, so the batch is redone rather
// than half-applied. Nothing here depends on the queue delivering anything.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('stream', 64)->unique();

            // The highest announce_log id folded into this stream's projection.
            // Everything above it is unprocessed; everything at or below is
            // already counted.
            $table->unsignedBigInteger('position')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_cursors');
    }
};
