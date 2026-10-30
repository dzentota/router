<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware;

use dzentota\Router\Middleware\Contract\RateLimitStorageInterface;
use dzentota\Router\Middleware\Cache\InMemoryRateLimitStorage;
use dzentota\Router\Middleware\Exception\SecurityException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * Honeypot Middleware
 * 
 * Detects and blocks automated form submissions by using hidden form fields
 * that should remain empty. If filled, indicates bot activity.
 *
 * Timing check: without a $timestampSecret the `_timestamp` field is an unsigned client
 * value and is only checked when present, so a bot can trivially bypass it (omit it or send
 * any time). Configure $timestampSecret to require an HMAC-signed timestamp token.
 *
 * Rate limiting: the default InMemoryRateLimitStorage lives only for the current PHP process
 * and is effectively useless under PHP-FPM / CGI (state is lost after every request).
 * In production inject a persistent RateLimitStorageInterface (Redis, APCu, database, ...).
 */
class HoneypotMiddleware implements MiddlewareInterface
{
    /**
     * Form fields that should remain empty (honeypot fields)
     *
     * @var array<string>
     */
    private array $honeypotFields;

    /**
     * Minimum time threshold in seconds between form display and submission
     *
     * @var int
     */
    private int $minTimeThreshold;

    /**
     * Logger for security events
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * PSR-17 factory for creating responses
     *
     * @var Psr17Factory
     */
    private Psr17Factory $responseFactory;

    /**
     * Whether to block requests on security violations
     *
     * @var bool
     */
    private bool $blockOnViolation;

    /**
     * Storage for rate limiting data
     *
     * @var RateLimitStorageInterface
     */
    private RateLimitStorageInterface $storage;

    /**
     * Maximum allowed submissions per minute from a single IP
     *
     * @var int
     */
    private int $maxSubmissionsPerMinute;

    private ?string $timestampSecret;

    private int $maxTimestampAge;

    private ?\Closure $clientIpResolver;

    /**
     * Constructor
     *
     * @param array $honeypotFields Field names that should remain empty
     * @param int $minTimeThreshold Minimum time in seconds between form display and submission
     * @param bool $blockOnViolation Whether to block requests on violations
     * @param LoggerInterface|null $logger PSR-3 logger for security events
     * @param RateLimitStorageInterface|null $storage Storage for rate limiting
     * @param int $maxSubmissionsPerMinute Maximum submissions allowed per minute from one IP
     * @param string|null $timestampSecret When set, `_timestamp` must be a token produced by
     *                                     generateTimestamp() ("{unix}.{base64url(hmac-sha256)}");
     *                                     missing, malformed or forged tokens are violations.
     *                                     When null, the unsigned check is bypassable.
     * @param int $maxTimestampAge Maximum age in seconds of the form timestamp (default 3600)
     * @param callable|null $clientIpResolver callable(ServerRequestInterface): ?string returning the
     *                                        rate-limit key (client IP); defaults to REMOTE_ADDR.
     *                                        Returning null skips rate limiting.
     */
    public function __construct(
        array $honeypotFields = ['website', 'url', 'confirm_email'],
        int $minTimeThreshold = 2,
        bool $blockOnViolation = true,
        ?LoggerInterface $logger = null,
        ?RateLimitStorageInterface $storage = null,
        int $maxSubmissionsPerMinute = 5,
        ?string $timestampSecret = null,
        int $maxTimestampAge = 3600,
        ?callable $clientIpResolver = null
    ) {
        if ($timestampSecret === '') {
            throw new \InvalidArgumentException('timestampSecret must not be empty');
        }
        $this->timestampSecret = $timestampSecret;
        $this->maxTimestampAge = $maxTimestampAge;
        $this->clientIpResolver = $clientIpResolver !== null ? \Closure::fromCallable($clientIpResolver) : null;
        $this->honeypotFields = $honeypotFields;
        $this->minTimeThreshold = $minTimeThreshold;
        $this->blockOnViolation = $blockOnViolation;
        $this->logger = $logger ?? new NullLogger();
        $this->responseFactory = new Psr17Factory();
        $this->storage = $storage ?? new InMemoryRateLimitStorage();
        $this->maxSubmissionsPerMinute = $maxSubmissionsPerMinute;
    }

    /**
     * Process request through honeypot detection
     *
     * @param ServerRequestInterface $request The PSR-7 request
     * @param RequestHandlerInterface $handler The request handler
     * @return ResponseInterface The PSR-7 response
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only check POST, PUT, PATCH requests with form data
        if (!in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            return $handler->handle($request);
        }

        $parsedBody = $request->getParsedBody();
        if (!is_array($parsedBody)) {
            return $handler->handle($request);
        }

        try {
            // Check honeypot fields
            $this->checkHoneypotFields($parsedBody);
            
            // Check submission timing (if timestamp present)
            $this->checkSubmissionTiming($parsedBody);
            
            // Check for rapid submissions from same IP
            $this->checkRapidSubmissions($request);
            
        } catch (SecurityException $e) {
            $this->logSecurityViolation($request, $e);
            
            if ($this->blockOnViolation) {
                return $this->createBlockedResponse();
            }
            
            // Continue processing but add violation flag to request
            $request = $request->withAttribute('honeypot_violation', true);
        }

        return $handler->handle($request);
    }

    /**
     * Check if honeypot fields are filled (indicating bot activity)
     *
     * @param array $parsedBody The parsed request body
     * @throws SecurityException When honeypot field is filled
     */
    private function checkHoneypotFields(array $parsedBody): void
    {
        foreach ($this->honeypotFields as $field) {
            if (!isset($parsedBody[$field])) {
                continue;
            }
            $value = $parsedBody[$field];
            // Legitimate forms send a scalar string; any array/object is treated as filled.
            if (!is_scalar($value) || trim((string)$value) !== '') {
                throw new SecurityException("Honeypot field '{$field}' was filled");
            }
        }
    }

    /**
     * Check if form was submitted too quickly (indicating bot activity)
     *
     * @param array $parsedBody The parsed request body
     * @throws SecurityException When form is submitted too quickly or timestamp is too old
     */
    private function checkSubmissionTiming(array $parsedBody): void
    {
        $raw = $parsedBody['_timestamp'] ?? null;

        if ($this->timestampSecret === null) {
            // Unsigned legacy mode: bypassable, only checked when present.
            if ($raw === null) {
                return;
            }
            $timestamp = is_scalar($raw) ? (int)$raw : 0;
        } else {
            $timestamp = $this->verifySignedTimestamp($raw);
        }

        $timeDiff = time() - $timestamp;

        if ($timeDiff < $this->minTimeThreshold) {
            throw new SecurityException("Form submitted too quickly ({$timeDiff}s)");
        }

        // Also check for suspiciously old timestamps (replay attacks)
        if ($timeDiff > $this->maxTimestampAge) {
            throw new SecurityException("Form timestamp too old ({$timeDiff}s)");
        }
    }

    /**
     * @throws SecurityException When the token is missing, malformed or has an invalid signature
     */
    private function verifySignedTimestamp(mixed $raw): int
    {
        if (!is_string($raw) || !preg_match('/^(\d{1,12})\.([A-Za-z0-9_-]{43})$/', $raw, $m)) {
            throw new SecurityException('Form timestamp token missing or malformed');
        }
        if (!hash_equals($this->sign($m[1]), $m[2])) {
            throw new SecurityException('Form timestamp token signature invalid');
        }
        return (int)$m[1];
    }

    private function sign(string $unix): string
    {
        $mac = hash_hmac('sha256', $unix, (string)$this->timestampSecret, true);
        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    private function getClientIp(ServerRequestInterface $request): ?string
    {
        if ($this->clientIpResolver !== null) {
            $ip = ($this->clientIpResolver)($request);
            return is_string($ip) && $ip !== '' ? $ip : null;
        }
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /**
     * Check for rapid consecutive submissions from same IP
     *
     * @param ServerRequestInterface $request The PSR-7 request
     * @throws SecurityException When too many submissions detected from IP
     */
    private function checkRapidSubmissions(ServerRequestInterface $request): void
    {
        $ipAddress = $this->getClientIp($request);

        if ($ipAddress === null) {
            return;
        }

        $now = time();
        
        // Get submissions from this IP in last minute
        $ipSubmissions = $this->storage->getSubmissionsForKey($ipAddress, 60);

        if (count($ipSubmissions) >= $this->maxSubmissionsPerMinute) {
            throw new SecurityException("Too many rapid submissions from IP {$ipAddress}");
        }
        
        // Record this submission
        $this->storage->recordSubmission($ipAddress, $now);
    }

    /**
     * Log security violation with context
     *
     * @param ServerRequestInterface $request The PSR-7 request
     * @param SecurityException $e The security exception
     */
    private function logSecurityViolation(ServerRequestInterface $request, SecurityException $e): void
    {
        $context = [
            'timestamp' => date('c'),
            'method' => $request->getMethod(),
            'uri' => (string)$request->getUri(),
            'ip_address' => $this->getClientIp($request) ?? 'unknown',
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'referer' => $request->getHeaderLine('Referer'),
            'violation_type' => 'honeypot',
            'violation_reason' => $e->getMessage(),
        ];

        $this->logger->warning('Honeypot violation detected', $context);
    }

    /**
     * Create blocked response for detected bots
     *
     * @return ResponseInterface The PSR-7 response
     */
    private function createBlockedResponse(): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(429); // Too Many Requests
        
        $errorBody = json_encode([
            'error' => 'Too Many Requests',
            'message' => 'Request rate limit exceeded',
            'timestamp' => date('c')
        ]);
        
        $response->getBody()->write($errorBody);
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Retry-After', '60')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    /**
     * Generate honeypot timestamp for forms
     *
     * With a timestamp secret this returns a signed token "{unix}.{base64url(hmac)}";
     * otherwise the plain unix time (unsigned, bypassable).
     *
     * @return string Value for the `_timestamp` hidden form field
     */
    public function generateTimestamp(): string
    {
        $now = (string)time();
        return $this->timestampSecret === null ? $now : $now . '.' . $this->sign($now);
    }

    /**
     * Get honeypot field names for form generation
     *
     * @return array List of honeypot field names
     */
    public function getHoneypotFields(): array
    {
        return $this->honeypotFields;
    }

    /**
     * Reset rate limiting storage
     *
     * Primarily used for testing purposes
     *
     * @return void
     */
    public function resetStorage(): void
    {
        $this->storage->clearAll();
    }

    /**
     * Get the current storage instance
     *
     * @return RateLimitStorageInterface
     */
    public function getStorage(): RateLimitStorageInterface
    {
        return $this->storage;
    }

    /**
     * Set the storage instance
     *
     * @param RateLimitStorageInterface $storage
     * @return void
     */
    public function setStorage(RateLimitStorageInterface $storage): void
    {
        $this->storage = $storage;
    }

    /**
     * Set maximum submissions per minute
     *
     * @param int $max
     * @return void
     */
    public function setMaxSubmissionsPerMinute(int $max): void
    {
        $this->maxSubmissionsPerMinute = $max;
    }
}

