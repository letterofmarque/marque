<?php

declare(strict_types=1);

// #11179. With invites on, every signed-in member could create them, and
// admins and the owner got the same three as everyone. Nobody decided that
// ("sure as shit WASNT me", Dan). Invites are how a private tracker controls
// who joins, so by default only staff (admin and owner) create them, staff
// have no allowance cap, and InvitePolicy::create() is the one gate the page,
// action, button and dashboard panel all follow.

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Marque\Usarrs\Livewire\Invite\InviteCreate;
use Marque\Usarrs\Models\Invite;
use Marque\Usarrs\Services\InviteService;
use Marque\Usarrs\Tests\TestUser;

beforeEach(fn () => config()->set('usarrs.invites.enabled', true));

function memberWithRole(string $role): TestUser
{
    return TestUser::factory()->create(['role' => $role]);
}

/** One request as $role. auth.session signs a second member out against the first's password hash, so each starts clean. */
function createPageAs(mixed $test, string $role): mixed
{
    $test->flushSession();

    return $test->actingAs(memberWithRole($role))->get(route('invites.create'));
}

function createInviteAs(TestUser $user): mixed
{
    return Livewire::actingAs($user)->test(InviteCreate::class)->call('create');
}

describe('who may create, by default', function () {
    it('refuses a plain user, an uploader and a moderator the create page', function (string $role) {
        $this->actingAs(memberWithRole($role))->get(route('invites.create'))->assertForbidden();
    })->with(['user', 'uploader', 'moderator']);

    it('lets an admin and the owner create one', function (string $role) {
        $staff = memberWithRole($role);

        createInviteAs($staff)->assertHasNoErrors()->assertRedirect(route('invites.index'));

        expect(Invite::where('creator_id', $staff->id)->count())->toBe(1);
    })->with(['admin', 'owner']);

    it('makes no invite when the action is called by someone the policy refuses', function () {
        // A form opened as an admin, submitted after a demotion to user.
        $member = memberWithRole('admin');
        $component = Livewire::actingAs($member)->test(InviteCreate::class);
        $member->update(['role' => 'user']);

        $component->call('create')->assertForbidden();

        expect(Invite::count())->toBe(0);
    });

    it('answers the policy the same way the page does', function () {
        expect(Gate::forUser(memberWithRole('moderator'))->allows('create', Invite::class))->toBeFalse()
            ->and(Gate::forUser(memberWithRole('admin'))->allows('create', Invite::class))->toBeTrue();
    });
});

describe('invites.min_role', function () {
    it('lets every member invite when set to user', function () {
        config()->set('usarrs.invites.min_role', 'user');

        createInviteAs(memberWithRole('user'))->assertHasNoErrors();

        expect(Invite::count())->toBe(1);
    });

    it('admits uploaders and above, and nobody below, when set to uploader', function () {
        config()->set('usarrs.invites.min_role', 'uploader');

        createPageAs($this, 'user')->assertForbidden();
        createPageAs($this, 'uploader')->assertOk();
        createPageAs($this, 'moderator')->assertOk();
    });

    it('lets only the owner create when the value is not a role', function () {
        config()->set('usarrs.invites.min_role', 'staff');

        createPageAs($this, 'admin')->assertForbidden();
        createPageAs($this, 'owner')->assertOk();
    });
});

describe('a verified address', function () {
    it('is required by the policy itself, even for an admin', function () {
        $unverified = TestUser::factory()->unverified()->create(['role' => 'admin']);

        expect(Gate::forUser($unverified)->allows('create', Invite::class))->toBeFalse();
        expect(app(InviteService::class)->canCreateInvite($unverified))->toBeFalse();
    });
});

describe('allowances', function () {
    it('gives admins and the owner no limit by default, while members keep max_per_user', function () {
        config()->set('usarrs.invites.min_role', 'user');
        config()->set('usarrs.invites.max_per_user', 1);
        $service = app(InviteService::class);

        $admin = memberWithRole('admin');
        $owner = memberWithRole('owner');
        $member = memberWithRole('user');
        foreach (range(1, 5) as $_) {
            $service->create($admin);
            $service->create($owner);
        }
        $service->create($member);

        expect($service->canCreateInvite($admin))->toBeTrue()
            ->and($service->canCreateInvite($owner))->toBeTrue()
            ->and($service->allowanceFor($admin))->toBeNull()
            ->and($service->canCreateInvite($member))->toBeFalse();
    });

    it('enforces a number configured for a role', function () {
        config()->set('usarrs.invites.min_role', 'moderator');
        config()->set('usarrs.invites.max_per_role', ['moderator' => 2, 'admin' => null, 'owner' => null]);
        $moderator = memberWithRole('moderator');

        createInviteAs($moderator)->assertHasNoErrors();
        createInviteAs($moderator)->assertHasNoErrors();

        $this->actingAs($moderator)->get(route('invites.create'))->assertForbidden();
        expect(Invite::where('creator_id', $moderator->id)->count())->toBe(2);
    });

    it('gives a member the entry for the highest listed role they outrank', function () {
        // Only admin listed: the owner outranks it and takes its entry.
        config()->set('usarrs.invites.max_per_role', ['admin' => 4]);

        expect(app(InviteService::class)->allowanceFor(memberWithRole('owner')))->toBe(4)
            ->and(app(InviteService::class)->allowanceFor(memberWithRole('moderator')))->toBe(3);
    });
});

describe('a config published before 11.0', function () {
    it('still gates by role and leaves staff unlimited, though its invites block has neither key', function () {
        // mergeConfigFrom is top-level only: a published `invites` block
        // replaces the package's whole, without min_role or max_per_role.
        config()->set('usarrs.invites', ['enabled' => true, 'required' => false, 'max_per_user' => 1, 'expiry_days' => 7]);
        $service = app(InviteService::class);

        $admin = memberWithRole('admin');
        $service->create($admin);
        $service->create($admin);

        expect($service->canCreateInvite(memberWithRole('user')))->toBeFalse()
            ->and($service->canCreateInvite($admin))->toBeTrue();
    });
});

describe('the UI follows the policy', function () {
    it('shows a plain member under the default no Invites panel and no create button', function () {
        $member = memberWithRole('user');

        $this->actingAs($member)->get(route('dashboard.index'))->assertOk()->assertDontSee('Send an invite');
        $this->actingAs($member)->get(route('invites.index'))->assertOk()->assertDontSee('Create Invite');
    });

    it('shows an admin the panel with no limit and the create button', function () {
        $admin = memberWithRole('admin');

        $this->actingAs($admin)->get(route('dashboard.index'))->assertOk()->assertSee('No limit')->assertSee('Send an invite');
        $this->actingAs($admin)->get(route('invites.index'))->assertOk()->assertSee('Create Invite');
    });

    it('still shows a demoted member their outstanding invites, without the button', function () {
        $member = memberWithRole('admin');
        app(InviteService::class)->create($member);
        $member->update(['role' => 'user']);

        $this->actingAs($member)->get(route('dashboard.index'))->assertOk()->assertSee('1 pending')->assertDontSee('Send an invite');
        $this->actingAs($member)->get(route('invites.index'))->assertOk()->assertDontSee('Create Invite');
    });
});
