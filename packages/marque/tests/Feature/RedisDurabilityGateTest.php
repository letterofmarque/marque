<?php

declare(strict_types=1);

namespace Marque\Marque\Tests\Feature;

use Marque\Marque\Install\EnvironmentCheck;
use Marque\Marque\Install\EnvironmentReport;
use Marque\Marque\Install\RedisInfo;
use Marque\Marque\Tests\TestCase;
use RuntimeException;

/**
 * Job #193 (bloodhound 8.0 design, addendum A5): a private tracker credits
 * every announce against peer state held in Redis, so the installer will not
 * build one on a Redis that can forget it. Checked before anything is
 * installed, so fixing it costs one re-run.
 */
final class RedisDurabilityGateTest extends TestCase
{
    /**
     * @param  array<string, string>|null  $persistence  null: INFO refused
     * @param  array<string, string>|null  $memory
     */
    private function redisSays(?array $persistence, ?array $memory): void
    {
        $this->app->instance(RedisInfo::class, new class($persistence, $memory) extends RedisInfo
        {
            public function __construct(private ?array $persistence, private ?array $memory) {}

            public function section(string $section): array
            {
                $answer = $section === 'persistence' ? $this->persistence : $this->memory;

                return $answer ?? throw new RuntimeException('INFO is not permitted');
            }
        });
    }

    private function gate(bool $private): EnvironmentReport
    {
        $check = $this->app->make(EnvironmentCheck::class);

        return $check->runConditional($check->runUnconditional(), needsRedis: true, needsDurableRedis: $private);
    }

    public function test_a_private_tracker_on_a_redis_that_can_forget_is_refused(): void
    {
        $this->redisSays(['aof_enabled' => '0'], ['maxmemory_policy' => 'allkeys-lru']);

        $report = $this->gate(private: true);

        $this->assertFalse($report->passed('redis-durability'));
        $this->assertTrue($report->isFatal());
        $message = implode(' ', array_column($report->messagesFor(['redis-durability']), 'text'));
        $this->assertStringContainsString('appendonly yes', $message);
        $this->assertStringContainsString('noeviction', $message);
    }

    public function test_a_durable_redis_passes(): void
    {
        $this->redisSays(['aof_enabled' => '1'], ['maxmemory_policy' => 'noeviction']);

        $this->assertTrue($this->gate(private: true)->passed('redis-durability'));
    }

    public function test_attestation_covers_persistence_but_not_eviction(): void
    {
        config(['marque.install.redis_durability' => 'attested']);

        $this->redisSays(['aof_enabled' => '0'], ['maxmemory_policy' => 'noeviction']);
        $this->assertTrue($this->gate(private: true)->passed('redis-durability'));

        $this->redisSays(['aof_enabled' => '0'], ['maxmemory_policy' => 'allkeys-lru']);
        $this->assertFalse($this->gate(private: true)->passed('redis-durability'));

        $this->redisSays(null, null);
        $this->assertTrue($this->gate(private: true)->passed('redis-durability'));
    }

    // #11061: read through config, so it holds with config cached, when
    // nothing reads .env and env() answers null.
    public function test_the_attestation_is_read_from_config_not_the_environment(): void
    {
        putenv('BLOODHOUND_REDIS_DURABILITY');
        config(['marque.install.redis_durability' => 'attested']);

        $this->redisSays(['aof_enabled' => '0'], ['maxmemory_policy' => 'noeviction']);

        $this->assertTrue($this->gate(private: true)->passed('redis-durability'));
    }

    public function test_the_installer_ships_the_key_backed_by_the_environment(): void
    {
        $this->assertSame('required', config('marque.install.redis_durability'));

        putenv('BLOODHOUND_REDIS_DURABILITY=attested');

        try {
            $config = require __DIR__.'/../../config/marque.php';
            $this->assertSame('attested', $config['install']['redis_durability']);
        } finally {
            putenv('BLOODHOUND_REDIS_DURABILITY');
        }
    }

    // A re-run on an install that already has bloodhound: its config is the
    // one the tracker will act on.
    public function test_bloodhounds_own_setting_wins_once_it_is_installed(): void
    {
        config(['bloodhound.redis.durability' => 'attested', 'marque.install.redis_durability' => 'required']);
        $this->redisSays(['aof_enabled' => '0'], ['maxmemory_policy' => 'noeviction']);
        $this->assertTrue($this->gate(private: true)->passed('redis-durability'));

        config(['bloodhound.redis.durability' => 'required', 'marque.install.redis_durability' => 'attested']);
        $this->assertFalse($this->gate(private: true)->passed('redis-durability'));
    }

    public function test_a_public_tracker_is_not_held_to_it(): void
    {
        // hound credits nobody anything, so nothing is lost when Redis forgets.
        $this->redisSays(['aof_enabled' => '0'], ['maxmemory_policy' => 'allkeys-lru']);

        $this->assertFalse($this->gate(private: false)->checked('redis-durability'));
    }
}
