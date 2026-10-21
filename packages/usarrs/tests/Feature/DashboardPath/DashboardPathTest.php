<?php

declare(strict_types=1);

// #11070 item 1: an app that keeps its own /dashboard moves usarrs' instead.

use Illuminate\Support\Facades\Log;
use Marque\Usarrs\Tests\TestUser;
use Marque\Usarrs\UsarrsServiceProvider;

it('serves the dashboard at the configured path, under the same name', function () {
    expect(route('dashboard.index', absolute: false))->toBe('/members/home');

    $this->actingAs(TestUser::factory()->create())
        ->get('/members/home')
        ->assertOk();
});

it('leaves /dashboard to the app', function () {
    $this->actingAs(TestUser::factory()->create())
        ->get('/dashboard')
        ->assertNotFound();
});

it('reports no collision at the configured path', function () {
    Log::spy();

    (new UsarrsServiceProvider(app()))->reportDashboardCollision();

    Log::shouldNotHaveReceived('error');
});
