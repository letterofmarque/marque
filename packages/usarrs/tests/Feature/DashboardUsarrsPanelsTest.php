<?php

declare(strict_types=1);

// Build #108 CP4 (Spec #118). The panels usarrs registers for data it owns
// outright: account security and invites. Neither names a tracker.
//
// Both features are off by default and usarrs already gates them per request
// — TwoFactorSetup, PasskeyManagement and InviteIndex all read their flag in
// mount() — so the panels read the same flags the same way, and these tests
// flip them with config()->set(). manage_auth is the exception: it is decided
// at boot, and its half lives in Feature/ManageAuthDisabled.

use Laravel\Passkeys\Passkey;
use Marque\Trove\Registry\DashboardPanelRegistry;
use Marque\Usarrs\Contracts\InviteServiceInterface;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
});

function addPasskey(TestUser $user, string $name = 'Laptop'): Passkey
{
    return $user->passkeys()->create([
        'name' => $name,
        'credential_id' => 'cred-'.$name,
        'credential' => ['type' => 'public-key'],
    ]);
}

it('registers both panels whenever usarrs manages auth', function () {
    $registry = app(DashboardPanelRegistry::class);

    expect($registry->find('usarrs-security'))->not->toBeNull()
        ->and($registry->find('usarrs-invites'))->not->toBeNull();
});

describe('security panel', function () {
    it('is hidden when neither two-factor nor passkeys is enabled', function () {
        // The shipped default. There is no security posture to report, and
        // inventing one to keep the page busy would be worse than the explicit
        // empty state.
        $this->actingAs($this->user)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertDontSee('Account Security')
            ->assertSee('Nothing to show yet');
    });

    it('reports two-factor as off for a user who has not set it up', function () {
        config()->set('usarrs.two_factor.enabled', true);

        $this->actingAs($this->user)
            ->get(route('dashboard.index'))
            ->assertSee('Account Security')
            ->assertSeeInOrder(['Two-factor authentication', 'Off']);
    });

    it('reports two-factor as on once it is confirmed', function () {
        config()->set('usarrs.two_factor.enabled', true);
        $this->user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($this->user)
            ->get(route('dashboard.index'))
            ->assertSeeInOrder(['Two-factor authentication', 'On']);
    });

    it('does not count an enabled-but-unconfirmed secret as on', function () {
        // Fortify writes the secret at enable() and the timestamp at confirm().
        // Between the two, a login is not yet challenged — reporting "On" here
        // would tell the user they are protected when they are not.
        config()->set('usarrs.two_factor.enabled', true);
        $this->user->forceFill(['two_factor_secret' => encrypt('secret')])->save();

        $this->actingAs($this->user)
            ->get(route('dashboard.index'))
            ->assertSeeInOrder(['Two-factor authentication', 'Off']);
    });

    it('counts the user\'s passkeys, and only theirs', function () {
        config()->set('usarrs.passkeys.enabled', true);
        addPasskey($this->user, 'Laptop');
        addPasskey($this->user, 'Phone');
        addPasskey(TestUser::factory()->create(), 'Elsewhere');

        $this->actingAs($this->user)
            ->get(route('dashboard.index'))
            ->assertSee('Account Security')
            ->assertSeeInOrder(['Passkeys', '2']);
    });

    it('omits the two-factor line when only passkeys are enabled', function () {
        config()->set('usarrs.passkeys.enabled', true);

        $this->actingAs($this->user)
            ->get(route('dashboard.index'))
            ->assertSee('Passkeys')
            ->assertDontSee('Two-factor authentication');
    });
});

describe('invites panel', function () {
    it('is hidden when invites are disabled', function () {
        $this->actingAs($this->user)
            ->get(route('dashboard.index'))
            ->assertDontSee('Invites');
    });

    it('shows how many invites the user has left, and links to them', function () {
        config()->set('usarrs.invites.enabled', true);
        config()->set('usarrs.invites.max_per_user', 3);
        app(InviteServiceInterface::class)->create($this->user);

        $this->actingAs($this->user)
            ->get(route('dashboard.index'))
            ->assertSee('Invites')
            ->assertSee('2 of 3 available')
            ->assertSee('1 pending')
            ->assertSee(route('invites.index'))
            ->assertSee(route('invites.create'));
    });

    it('still shows pending invites once the allowance is used up, without offering another', function () {
        config()->set('usarrs.invites.enabled', true);
        config()->set('usarrs.invites.max_per_user', 1);
        app(InviteServiceInterface::class)->create($this->user);

        $this->actingAs($this->user)
            ->get(route('dashboard.index'))
            ->assertSee('0 of 1 available')
            ->assertSee('1 pending')
            ->assertDontSee(route('invites.create'));
    });

    // The per-request half of criterion 6, and the exact case NavItem's
    // docblock cites: whether THIS user has invites is a query.
    it('is hidden for a user with no invites to give and none outstanding', function () {
        config()->set('usarrs.invites.enabled', true);
        config()->set('usarrs.invites.max_per_user', 0);

        $this->actingAs($this->user)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertDontSee('Invites');
    });
});

it('names no tracker package anywhere in the dashboard code', function () {
    // Criterion 4, by grep: usarrs reads tracker data through trove's contract
    // or not at all.
    $paths = [
        ...glob(__DIR__.'/../../src/Livewire/Dashboard/*.php'),
        ...glob(__DIR__.'/../../resources/views/dashboard/*.blade.php'),
        ...glob(__DIR__.'/../../resources/views/dashboard/panels/*.blade.php'),
    ];

    expect($paths)->not->toBeEmpty();

    foreach ($paths as $path) {
        expect(strtolower(file_get_contents($path)))
            ->not->toContain('bloodhound')
            ->not->toContain('ratio_mode');
    }
});
