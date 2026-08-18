<?php

declare(strict_types=1);

use Marque\Parley\Models\Category;
use Marque\Parley\Models\Post;
use Marque\Parley\Models\Thread;
use Marque\Parley\Tests\TestSubject;
use Marque\Parley\Tests\TestUser;

describe('one model, several presentations', function () {
    it('makes a forum thread: titled, categorised, standalone', function () {
        $thread = Thread::factory()->create();

        expect($thread->isForum())->toBeTrue()
            ->and($thread->isComments())->toBeFalse()
            ->and($thread->title)->not->toBeNull()
            ->and($thread->category)->toBeInstanceOf(Category::class);
    });

    it('makes a comment thread: attached, no category, no title', function () {
        $subject = TestSubject::create(['name' => 'a thing']);

        $thread = Thread::factory()->on($subject)->create();

        expect($thread->isComments())->toBeTrue()
            ->and($thread->isForum())->toBeFalse()
            ->and($thread->title)->toBeNull()
            ->and($thread->category_id)->toBeNull();
    });

    it('makes an announcement, which is a pinned locked forum thread', function () {
        $thread = Thread::factory()->announcement()->create();

        expect($thread->pinned)->toBeTrue()
            ->and($thread->locked)->toBeTrue()
            ->and($thread->isForum())->toBeTrue();
    });

    it('separates the two kinds by scope', function () {
        $subject = TestSubject::create(['name' => 'a thing']);

        Thread::factory()->count(2)->create();
        Thread::factory()->on($subject)->count(3)->create();

        expect(Thread::forum()->count())->toBe(2)
            ->and(Thread::comments()->count())->toBe(3);
    });

    it('orders pinned threads first, then newest', function () {
        $old = Thread::factory()->create(['created_at' => now()->subDays(2)]);
        $new = Thread::factory()->create(['created_at' => now()]);
        $pinned = Thread::factory()->pinned()->create(['created_at' => now()->subDays(5)]);

        expect(Thread::ordered()->pluck('id')->all())
            ->toBe([$pinned->id, $new->id, $old->id]);
    });
});

describe('relationships', function () {
    it('resolves the user through trove config rather than a hard-coded class', function () {
        $thread = Thread::factory()->create();

        expect($thread->user)->toBeInstanceOf(TestUser::class);
    });

    it('attaches a thread to any model via the morph', function () {
        $subject = TestSubject::create(['name' => 'anything at all']);
        $thread = Thread::factory()->on($subject)->create();

        expect($thread->threadable)->toBeInstanceOf(TestSubject::class)
            ->and($thread->threadable->id)->toBe($subject->id);
    });

    it('links posts to their thread', function () {
        $thread = Thread::factory()->create();
        Post::factory()->count(3)->inThread($thread)->create();

        expect($thread->posts)->toHaveCount(3)
            ->and($thread->posts->first()->thread->id)->toBe($thread->id);
    });

    it('separates root posts from replies', function () {
        $thread = Thread::factory()->create();
        $root = Post::factory()->inThread($thread)->create();
        Post::factory()->replyTo($root)->create();

        expect($thread->posts)->toHaveCount(2)
            ->and($thread->rootPosts)->toHaveCount(1);
    });

    it('links a category to its threads', function () {
        $category = Category::factory()->create();
        Thread::factory()->count(2)->create(['category_id' => $category->id]);

        expect($category->threads)->toHaveCount(2);
    });
});

describe('arbitrary reply nesting', function () {
    it('nests as deep as it likes, with no cap in the data', function () {
        $thread = Thread::factory()->create();

        $post = Post::factory()->inThread($thread)->create();
        $depth = 12;

        for ($i = 0; $i < $depth; $i++) {
            $post = Post::factory()->replyTo($post)->create();
        }

        // Twelve deep is well past any sane indent limit — which is the point.
        // The renderer decides where to stop indenting; storage does not care.
        expect($thread->posts)->toHaveCount($depth + 1);

        $walked = 0;
        $current = $post;

        while ($current->replyTo !== null) {
            $walked++;
            $current = $current->replyTo;
        }

        expect($walked)->toBe($depth);
    });

    it('knows a reply from a root post', function () {
        $root = Post::factory()->create();
        $reply = Post::factory()->replyTo($root)->create();

        expect($root->isReply())->toBeFalse()
            ->and($reply->isReply())->toBeTrue();
    });

    it('exposes direct replies', function () {
        $root = Post::factory()->create();
        Post::factory()->count(3)->replyTo($root)->create();

        expect($root->replies)->toHaveCount(3);
    });

    it('keeps replies when their parent is deleted', function () {
        $thread = Thread::factory()->create();
        $root = Post::factory()->inThread($thread)->create();
        $reply = Post::factory()->replyTo($root)->create();

        $root->forceDelete();

        // Destroying a subtree because one person removed their message would
        // take other people's words with it. The reply survives, orphaned.
        expect(Post::find($reply->id))->not->toBeNull()
            ->and(Post::find($reply->id)->reply_to_id)->toBeNull();
    });
});

describe('soft deletion', function () {
    it('soft-deletes a thread', function () {
        $thread = Thread::factory()->create();
        $thread->delete();

        expect(Thread::find($thread->id))->toBeNull()
            ->and(Thread::withTrashed()->find($thread->id))->not->toBeNull();
    });

    it('soft-deletes a post', function () {
        $post = Post::factory()->create();
        $post->delete();

        expect(Post::find($post->id))->toBeNull()
            ->and(Post::withTrashed()->find($post->id))->not->toBeNull();
    });
});

describe('post bodies render through squidink', function () {
    it('renders markdown', function () {
        $post = Post::factory()->format('markdown', '**bold**')->create();

        expect($post->renderedBody())->toBe('<p><strong>bold</strong></p>');
    });

    it('renders bbcode', function () {
        $post = Post::factory()->format('bbcode', '[b]bold[/b]')->create();

        expect($post->renderedBody())->toBe('<p><strong>bold</strong></p>');
    });

    it('renders each post in its own recorded syntax', function () {
        // The reason body_format is per record: both of these coexist in one
        // thread and each renders the way its author meant.
        $thread = Thread::factory()->create();

        $markdown = Post::factory()->inThread($thread)->format('markdown', '**x**')->create();
        $bbcode = Post::factory()->inThread($thread)->format('bbcode', '[b]x[/b]')->create();

        expect($markdown->renderedBody())->toBe($bbcode->renderedBody())
            ->and($markdown->format())->toBe('markdown')
            ->and($bbcode->format())->toBe('bbcode');
    });

    it('falls back to the configured parser when a post records none', function () {
        config()->set('parley.format.parser', 'bbcode');

        $post = Post::factory()->create(['body' => '[b]x[/b]', 'body_format' => null]);

        expect($post->format())->toBe('bbcode')
            ->and($post->renderedBody())->toBe('<p><strong>x</strong></p>');
    });

    it('renders to plain text for indexing', function () {
        $post = Post::factory()->format('markdown', '**bold** and [a link](https://example.com)')->create();

        expect($post->plainBody())->toBe('bold and a link');
    });

    it('does not sanitise, because the schema already did', function () {
        $post = Post::factory()->format('bbcode', '[url=javascript:alert(1)]click[/url]')->create();

        expect($post->renderedBody())->not->toContain('javascript');
    });
});
