<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forum categories.
 *
 * Only forum threads belong to a category — comment threads attached to a
 * torrent (or anything else) have none, which is why threads.category_id is
 * nullable. A comments-only deployment leaves this table empty rather than
 * needing a different schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('parley_categories')) {
            return;
        }

        Schema::create('parley_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parley_categories');
    }
};
