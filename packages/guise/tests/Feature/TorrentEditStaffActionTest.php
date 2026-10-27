<?php

declare(strict_types=1);

use Livewire\Livewire;
use Marque\Guise\Livewire\Torrent\Edit;
use Marque\Guise\Tests\TestUser;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Support\StaffAction;

/**
 * The edit screen passes the editor to trove, so a moderator's edit of
 * someone else's torrent is recorded and an uploader's own edit isn't.
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

    $this->uploader = TestUser::factory()->create();
    $this->torrent = Torrent::create(['info_hash' => str_repeat('a', 40), 'name' => 'Original', 'user_id' => $this->uploader->id]);
});

it('records a moderator editing someone else\'s torrent', function () {
    $moderator = TestUser::factory()->create(['role' => 'moderator']);

    Livewire::actingAs($moderator)
        ->test(Edit::class, ['torrent' => $this->torrent])
        ->set('name', 'Moderated')
        ->call('save');

    expect($this->store->recorded)->toHaveCount(1)
        ->and($this->store->recorded[0]->action)->toBe('trove.torrent.edited')
        ->and($this->store->recorded[0]->actorId)->toBe($moderator->id);
});

it('records nothing when the uploader edits their own', function () {
    Livewire::actingAs($this->uploader)
        ->test(Edit::class, ['torrent' => $this->torrent])
        ->set('name', 'Renamed')
        ->call('save');

    expect($this->torrent->fresh()->name)->toBe('Renamed')
        ->and($this->store->recorded)->toBe([]);
});
