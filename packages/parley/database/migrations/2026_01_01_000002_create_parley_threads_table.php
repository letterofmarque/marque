<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A discussion, whatever it is attached to.
 *
 * One table serves every presentation, which is the whole point of the design:
 *
 *   torrent comments  threadable set, category null, title null
 *   forum thread      threadable null, category set, title set
 *   announcement      as above, pinned + locked
 *
 * The nullable morph is what makes "comments on anything" free rather than a
 * feature per model.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('parley_threads')) {
            return;
        }

        Schema::create('parley_threads', function (Blueprint $table) {
            $table->id();

            // Nullable morph: set for attached discussion, null for forum
            // threads that stand on their own.
            $table->nullableMorphs('threadable');

            $table->foreignId('category_id')
                ->nullable()
                ->constrained('parley_categories')
                ->nullOnDelete();

            // Comment threads have no title — the thing they hang off is the
            // subject.
            $table->string('title')->nullable();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->boolean('pinned')->default(false);
            $table->boolean('locked')->default(false);

            $table->timestamps();
            $table->softDeletes();

            // Listing a forum category: pinned first, then most recent.
            $table->index(['category_id', 'pinned', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parley_threads');
    }
};
