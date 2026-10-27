<?php

declare(strict_types=1);

use Marque\Parley\Contracts\ThreadServiceInterface;
use Marque\Parley\Models\Thread;
use Marque\Parley\Tests\TestUser;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Support\StaffAction;

/**
 * The record is made inside the change's own transaction, so the two stand or
 * fall together: a change rolled back is never recorded, and a store that
 * fails closed (throws, on purpose) takes the change down with it. Shown here
 * with a store that refuses.
 */
it('rolls the change back when the store refuses the record', function () {
    app()->instance(StaffActionsInterface::class, new class implements StaffActionsInterface
    {
        public function record(StaffAction $action): void
        {
            throw new RuntimeException('store refused');
        }
    });

    $thread = Thread::factory()->create(['locked' => false]);
    $moderator = TestUser::factory()->moderator()->create();

    expect(fn () => app(ThreadServiceInterface::class)->lock($thread, $moderator))->toThrow(RuntimeException::class, 'store refused');

    expect((bool) $thread->fresh()->locked)->toBeFalse();
});
