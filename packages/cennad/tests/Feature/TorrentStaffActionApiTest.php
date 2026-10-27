<?php

declare(strict_types=1);

use Marque\Cennad\Tests\TestUser;
use Marque\Trove\Contracts\StaffActionsInterface;
use Marque\Trove\Models\Torrent;
use Marque\Trove\Support\StaffAction;

/**
 * The API passes the caller to trove, so a moderator editing or deleting
 * someone else's torrent through it is recorded, as on the web.
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
    $this->moderator = TestUser::factory()->moderator()->create();
    $this->torrent = Torrent::factory()->for($this->uploader, 'user')->create(['name' => 'Original']);
});

it('records a moderator\'s edit and delete of someone else\'s torrent', function () {
    $this->actingAs($this->moderator);

    $this->putJson("/api/torrents/{$this->torrent->id}", ['name' => 'Moderated'])->assertOk();
    $this->deleteJson("/api/torrents/{$this->torrent->id}")->assertNoContent();

    expect(array_map(fn (StaffAction $a): string => $a->action, $this->store->recorded))
        ->toBe(['trove.torrent.edited', 'trove.torrent.deleted'])
        ->and($this->store->recorded[1]->actorId)->toBe($this->moderator->id);
});

it('records nothing when the uploader edits their own', function () {
    $this->actingAs($this->uploader);

    $this->putJson("/api/torrents/{$this->torrent->id}", ['name' => 'Renamed'])->assertOk();

    expect($this->store->recorded)->toBe([]);
});
