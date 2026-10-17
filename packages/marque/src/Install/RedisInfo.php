<?php

declare(strict_types=1);

namespace Marque\Marque\Install;

use Illuminate\Support\Facades\Redis;

/**
 * One INFO section from the tracker's Redis, flattened. Throws where the
 * server refuses INFO, as some managed proxies do.
 */
class RedisInfo
{
    /**
     * @return array<string, string>
     */
    public function section(string $section): array
    {
        $info = Redis::connection(config('threepio.redis.connection', 'default'))->info($section);
        $flat = [];

        // phpredis answers a flat array; predis nests it under the section.
        array_walk_recursive($info, function ($value, $key) use (&$flat) {
            $flat[(string) $key] = (string) $value;
        });

        return $flat;
    }
}
