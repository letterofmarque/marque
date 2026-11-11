<?php

declare(strict_types=1);

// Job #355 step 2. A passkey signs in on its own, so a member who has one can
// remove their only provider connection: it isn't their last way in.

use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Marque\Usarrs\Livewire\Profile\ConnectedAccounts;
use Marque\Usarrs\Models\SocialAccount;
use Marque\Usarrs\Tests\SoftAuthenticator;
use Marque\Usarrs\Tests\TestUser;

it('lets a member with a passkey remove their only connection', function () {
    Notification::fake();
    config(['usarrs.socialite_providers' => ['github']]);
    $user = TestUser::factory()->create();
    SocialAccount::forceCreate(['user_id' => $user->getKey(), 'provider' => 'github', 'provider_user_id' => 'gh-1']);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    $options = $this->getJson('/user/passkeys/options')->assertOk()->json('options');
    $this->postJson('/user/passkeys', ['name' => 'Laptop', 'credential' => (new SoftAuthenticator)->register($options)])->assertSuccessful();

    Livewire::actingAs($user)->test(ConnectedAccounts::class)
        ->call('disconnect', 'github')
        ->assertHasNoErrors();

    expect(SocialAccount::resolve('github', 'gh-1'))->toBeNull();
});
