<?php

declare(strict_types=1);

namespace Marque\Parley\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Marque\Parley\Models\Category;
use Marque\Parley\Models\Thread;

/**
 * @extends Factory<Thread>
 */
class ThreadFactory extends Factory
{
    protected $model = Thread::class;

    /**
     * Defaults to a forum thread — titled, categorised, standalone. The
     * comment shape is a state rather than the default because it needs
     * something to attach to.
     */
    public function definition(): array
    {
        return [
            'threadable_type' => null,
            'threadable_id' => null,
            'category_id' => Category::factory(),
            'title' => 'Thread '.fake()->words(3, true),
            'user_id' => $this->userId(),
            'pinned' => false,
            'locked' => false,
        ];
    }

    /**
     * A comment thread: attached to a model, no category, no title.
     */
    public function on(Model $subject): static
    {
        return $this->state(fn (): array => [
            'threadable_type' => $subject->getMorphClass(),
            'threadable_id' => $subject->getKey(),
            'category_id' => null,
            'title' => null,
        ]);
    }

    public function pinned(): static
    {
        return $this->state(fn (): array => ['pinned' => true]);
    }

    public function locked(): static
    {
        return $this->state(fn (): array => ['locked' => true]);
    }

    /**
     * An announcement is a pinned, locked forum thread — a presentation, not a
     * fourth kind of record.
     */
    public function announcement(): static
    {
        return $this->state(fn (): array => ['pinned' => true, 'locked' => true]);
    }

    /**
     * The user model belongs to the host app, so the factory resolves it from
     * config rather than naming one.
     */
    private function userId(): mixed
    {
        $model = config('trove.user_model', 'App\\Models\\User');

        return $model::factory();
    }
}
