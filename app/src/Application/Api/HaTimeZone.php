<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Infrastructure\Engine\EngineClient;
use App\Infrastructure\Engine\EngineUnavailableException;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The Home Assistant time zone, in which every instant of the API is expressed.
 *
 * The engine knows it (it reads it from Home Assistant when it connects); the last value it gave is cached so
 * a request does not depend on the engine being up. Without any answer yet, the PHP default time zone is used.
 */
final class HaTimeZone
{
    private const string CACHE_KEY = 'api.ha_time_zone';
    private const int CACHE_SECONDS = 3600;

    private ?\DateTimeZone $resolved = null;

    public function __construct(
        private readonly EngineClient $engine,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function get(): \DateTimeZone
    {
        if (null !== $this->resolved) {
            return $this->resolved;
        }

        $item = $this->cache->getItem(self::CACHE_KEY);
        $cached = $item->get();
        if ($item->isHit() && \is_string($cached)) {
            return $this->resolved = new \DateTimeZone($cached);
        }

        try {
            $name = $this->engine->context()['haTimeZone'] ?? null;
        } catch (EngineUnavailableException) {
            $name = null;
        }

        return $this->remember(\is_string($name) ? $name : null) ?? new \DateTimeZone(date_default_timezone_get());
    }

    /**
     * Keeps the time zone the engine reported; ignores an unknown or missing one.
     */
    public function remember(?string $name): ?\DateTimeZone
    {
        if (null === $name) {
            return null;
        }

        try {
            $zone = new \DateTimeZone($name);
        } catch (\Exception) {
            return null;
        }

        $item = $this->cache->getItem(self::CACHE_KEY);
        $item->set($name)->expiresAfter(self::CACHE_SECONDS);
        $this->cache->save($item);

        return $this->resolved = $zone;
    }
}
