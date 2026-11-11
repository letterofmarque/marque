<?php

declare(strict_types=1);

// Job #355 step 4 (#10880). Setting or changing a password, or changing the
// email address, on /profile/edit needed no step-up: a session that wasn't the
// member's could take the account's address or set a password that outlived
// them. Both now take a confirmed step-up, which every driver can give (step 1).

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Marque\Usarrs\Livewire\Profile\Edit;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    Notification::fake();
    $this->user = TestUser::factory()->create(['name' => 'Old', 'email' => 'old@example.com', 'password' => Hash::make('old-password')]);
});

it('sends an unconfirmed member to confirm before changing the email, and saves nothing', function () {
    Livewire::actingAs($this->user)->test(Edit::class)
        ->set('email', 'new@example.com')
        ->set('name', 'New')
        ->call('save')
        ->assertRedirect(route('password.confirm'));

    expect($this->user->fresh()->email)->toBe('old@example.com')
        ->and($this->user->fresh()->name)->toBe('Old')
        ->and(session('url.intended'))->toBe(route('profile.edit'));
});

it('sends an unconfirmed member to confirm before setting a password, and saves nothing', function () {
    Livewire::actingAs($this->user)->test(Edit::class)
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('save')
        ->assertRedirect(route('password.confirm'));

    expect(Hash::check('old-password', $this->user->fresh()->password))->toBeTrue();
});

it('changes the email and password for a confirmed member', function () {
    session()->put('auth.password_confirmed_at', time());

    Livewire::actingAs($this->user)->test(Edit::class)
        ->set('email', 'new@example.com')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('save')
        ->assertRedirect(route('profile.show'));

    expect($this->user->fresh()->email)->toBe('new@example.com')
        ->and(Hash::check('new-password', $this->user->fresh()->password))->toBeTrue();
});

it('needs no step-up to change only the name or bio', function () {
    Livewire::actingAs($this->user)->test(Edit::class)
        ->set('name', 'New')
        ->set('bio', 'Hello')
        ->call('save')
        ->assertRedirect(route('profile.show'));

    expect($this->user->fresh()->name)->toBe('New');
});
