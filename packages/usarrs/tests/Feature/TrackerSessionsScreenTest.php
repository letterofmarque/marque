<?php

declare(strict_types=1);

// Job #193 (D8): a member sees their live tracker sessions and ends one, so a
// client restarted somewhere else is not refused by the leeching limit until
// the old session expires. usarrs renders; the tracker owns the sessions,
// through trove's TrackerSessionsInterface.

use Livewire\Livewire;
use Marque\Trove\Contracts\TrackerSessionsInterface;
use Marque\Usarrs\Livewire\Profile\SessionManagement;
use Marque\Usarrs\Tests\FakeTrackerSessions;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
    $this->other = TestUser::factory()->create();
});

describe('with no tracker', function () {
    it('answers 404', function () {
        $this->actingAs($this->user)->get(route('profile.sessions'))->assertNotFound();
    });

    it('does not link to sessions from the stats page', function () {
        $this->actingAs($this->user)->get(route('profile.stats'))->assertOk()->assertDontSee(route('profile.sessions'));
    });
});

describe('with a tracker', function () {
    beforeEach(function () {
        app()->singleton(TrackerSessionsInterface::class, FakeTrackerSessions::class);
        $this->sessions = app(TrackerSessionsInterface::class);
    });

    it('lists the member\'s own sessions', function () {
        $this->sessions->add($this->user, 'Big Buck Bunny', seeding: false, ip: '203.0.113.7');
        $this->sessions->add($this->other, 'Not Mine');

        $this->actingAs($this->user)
            ->get(route('profile.sessions'))
            ->assertOk()
            ->assertSee('Big Buck Bunny')
            ->assertSee('203.0.113.7')
            ->assertSee('qBittorrent/4.5.0')
            ->assertSee('Leeching')
            ->assertDontSee('Not Mine');
    });

    it('says so when there are none', function () {
        $this->actingAs($this->user)->get(route('profile.sessions'))->assertOk()->assertSee('No live sessions.');
    });

    it('ends a session through the tracker', function () {
        $session = $this->sessions->add($this->user, 'Big Buck Bunny');

        Livewire::actingAs($this->user)
            ->test(SessionManagement::class)
            ->call('end', $session->id)
            ->assertHasNoErrors();

        expect($this->sessions->sessionsFor($this->user))->toBe([]);
    });

    it('cannot end another member\'s session, and says it is gone', function () {
        $theirs = $this->sessions->add($this->other, 'Theirs');

        Livewire::actingAs($this->user)
            ->test(SessionManagement::class)
            ->call('end', $theirs->id)
            ->assertSee('That session had already ended.');

        expect($this->sessions->sessionsFor($this->other))->toHaveCount(1);
    });

    it('shows the tracker\'s reason when it cannot end one yet', function () {
        $session = $this->sessions->add($this->user, 'Big Buck Bunny');
        $this->sessions->busy = true;

        Livewire::actingAs($this->user)
            ->test(SessionManagement::class)
            ->call('end', $session->id)
            ->assertSee('busy with an announce');

        expect($this->sessions->sessionsFor($this->user))->toHaveCount(1);
    });

    // A seedbox can hold thousands of sessions.
    it('pages a long list', function () {
        foreach (range(1, 51) as $i) {
            $this->sessions->add($this->user, sprintf('Torrent %03d', $i));
        }

        Livewire::actingAs($this->user)
            ->test(SessionManagement::class)
            ->assertSee('Torrent 050')
            ->assertDontSee('Torrent 051')
            ->call('nextPage')
            ->assertSee('Torrent 051');
    });

    it('links to sessions from the stats page', function () {
        $this->actingAs($this->user)->get(route('profile.stats'))->assertOk()->assertSee(route('profile.sessions'));
    });
});
