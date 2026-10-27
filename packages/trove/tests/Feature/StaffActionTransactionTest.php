<?php

declare(strict_types=1);

use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Services\TorrentService;
use Marque\Trove\Support\StaffAction;
use Marque\Trove\Tests\TestUser;

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

    $uploader = TestUser::factory()->uploader()->create();
    $moderator = TestUser::factory()->moderator()->create();
    $torrent = Torrent::factory()->create(['user_id' => $uploader->id, 'name' => 'Original']);

    expect(fn () => app(TorrentService::class)->update($torrent, ['name' => 'Changed'], $moderator))->toThrow(RuntimeException::class, 'store refused');
    expect(Torrent::query()->find($torrent->id)?->name)->toBe('Original');

    expect(fn () => app(TorrentService::class)->delete(Torrent::query()->find($torrent->id), $moderator))->toThrow(RuntimeException::class, 'store refused');
    expect(Torrent::query()->find($torrent->id))->not->toBeNull();
});
