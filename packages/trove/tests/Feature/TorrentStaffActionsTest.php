<?php

declare(strict_types=1);

use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Services\TorrentService;
use Marque\Trove\Support\StaffAction;
use Marque\Trove\Tests\TestUser;

/**
 * A torrent edited or deleted by someone who isn't its uploader is a staff
 * action, and is recorded. An uploader editing their own isn't.
 */
beforeEach(function () {
    $this->store = new class implements StaffActionsInterface
    {
        /** @var list<StaffAction> */
        public array $recorded = [];

        public function record(StaffAction $action): void
        {
            $this->recorded[] = $action;
        }
    };
    app()->instance(StaffActionsInterface::class, $this->store);

    $this->service = app(TorrentService::class);
    $this->uploader = TestUser::factory()->uploader()->create();
    $this->moderator = TestUser::factory()->moderator()->create();
    $this->torrent = Torrent::factory()->create(['user_id' => $this->uploader->id, 'name' => 'Eagles at Cowboys', 'description' => 'Week 12']);
});

it('records a moderator editing someone else\'s torrent, with only the fields that moved', function () {
    $this->service->update($this->torrent, ['name' => 'Eagles at Cowboys (2006)', 'description' => 'Week 12'], $this->moderator, 'Season missing');

    expect($this->store->recorded)->toHaveCount(1);

    $action = $this->store->recorded[0];

    expect($action->action)->toBe('trove.torrent.edited')
        ->and($action->actorId)->toBe($this->moderator->id)
        ->and($action->subjectType)->toBe($this->torrent->getMorphClass())
        ->and($action->subjectId)->toBe($this->torrent->id)
        ->and($action->reason)->toBe('Season missing')
        ->and($action->changes)->toBe(['name' => ['from' => 'Eagles at Cowboys', 'to' => 'Eagles at Cowboys (2006)']]);
});

it('records nothing when the uploader edits their own', function () {
    $this->service->update($this->torrent, ['name' => 'Renamed'], $this->uploader);

    expect($this->torrent->fresh()->name)->toBe('Renamed')
        ->and($this->store->recorded)->toBe([]);
});

it('records nothing for an edit that changed nothing', function () {
    $this->service->update($this->torrent, ['name' => 'Eagles at Cowboys'], $this->moderator);

    expect($this->store->recorded)->toBe([]);
});

it('records a moderator deleting someone else\'s torrent, keeping its name', function () {
    $id = $this->torrent->id;

    $this->service->delete($this->torrent, $this->moderator, 'Fake');

    expect(Torrent::query()->find($id))->toBeNull()
        ->and($this->store->recorded)->toHaveCount(1)
        ->and($this->store->recorded[0]->action)->toBe('trove.torrent.deleted')
        ->and($this->store->recorded[0]->subjectId)->toBe($id)
        ->and($this->store->recorded[0]->reason)->toBe('Fake')
        ->and($this->store->recorded[0]->changes)->toBe(['name' => ['from' => 'Eagles at Cowboys', 'to' => null]]);
});

it('records nothing with no actor', function () {
    $this->service->update($this->torrent, ['name' => 'Renamed']);
    $this->service->delete($this->torrent);

    expect($this->store->recorded)->toBe([]);
});
