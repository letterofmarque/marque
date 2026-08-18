<?php

declare(strict_types=1);

namespace Marque\Parley\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Marque\Parley\Models\Post;
use Marque\Parley\Models\Thread;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    protected $model = Post::class;

    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'user_id' => $this->userId(),
            'body' => fake()->paragraph(),
            'body_format' => null,
            'reply_to_id' => null,
        ];
    }

    /**
     * A reply to another post. Nesting is arbitrary, so chaining these builds
     * a subtree of any depth.
     */
    public function replyTo(Post $post): static
    {
        return $this->state(fn (): array => [
            'reply_to_id' => $post->getKey(),
            'thread_id' => $post->thread_id,
        ]);
    }

    public function inThread(Thread $thread): static
    {
        return $this->state(fn (): array => ['thread_id' => $thread->getKey()]);
    }

    /**
     * Written in a specific syntax, so a fixture can exercise the fact that
     * posts record their own parser.
     */
    public function format(string $parser, ?string $body = null): static
    {
        return $this->state(fn (): array => array_filter([
            'body_format' => $parser,
            'body' => $body,
        ], static fn ($v): bool => $v !== null));
    }

    private function userId(): mixed
    {
        $model = config('trove.user_model', 'App\\Models\\User');

        return $model::factory();
    }
}
