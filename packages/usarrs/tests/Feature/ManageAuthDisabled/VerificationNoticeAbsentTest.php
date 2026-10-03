<?php

declare(strict_types=1);

// Found by the 8.2.0 docs read-through. With manage_auth off, usarrs registers
// no auth routes, verification.notice included, but the verified-address gates
// (#10879) pointed an unverified user at it: route-not-found instead of a
// refusal.

use Illuminate\Support\ViewErrorBag;
use Marque\Usarrs\Tests\TestUser;

it('refuses an unverified member at the invite form without a verification page to send them to', function () {
    config()->set('usarrs.invites.enabled', true);

    $this->actingAs(TestUser::factory()->unverified()->create())
        ->get(route('invites.create'))
        ->assertForbidden()
        ->assertSee(__('Verify your email address before creating invites.'));
});

it('renders the announce key notice without linking to a verification page that does not exist', function () {
    $html = view('usarrs::partials.announce-key', [
        'announceKey' => null,
        'allowRegen' => true,
        'addressUnproven' => true,
        'errors' => new ViewErrorBag,
    ])->render();

    expect($html)->toContain(__('Verify your email address to get an announce key.'))
        ->not->toContain(__('Resend the verification email'));
});
