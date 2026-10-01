<?php

declare(strict_types=1);

// Build #124 CP #777. usarrs ends a squatter's sessions by rotating the account's
// password hash under `auth.session` (CP #776). Two holes in that:
//
// - Livewire's update endpoint re-applies only a fixed list of "persistent"
//   middleware, and Laravel's AuthenticateSession wasn't on it. An open tab
//   kept working after the hash rotated — the squatter changed the account's
//   email from one.
// - Changing your own password rotates the hash too, and nothing re-recorded it
//   in *this* session, so the next page signed you out.
//
// Livewire::test() deliberately skips persistent middleware, so the first is
// tested against the real update endpoint with the page's own snapshot.

use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Marque\Usarrs\Livewire\Profile\Edit;
use Marque\Usarrs\Tests\TestUser;

it('refuses a Livewire action from a session whose password hash has rotated', function () {
    $user = TestUser::factory()->create(['name' => 'Owner']);
    $this->actingAs($user);

    $html = $this->get(route('profile.edit'))->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $match);
    $snapshot = html_entity_decode($match[1], ENT_QUOTES);

    // Rotated underneath this session — as proving the inbox does.
    $user->forceFill(['password' => Hash::make('rotated-elsewhere')])->save();

    $this->withHeaders(['X-Livewire' => '1'])->postJson(EndpointResolver::updatePath(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => ['name' => 'Hijacked'],
            'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
        ]],
    ]);

    expect($user->fresh()->name)->toBe('Owner');
});

it('keeps you signed in after changing your own password', function () {
    $user = TestUser::factory()->create();
    $this->actingAs($user);
    $this->get(route('profile.show'))->assertOk(); // the session records its hash

    Livewire::test(Edit::class)
        ->set('password', 'a-new-password')
        ->set('password_confirmation', 'a-new-password')
        ->call('save')
        ->assertHasNoErrors();

    expect(Hash::check('a-new-password', $user->fresh()->password))->toBeTrue();
    $this->get(route('profile.show'))->assertOk();
});

it('keeps you signed in after changing your password through the real update endpoint', function () {
    $user = TestUser::factory()->create();
    $this->actingAs($user);

    $html = $this->get(route('profile.edit'))->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $match);

    $this->withHeaders(['X-Livewire' => '1'])->postJson(EndpointResolver::updatePath(), [
        'components' => [[
            'snapshot' => html_entity_decode($match[1], ENT_QUOTES),
            'updates' => ['password' => 'a-new-password', 'password_confirmation' => 'a-new-password'],
            'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
        ]],
    ])->assertOk();

    expect(Hash::check('a-new-password', $user->fresh()->password))->toBeTrue();
    $this->get(route('profile.show'))->assertOk();
});
