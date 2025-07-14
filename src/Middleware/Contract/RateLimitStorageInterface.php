<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware\Contract;

/**
 * Interface for rate limiting storage
 *
 * Abstracts the storage mechanism for rate limiting data
 */
interface RateLimitStorageInterface
{
    /**
     * Store a submission record
     *
     * @param string $key Unique identifier (usually IP address)
     * @param int $timestamp Submission timestamp
     * @return void
     */
    public function recordSubmission(string $key, int $timestamp): void;

    /**
     * Get all submissions within the given timeframe
     *
     * @param int $timeframe Number of seconds to look back
     * @return array Associative array of submission records
     */
    public function getRecentSubmissions(int $timeframe = 60): array;

    /**
     * Get submissions for a specific identifier within timeframe
     *
     * @param string $key Identifier to filter by (usually IP address)
     * @param int $timeframe Number of seconds to look back
     * @return array Filtered submission records
     */
    public function getSubmissionsForKey(string $key, int $timeframe = 60): array;

    /**
     * Clear all stored submissions
     *
     * @return void
     */
    public function clearAll(): void;
}
