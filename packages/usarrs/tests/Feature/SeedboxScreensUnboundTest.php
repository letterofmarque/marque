<?php

declare(strict_types=1);

// Job #193: with no tracker that measures speeds, there are no seedboxes to
// manage. The routes exist on every install (a link to them must never be
// fatal) and answer 404; nothing advertises them.

use Marque\Trove\Registry\AdminScreenRegistry;
use Marque\Usarrs\Tests\TestUser;

beforeEach(function () {
    $this->user = TestUser::factory()->create();
    $this->admin = TestUser::factory()->admin()->create();
});

it('answers 404 for the member page', function () {
    $this->actingAs($this->user)->get(route('profile.seedboxes'))->assertNotFound();
});

it('answers 404 for the admin page', function () {
    $this->actingAs($this->admin)->get(route('admin.seedboxes.index'))->assertNotFound();
});

it('advertises no seedbox admin screen', function () {
    expect(collect(app(AdminScreenRegistry::class)->all())->pluck('identifier'))->not->toContain('usarrs-seedboxes');
});

it('does not link to seedboxes from the stats page', function () {
    $this->actingAs($this->user)->get(route('profile.stats'))->assertOk()->assertDontSee(route('profile.seedboxes'));
});
