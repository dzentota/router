<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware\Cache;

use dzentota\Router\Middleware\Contract\RateLimitStorageInterface;

/**
 * In-memory storage for rate limiting
 *
 * Stores submission data in memory. Suitable for tests and simple applications.
 * For production environments, consider using a persistent storage implementation.
 */
class InMemoryRateLimitStorage implements RateLimitStorageInterface
{
    /**
     * Stored submissions
     *
     * @var array<string, int> Key-value pairs of submission identifiers and timestamps
     */
    private static array $submissions = [];

    /**
     * {@inheritdoc}
     */
    public function recordSubmission(string $key, int $timestamp): void
    {
        self::$submissions[$key . '_' . $timestamp] = $timestamp;
    }

    /**
     * {@inheritdoc}
     */
    public function getRecentSubmissions(int $timeframe = 60): array
    {
        $now = time();

        // Return only submissions within the timeframe
        return array_filter(
            self::$submissions,
            fn($time) => ($now - $time) < $timeframe
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getSubmissionsForKey(string $key, int $timeframe = 60): array
    {
        $now = time();

        // First filter by timeframe, then by key
        return array_filter(
            $this->getRecentSubmissions($timeframe),
            fn($time, $submissionKey) => strpos($submissionKey, $key . '_') === 0,
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * {@inheritdoc}
     */
    public function clearAll(): void
    {
        self::$submissions = [];
    }
}
