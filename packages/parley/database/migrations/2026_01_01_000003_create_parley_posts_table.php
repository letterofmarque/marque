<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A post within a thread.
 *
 * Two columns carry decisions worth stating.
 *
 * `reply_to_id` is a self-reference with NO depth limit. Replies nest
 * arbitrarily deep in storage, and how far the UI indents before offering a
 * "continue thread" affordance is a config value (parley.nesting.indent_depth).
 * Keeping the cap out of the schema is what makes changing the display a
 * template edit rather than a migration.
 *
 * `body_format` records which squidink parser wrote this post. Without it, a
 * site that later enables BBCode — or imports legacy content — would have to
 * re-parse and rewrite every existing row. With it, posts written under
 * different syntaxes coexist forever and each renders the way its author meant.
 * This is the column that is expensive to add later, once there is data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('parley_posts')) {
            return;
        }

        Schema::create('parley_posts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('thread_id')
                ->constrained('parley_threads')
                ->cascadeOnDelete();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->text('body');

            // The squidink parser this body was written with. Null means "use
            // whatever the site is configured to default to", which is the
            // right answer for content written before a site added a parser.
            $table->string('body_format')->nullable();

            // Arbitrary nesting. A deleted parent does not delete its replies —
            // they are reparented in the renderer, since destroying a subtree
            // because someone removed one message loses other people's words.
            $table->foreignId('reply_to_id')
                ->nullable()
                ->constrained('parley_posts')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['thread_id', 'created_at']);
            $table->index('reply_to_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parley_posts');
    }
};
