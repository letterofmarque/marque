<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Marque\Cennad\Tests\TestUser;

// The limiter reads cennad.rate_limit per request, so these set it at runtime.
// Only the middleware lists are bound at route registration.

beforeEach(function () {
    $this->user = TestUser::factory()->create();
});

describe('API rate limit', function () {
    test('refuses requests past rate_limit per minute with a 429', function () {
        config(['cennad.rate_limit' => 2]);
        $this->actingAs($this->user);

        $this->getJson('/api/torrents')->assertOk();
        $this->getJson('/api/torrents')->assertOk();
        $this->getJson('/api/torrents')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
    });

    test('defaults to 60 a minute, and says so in the response headers', function () {
        $this->actingAs($this->user);

        $this->getJson('/api/torrents')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining', '59');
    });

    test('counts writes against the same limit as reads', function () {
        config(['cennad.rate_limit' => 1]);
        $this->actingAs($this->user);

        $this->getJson('/api/torrents')->assertOk();
        $this->postJson('/api/torrents', [])->assertTooManyRequests();
    });

    test('counts each user separately', function () {
        config(['cennad.rate_limit' => 1]);
        $other = TestUser::factory()->create();

        $this->actingAs($this->user)->getJson('/api/torrents')->assertOk();
        $this->actingAs($this->user)->getJson('/api/torrents')->assertTooManyRequests();

        $this->actingAs($other)->getJson('/api/torrents')->assertOk();
    });

    test('counts guests by IP when reads are open', function () {
        $this->rebootWithConfig([
            'cennad.read_middleware' => ['api'],
            'cennad.rate_limit' => 1,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1']);

        $this->getJson('/api/torrents')->assertOk();
        $this->getJson('/api/torrents')->assertTooManyRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])
            ->getJson('/api/torrents')
            ->assertOk();
    });

    test('can be replaced by an app redefining the cennad limiter', function () {
        RateLimiter::for('cennad', fn () => Limit::perMinute(1));
        $this->actingAs($this->user);

        $this->getJson('/api/torrents')->assertOk();
        $this->getJson('/api/torrents')->assertTooManyRequests();
    });

    test('is off when rate_limit is null or 0', function (?int $limit) {
        config(['cennad.rate_limit' => $limit]);
        $this->actingAs($this->user);

        foreach (range(1, 5) as $_) {
            $this->getJson('/api/torrents')->assertOk();
        }
    })->with(['null' => [null], 'zero' => [0]]);
});
