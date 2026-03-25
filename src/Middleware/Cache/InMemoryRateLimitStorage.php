<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware\Cache;

use dzentota\Router\Middleware\Contract\RateLimitStorageInterface;

/**
 * In-memory rate-limit storage.
 *
 * Stores timestamps in per-key buckets instead of a flat composite-key array,
 * giving O(1) key lookup and avoiding O(n) full-array prefix scans.
 * Stale entries are pruned automatically to prevent unbounded memory growth.
 *
 * Note: state is shared across all instances via the static property; use
 * clearAll() between tests to avoid cross-test pollution.
 */
class InMemoryRateLimitStorage implements RateLimitStorageInterface
{
    /**
     * Submissions indexed by key; each value is a list of Unix timestamps.
     *
     * @var array<string, list<int>>
     */
    private static array $buckets = [];

    /**
     * {@inheritdoc}
     */
    public function recordSubmission(string $key, int $timestamp): void
    {
        self::$buckets[$key][] = $timestamp;
    }

    /**
     * {@inheritdoc}
     */
    public function getSubmissionsForKey(string $key, int $timeframe = 60): array
    {
        if (!isset(self::$buckets[$key])) {
            return [];
        }

        $cutoff  = time() - $timeframe;
        $results = array_filter(self::$buckets[$key], static fn(int $ts) => $ts > $cutoff);

        // Prune expired entries in-place while we have the bucket open.
        self::$buckets[$key] = array_values($results);

        return self::$buckets[$key];
    }

    /**
     * {@inheritdoc}
     *
     * Returns a flat list of timestamps (across all keys) within the timeframe.
     */
    public function getRecentSubmissions(int $timeframe = 60): array
    {
        $cutoff = time() - $timeframe;
        $recent = [];

        foreach (self::$buckets as $key => $timestamps) {
            $filtered = array_filter($timestamps, static fn(int $ts) => $ts > $cutoff);
            // Prune stale entries opportunistically.
            self::$buckets[$key] = array_values($filtered);
            foreach ($filtered as $ts) {
                $recent[] = $ts;
            }
        }

        return $recent;
    }

    /**
     * {@inheritdoc}
     */
    public function clearAll(): void
    {
        self::$buckets = [];
    }
}
