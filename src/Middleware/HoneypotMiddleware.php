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

    /**
     * Constructor
     *
     * @param array $honeypotFields Field names that should remain empty
     * @param int $minTimeThreshold Minimum time in seconds between form display and submission
     * @param bool $blockOnViolation Whether to block requests on violations
     * @param LoggerInterface|null $logger PSR-3 logger for security events
     * @param RateLimitStorageInterface|null $storage Storage for rate limiting
     * @param int $maxSubmissionsPerMinute Maximum submissions allowed per minute from one IP
     */
    public function __construct(
        array $honeypotFields = ['website', 'url', 'confirm_email'],
        int $minTimeThreshold = 2,
        bool $blockOnViolation = true,
        ?LoggerInterface $logger = null,
        ?RateLimitStorageInterface $storage = null,
        int $maxSubmissionsPerMinute = 5
    ) {
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
            if (isset($parsedBody[$field]) && !empty(trim((string)$parsedBody[$field]))) {
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
        if (!isset($parsedBody['_timestamp'])) {
            return;
        }

        $timestamp = (int)$parsedBody['_timestamp'];
        $currentTime = time();
        $timeDiff = $currentTime - $timestamp;

        if ($timeDiff < $this->minTimeThreshold) {
            throw new SecurityException("Form submitted too quickly ({$timeDiff}s)");
        }

        // Also check for suspiciously old timestamps (replay attacks)
        if ($timeDiff > 3600) { // 1 hour
            throw new SecurityException("Form timestamp too old ({$timeDiff}s)");
        }
    }

    /**
     * Check for rapid consecutive submissions from same IP
     *
     * @param ServerRequestInterface $request The PSR-7 request
     * @throws SecurityException When too many submissions detected from IP
     */
    private function checkRapidSubmissions(ServerRequestInterface $request): void
    {
        $serverParams = $request->getServerParams();
        $ipAddress = $serverParams['REMOTE_ADDR'] ?? null;
        
        if (!$ipAddress) {
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
        $serverParams = $request->getServerParams();
        
        $context = [
            'timestamp' => date('c'),
            'method' => $request->getMethod(),
            'uri' => (string)$request->getUri(),
            'ip_address' => $serverParams['REMOTE_ADDR'] ?? 'unknown',
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
     * @return string Current timestamp as string
     */
    public function generateTimestamp(): string
    {
        return (string)time();
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

