<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware\Builder;

use dzentota\Router\Middleware\Contract\RateLimitStorageInterface;
use dzentota\Router\Middleware\HoneypotMiddleware;
use dzentota\Router\Middleware\Cache\InMemoryRateLimitStorage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builder for HoneypotMiddleware
 *
 * Provides a fluent interface for configuring HoneypotMiddleware.
 */
class HoneypotMiddlewareBuilder
{
    /**
     * Form fields that should remain empty (honeypot fields)
     *
     * @var array<string>
     */
    private array $honeypotFields = ['website', 'url', 'confirm_email'];

    /**
     * Minimum time threshold in seconds between form display and submission
     *
     * @var int
     */
    private int $minTimeThreshold = 2;

    /**
     * Whether to block requests on security violations
     *
     * @var bool
     */
    private bool $blockOnViolation = true;

    /**
     * Logger for security events
     *
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger = null;

    /**
     * Storage for rate limiting data
     *
     * @var RateLimitStorageInterface|null
     */
    private ?RateLimitStorageInterface $storage = null;

    /**
     * Maximum allowed submissions per minute from a single IP
     *
     * @var int
     */
    private int $maxSubmissionsPerMinute = 5;

    /**
     * Set honeypot field names
     *
     * @param array $fields Array of field names that should remain empty
     * @return self
     */
    public function withHoneypotFields(array $fields): self
    {
        $clone = clone $this;
        $clone->honeypotFields = $fields;
        return $clone;
    }

    /**
     * Set minimum time threshold between form display and submission
     *
     * @param int $seconds Minimum time in seconds
     * @return self
     */
    public function withMinTimeThreshold(int $seconds): self
    {
        $clone = clone $this;
        $clone->minTimeThreshold = $seconds;
        return $clone;
    }

    /**
     * Set whether to block requests on security violations
     *
     * @param bool $block Whether to block
     * @return self
     */
    public function withBlockOnViolation(bool $block): self
    {
        $clone = clone $this;
        $clone->blockOnViolation = $block;
        return $clone;
    }

    /**
     * Set logger for security events
     *
     * @param LoggerInterface $logger PSR-3 logger
     * @return self
     */
    public function withLogger(LoggerInterface $logger): self
    {
        $clone = clone $this;
        $clone->logger = $logger;
        return $clone;
    }

    /**
     * Set storage for rate limiting
     *
     * @param RateLimitStorageInterface $storage Storage implementation
     * @return self
     */
    public function withStorage(RateLimitStorageInterface $storage): self
    {
        $clone = clone $this;
        $clone->storage = $storage;
        return $clone;
    }

    /**
     * Set maximum submissions per minute from a single IP
     *
     * @param int $max Maximum submissions
     * @return self
     */
    public function withMaxSubmissionsPerMinute(int $max): self
    {
        $clone = clone $this;
        $clone->maxSubmissionsPerMinute = $max;
        return $clone;
    }

    /**
     * Create a new instance of the builder
     *
     * @return self
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Build the HoneypotMiddleware
     *
     * @return HoneypotMiddleware
     */
    public function build(): HoneypotMiddleware
    {
        return new HoneypotMiddleware(
            $this->honeypotFields,
            $this->minTimeThreshold,
            $this->blockOnViolation,
            $this->logger ?? new NullLogger(),
            $this->storage ?? new InMemoryRateLimitStorage(),
            $this->maxSubmissionsPerMinute
        );
    }
}
