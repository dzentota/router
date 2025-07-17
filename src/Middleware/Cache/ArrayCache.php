<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * Simple array-based cache implementation
 * 
 * For development and testing. In production, use Redis, Memcached, or file cache.
 */
class ArrayCache implements CacheInterface
{
    private array $cache = [];
    private array $expiry = [];

    /**
     * Get value from cache
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }
        
        return $this->cache[$key];
    }

    /**
     * Set value in cache
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->cache[$key] = $value;
        
        if ($ttl !== null) {
            if ($ttl instanceof \DateInterval) {
                $ttl = (new \DateTime())->add($ttl)->getTimestamp() - time();
            }
            $this->expiry[$key] = time() + $ttl;
        }
        
        return true;
    }

    /**
     * Delete value from cache
     */
    public function delete(string $key): bool
    {
        unset($this->cache[$key], $this->expiry[$key]);
        return true;
    }

    /**
     * Clear all cache
     */
    public function clear(): bool
    {
        $this->cache = [];
        $this->expiry = [];
        return true;
    }

    /**
     * Get multiple values
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /**
     * Set multiple values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    /**
     * Delete multiple values
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    /**
     * Check if key exists and is not expired
     */
    public function has(string $key): bool
    {
        // Check if key exists
        if (!array_key_exists($key, $this->cache)) {
            return false;
        }
        
        // Check if expired
        if (isset($this->expiry[$key]) && time() > $this->expiry[$key]) {
            $this->delete($key);
            return false;
        }
        
        return true;
    }
}
