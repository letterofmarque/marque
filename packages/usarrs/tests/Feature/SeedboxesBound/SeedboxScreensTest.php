<?php

declare(strict_types=1);

// Job #193: members register and remove seedboxes, admins approve and revoke
// them, through trove's SeedboxesInterface. usarrs renders; the tracker owns
// the data and the rules (what a valid range is, what approval means).

use Livewire\Livewire;
use Marque\Trove\Contracts\SeedboxesInterface;
use Marque\Trove\Registry\AdminScreenRegistry;
use Marque\Trove\Support\Seedbox;
use Marque\Usarrs\Livewire\Admin\SeedboxIndex;
use Marque\Usarrs\Livewire\Profile\SeedboxManagement;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
    $this->other = TestUser::factory()->create();
    $this->admin = TestUser::factory()->admin()->create();
    $this->seedboxes = app(SeedboxesInterface::class);
});

describe('the member page', function () {
    it('lists the member\'s own seedboxes and their status', function () {
        $this->seedboxes->register($this->user, '203.0.113.7/32', 'Hetzner');
        $this->seedboxes->register($this->other, '198.51.100.9/32', 'Theirs');

        $this->actingAs($this->user)
            ->get(route('profile.seedboxes'))
            ->assertOk()
            ->assertSee('203.0.113.7/32')
            ->assertSee('Hetzner')
            ->assertSee('Waiting for approval')
            ->assertDontSee('198.51.100.9/32');
    });

    it('registers a seedbox through the tracker', function () {
        Livewire::actingAs($this->user)
            ->test(SeedboxManagement::class)
            ->set('network', '203.0.113.7')
            ->set('label', 'Box')
            ->call('register')
            ->assertHasNoErrors()
            ->assertSee('203.0.113.7');

        expect($this->seedboxes->forUser($this->user))->toHaveCount(1)
            ->and($this->seedboxes->forUser($this->user)[0]->label)->toBe('Box');
    });

    it('shows the tracker\'s reason when it refuses a network', function () {
        Livewire::actingAs($this->user)
            ->test(SeedboxManagement::class)
            ->set('network', 'nonsense')
            ->call('register')
            ->assertHasErrors('network')
            ->assertSee('is not an IP address or range');
    });

    it('removes one of the member\'s own', function () {
        $seedbox = $this->seedboxes->register($this->user, '203.0.113.7/32');

        Livewire::actingAs($this->user)
            ->test(SeedboxManagement::class)
            ->call('remove', $seedbox->id);

        expect($this->seedboxes->forUser($this->user))->toBe([]);
    });

    it('cannot remove another member\'s', function () {
        $theirs = $this->seedboxes->register($this->other, '198.51.100.9/32');

        Livewire::actingAs($this->user)
            ->test(SeedboxManagement::class)
            ->call('remove', $theirs->id)
            ->assertNotFound();

        expect($this->seedboxes->forUser($this->other))->toHaveCount(1);
    });

    it('is linked from the stats page', function () {
        $this->actingAs($this->user)->get(route('profile.stats'))->assertOk()->assertSee(route('profile.seedboxes'));
    });
});

describe('the admin page', function () {
    it('shows pending seedboxes by default, with their member', function () {
        $this->seedboxes->register($this->user, '203.0.113.7/32');
        $this->seedboxes->approve($this->seedboxes->register($this->other, '198.51.100.9/32')->id, $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.seedboxes.index'))
            ->assertOk()
            ->assertSee('203.0.113.7/32')
            ->assertDontSee('198.51.100.9/32');
    });

    it('approves and revokes, as the signed-in admin', function () {
        $seedbox = $this->seedboxes->register($this->user, '203.0.113.7/32');

        Livewire::actingAs($this->admin)->test(SeedboxIndex::class)->call('approve', $seedbox->id);

        expect($this->seedboxes->all(Seedbox::APPROVED)[0]->approvedBy)->toBe($this->admin->id);

        Livewire::actingAs($this->admin)->test(SeedboxIndex::class)->set('status', 'approved')->call('revoke', $seedbox->id);

        expect($this->seedboxes->all(Seedbox::REVOKED))->toHaveCount(1);
    });

    it('is for admins only', function () {
        $this->actingAs($this->user)->get(route('admin.seedboxes.index'))->assertForbidden();

        Livewire::actingAs($this->user)->test(SeedboxIndex::class)->assertForbidden();
    });

    it('is advertised to the admin panel', function () {
        expect(collect(app(AdminScreenRegistry::class)->all())->pluck('identifier'))->toContain('usarrs-seedboxes');
    });
});
