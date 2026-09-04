<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Marque\Parley\Models\Category;
use Marque\Parley\Models\Post;
use Marque\Parley\Models\Thread;

// Marque ships into applications that have made their own decisions about
// mass assignment. A package model must therefore protect itself: it cannot
// assume the host app calls Model::unguard(), and equally must not assume it
// does not.
//
// `$guarded = []` is the trap. It reads as "nothing is guarded here", which is
// fine in an application you control, and wrong in a package — it makes every
// column of that model mass-assignable in any app that installs it, including
// columns like `pinned` and `locked` that only moderation should ever touch.
//
// Parley's three models shipped that way until 2026-09-04. Nothing had gone
// wrong, but the exposure was real and free to remove: every one of them is
// only ever created through a service with a fixed payload, so an explicit
// $fillable costs nothing and closes the hole.
//
// This test is per-package by necessity — a package's test suite only
// autoloads its own src — so the same file belongs in any Marque package that
// ships models.

/** @return list<class-string<Model>> */
function parleyModels(): array
{
    return [Category::class, Post::class, Thread::class];
}

it('declares an explicit fillable on every model', function () {
    foreach (parleyModels() as $class) {
        $model = new $class;

        expect($model->getFillable())
            ->not->toBeEmpty()
            ->and($model->getGuarded())->not->toBe([]);
    }
});

it('never ships a model that guards nothing', function () {
    foreach (parleyModels() as $class) {
        // getGuarded() === ['*'] is Eloquent's "everything is guarded" default,
        // which is what an explicit $fillable leaves in place. An empty array
        // is the dangerous one: it means totallyGuarded() is false and every
        // attribute is writable from request input.
        expect((new $class)->getGuarded())->toBe(['*']);
    }
});

it('keeps fillable in step with the columns the services actually write', function () {
    // A $fillable that is missing a column fails silently — the attribute is
    // dropped on create() rather than raising — so these assert the exact
    // payloads ThreadService, PostService and HasThreads build.
    expect((new Thread)->getFillable())
        ->toContain('threadable_type', 'threadable_id', 'category_id', 'title', 'user_id')
        // Moderation goes through update(), which respects $fillable too.
        ->toContain('pinned', 'locked');

    expect((new Post)->getFillable())
        ->toContain('thread_id', 'user_id', 'body', 'body_format', 'reply_to_id');

    expect((new Category)->getFillable())
        ->toContain('name', 'slug', 'description', 'position');
});
