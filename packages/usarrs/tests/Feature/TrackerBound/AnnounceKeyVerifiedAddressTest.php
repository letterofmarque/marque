<?php

declare(strict_types=1);

// Issue #10879: no announce key for an unverified address. bloodhound holds the
// key back at sign-up and issues it on verification; this is usarrs' side, the
// generate/regenerate action, and what the page shows a user who has no key.

use Livewire\Livewire;
use Marque\Trove\Contracts\TrackerStatsInterface;
use Marque\Usarrs\Livewire\Dashboard\AnnounceKeyPanel;
use Marque\Usarrs\Livewire\Profile\AnnounceKeyManagement;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    $this->tracker = app(TrackerStatsInterface::class);
});

it('issues no key to an unverified account, from the stats page or the dashboard', function (string $component) {
    $user = TestUser::factory()->unverified()->create();

    Livewire::actingAs($user)->test($component)
        ->call('regenerateAnnounceKey')
        ->assertHasErrors('email');

    expect($this->tracker->regenerated)->toBe([]);
})->with(['stats page' => [AnnounceKeyManagement::class], 'dashboard panel' => [AnnounceKeyPanel::class]]);

it('tells an unverified account to verify for a key', function () {
    $this->actingAs(TestUser::factory()->unverified()->create())
        ->get(route('profile.stats'))
        ->assertSee(__('Verify your email address to get an announce key.'));
});

it('offers a verified account with no key a way to get one', function () {
    $user = TestUser::factory()->create();

    $this->actingAs($user)->get(route('profile.stats'))->assertSee(__('Generate announce key'));

    Livewire::actingAs($user)->test(AnnounceKeyManagement::class)->call('regenerateAnnounceKey');

    expect($this->tracker->regenerated)->toBe([$user->id]);
});
