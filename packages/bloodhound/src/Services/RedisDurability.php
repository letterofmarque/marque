<?php

declare(strict_types=1);

namespace Marque\Bloodhound\Services;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Marque\Bloodhound\Models\AnnounceLog;
use Throwable;

/**
 * Is the Redis that holds the swarm keeping what it holds?
 *
 * Every announce is credited against the peer's last counters, kept in Redis
 * (Job #193). That makes Redis a store, not a cache: it must persist (AOF)
 * and must never evict (noeviction), or a restart or a full memory silently
 * costs members their credit. This checks both, from INFO, which managed
 * services allow where they refuse CONFIG.
 *
 * And if Redis is lost anyway, it notices (a sentinel key gone while the
 * ledger shows recent announces) and opens a recovery window, during which a
 * peer Redis does not know is looked up in the ledger instead of being
 * credited from nothing. Outside that window the ledger is never read on the
 * announce path.
 */
class RedisDurability
{
    /** Seconds a verdict on the announce path is cached. */
    private const CACHE_SECONDS = 300;

    /** Seconds a failing verdict is cached before INFO is asked again. */
    private const RETRY_SECONDS = 15;

    public function verify(): DurabilityVerdict
    {
        $attested = config('bloodhound.redis.durability', 'required') === 'attested';
        $problems = [];
        $warnings = [];

        $aof = $this->read('persistence', 'aof_enabled');
        $policy = $this->read('memory', 'maxmemory_policy');

        if ($aof !== '1' && ! $attested) {
            $problems[] = $aof === null
                ? 'Redis refused INFO, so persistence cannot be checked. Set appendonly yes, or BLOODHOUND_REDIS_DURABILITY=attested if your provider makes the data durable another way.'
                : 'Persistence is off. Set appendonly yes (with appendfsync everysec), or BLOODHOUND_REDIS_DURABILITY=attested if your provider makes the data durable another way.';
        }

        // Attestation covers persistence only. An evicted peer record is a
        // silently lost baseline, and every provider lets the policy be set.
        if ($policy !== null && $policy !== 'noeviction') {
            $problems[] = "The eviction policy is {$policy}. Set maxmemory-policy noeviction: an evicted peer record loses that member's credit.";
        } elseif ($policy === null && ! $attested) {
            $problems[] = 'Redis refused INFO, so the eviction policy cannot be checked. Set maxmemory-policy noeviction.';
        }

        if ($this->sharesItsStore()) {
            $warnings[] = 'The tracker shares its Redis database with the cache, queue or sessions. A flush aimed at any of them takes the swarm with it; give the tracker its own database (THREEPIO_REDIS_CONNECTION).';
        }

        return new DurabilityVerdict(
            aofEnabled: $aof === null ? null : $aof === '1',
            evictionPolicy: $policy,
            attested: $attested,
            problems: $problems,
            warnings: $warnings,
        );
    }

    /**
     * May the tracker take announces?
     *
     * In production, not on a Redis that can forget: refusing is better than
     * crediting members against baselines that may vanish, and putting it
     * right is the operator's two-line change. Anywhere else (a dev machine,
     * CI) announces go ahead and a warning is logged. Either way, once per
     * cache window.
     */
    public function allowsAnnounces(): bool
    {
        $production = app()->environment('production');

        // Cached so INFO is not asked on every announce. If the cache store
        // is down, it is asked anyway: a cache outage must not become a
        // tracker outage.
        try {
            $cached = Cache::get('bloodhound:redis-durability');

            if ($cached !== null) {
                return (bool) $cached;
            }

            $allowed = $this->judge($production);

            // A passing verdict is kept for five minutes. A failing one only
            // briefly: a check that failed because one INFO call was lost in
            // transit must not refuse every announce for five minutes on a
            // Redis that is configured right.
            Cache::put('bloodhound:redis-durability', $allowed, $allowed ? self::CACHE_SECONDS : self::RETRY_SECONDS);

            return $allowed;
        } catch (Throwable) {
            return $this->judge($production);
        }
    }

    private function judge(bool $production): bool
    {
        return (function () use ($production) {
            $verdict = $this->verify();

            if ($verdict->ok()) {
                return true;
            }

            $message = 'bloodhound: the tracker\'s Redis is not configured for durability; '.implode(' ', $verdict->problems)
                .' Run php artisan bloodhound:check-redis.';

            $firstThisWindow = $this->firstReportThisWindow();

            if ($production) {
                if ($firstThisWindow) {
                    Log::critical($message.' Announces are refused until it is.');
                }

                return false;
            }

            if ($firstThisWindow) {
                Log::warning($message);
            }

            return true;
        })();
    }

    /**
     * Whether this is the first report of a failing check in five minutes,
     * so retries every few seconds don't each log one. Yes if the cache is
     * down: an extra line beats a silent failure.
     */
    private function firstReportThisWindow(): bool
    {
        try {
            return Cache::add('bloodhound:redis-durability-reported', true, self::CACHE_SECONDS);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Report from the scheduler, which runs whether or not anyone announces.
     */
    public function reportFromSchedule(): void
    {
        $verdict = $this->verify();

        if ($verdict->ok()) {
            return;
        }

        $message = 'bloodhound: the tracker\'s Redis is not configured for durability; '.implode(' ', $verdict->problems);

        app()->environment('production') ? Log::critical($message) : Log::warning($message);
    }

    /**
     * Is a recovery window open? Opens one if Redis has just been lost.
     *
     * Asked only when Redis has no record of a peer, so a warm session never
     * pays for it. Redis has been lost when its sentinel is gone but the
     * ledger has announces from the last peer_expiry: the swarm those
     * announces built is not there.
     */
    public function recovering(): bool
    {
        $redis = $this->redis();
        $prefix = config('threepio.redis.prefix', 'marque:');

        if ($redis->exists($prefix.'recovery_until')) {
            return true;
        }

        if ($redis->exists($prefix.'durability_sentinel')) {
            return false;
        }

        $expiry = (int) config('threepio.peer_expiry', 3600);
        $lost = AnnounceLog::query()->where('created_at', '>=', Carbon::now()->subSeconds($expiry))->exists();

        $redis->set($prefix.'durability_sentinel', '1');

        if ($lost) {
            $this->openRecoveryWindow();
            Log::warning('bloodhound: the tracker\'s Redis lost its data. Baselines are recovered from the ledger for '.(2 * $expiry).' seconds.');
        }

        return $lost;
    }

    /**
     * Read baselines from the ledger for twice peer_expiry: long enough for
     * every peer that was in the swarm to announce again.
     */
    public function openRecoveryWindow(): void
    {
        $prefix = config('threepio.redis.prefix', 'marque:');
        $redis = $this->redis();

        $redis->setex($prefix.'recovery_until', 2 * (int) config('threepio.peer_expiry', 3600), '1');
        $redis->set($prefix.'durability_sentinel', '1');
    }

    /**
     * One INFO section, flattened. Throws where the server refuses INFO.
     *
     * @return array<string, string>
     */
    protected function info(string $section): array
    {
        $info = $this->redis()->info($section);
        $flat = [];

        // phpredis answers a flat array; predis nests it under the section.
        array_walk_recursive($info, function ($value, $key) use (&$flat) {
            $flat[(string) $key] = (string) $value;
        });

        return $flat;
    }

    private function read(string $section, string $field): ?string
    {
        try {
            return $this->info($section)[$field] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Does the tracker's Redis database also hold the cache, queue or sessions?
     */
    private function sharesItsStore(): bool
    {
        $tracker = config('threepio.redis.connection', 'default');
        $others = array_filter([
            config('cache.default') === 'redis' ? config('cache.stores.redis.connection', 'cache') : null,
            config('queue.default') === 'redis' ? config('queue.connections.redis.connection', 'default') : null,
            config('session.driver') === 'redis' ? (config('session.connection') ?? 'default') : null,
        ]);

        $where = fn (string $connection) => implode(':', [
            config("database.redis.{$connection}.host"),
            config("database.redis.{$connection}.port"),
            config("database.redis.{$connection}.database"),
        ]);

        foreach ($others as $other) {
            if ($where($other) === $where($tracker)) {
                return true;
            }
        }

        return false;
    }

    private function redis(): Connection
    {
        return Redis::connection(config('threepio.redis.connection', 'default'));
    }
}
