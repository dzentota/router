<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware;

use dzentota\Router\Middleware\Contract\CsrfProtectionStrategyInterface;
use dzentota\Router\Middleware\Exception\CsrfException;
use dzentota\Router\Middleware\Exception\SecurityException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * PSR-15 CSRF Protection Middleware
 * 
 * Stateless middleware that operates exclusively with PSR-7 objects.
 * Uses pluggable strategies for different CSRF protection methods.
 */
class CsrfMiddleware implements MiddlewareInterface
{
    private CsrfProtectionStrategyInterface $strategy;
    private array $safeMethods = ['GET', 'HEAD', 'OPTIONS'];
    private LoggerInterface $logger;
    private Psr17Factory $responseFactory;
    private array $exemptRoutes = [];
    private ?CacheInterface $cache;
    private int $maxFailedAttempts;
    private int $failureWindowSeconds;

    /**
     * @param CsrfProtectionStrategyInterface $strategy      CSRF protection strategy.
     * @param LoggerInterface|null            $logger         PSR-3 logger for security events.
     * @param array                           $exemptRoutes   Route paths exempt from CSRF protection.
     * @param CacheInterface|null             $cache          PSR-16 cache for failure rate limiting.
     *                                                         When null, rate limiting is disabled.
     * @param int                             $maxFailedAttempts Max failures before blocking (per IP per window).
     * @param int                             $failureWindowSeconds Rolling window in seconds.
     */
    public function __construct(
        CsrfProtectionStrategyInterface $strategy,
        ?LoggerInterface $logger = null,
        array $exemptRoutes = [],
        ?CacheInterface $cache = null,
        int $maxFailedAttempts = 5,
        int $failureWindowSeconds = 3600,
    ) {
        $this->strategy             = $strategy;
        $this->logger               = $logger ?? new NullLogger();
        $this->responseFactory      = new Psr17Factory();
        $this->exemptRoutes         = $exemptRoutes;
        $this->cache                = $cache;
        $this->maxFailedAttempts    = $maxFailedAttempts;
        $this->failureWindowSeconds = $failureWindowSeconds;
    }

    /**
     * Process the request through CSRF protection
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array($request->getMethod(), $this->safeMethods, true) &&
            !$this->isExemptRoute($request->getUri()->getPath())) {

            $ip = $this->getClientIp($request);

            // Block IPs that have exceeded the failure threshold before even validating the token.
            if ($this->isRateLimited($ip)) {
                $this->logger->warning('CSRF rate limit exceeded', [
                    'ip_address' => $ip,
                    'uri'        => (string)$request->getUri(),
                ]);
                return $this->createForbiddenResponse('Too many failed requests');
            }

            try {
                $this->strategy->validateRequest($request);
            } catch (CsrfException $e) {
                $this->logFailedAttempt($request, $e);
                $this->recordFailure($ip);
                return $this->createForbiddenResponse($e->getMessage());
            }
        }

        $response = $handler->handle($request);
        return $this->strategy->attachToken($request, $response);
    }

    // -------------------------------------------------------------------------
    // Rate limiting helpers
    // -------------------------------------------------------------------------

    private function getClientIp(ServerRequestInterface $request): string
    {
        return $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
    }

    private function failureCacheKey(string $ip): string
    {
        return 'csrf_failures_' . md5($ip);
    }

    private function isRateLimited(string $ip): bool
    {
        if ($this->cache === null) {
            return false;
        }
        $count = (int)($this->cache->get($this->failureCacheKey($ip), 0));
        return $count >= $this->maxFailedAttempts;
    }

    private function recordFailure(string $ip): void
    {
        if ($this->cache === null) {
            return;
        }
        $key   = $this->failureCacheKey($ip);
        $count = (int)($this->cache->get($key, 0));
        $this->cache->set($key, $count + 1, $this->failureWindowSeconds);
    }

    // -------------------------------------------------------------------------

    /**
     * Log failed CSRF attempt with security metadata (no sensitive data)
     */
    private function logFailedAttempt(ServerRequestInterface $request, CsrfException $e): void
    {
        $serverParams = $request->getServerParams();
        
        $context = [
            'timestamp' => date('c'),
            'method' => $request->getMethod(),
            'uri' => (string)$request->getUri(),
            'ip_address' => $serverParams['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'referer' => $request->getHeaderLine('Referer'),
            'error_type' => get_class($e),
            'error_message' => $e->getMessage(),
            // Note: We explicitly do NOT log the actual token values for security
        ];

        $this->logger->warning('CSRF protection violation detected', $context);
    }

    /**
     * Create 403 Forbidden response for CSRF failures
     */
    private function createForbiddenResponse(string $message = 'Request validation failed'): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(403);

        $errorBody = json_encode([
            'error'     => 'Forbidden',
            'message'   => $message,
            'timestamp' => date('c'),
        ]);
        
        $response->getBody()->write($errorBody);
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');
    }

    /**
     * Add additional safe methods (for custom extension)
     */
    public function addSafeMethod(string $method): self
    {
        if (!in_array($method, $this->safeMethods, true)) {
            $this->safeMethods[] = strtoupper($method);
        }
        
        return $this;
    }

    /**
     * Remove safe method (for custom restriction)
     */
    public function removeSafeMethod(string $method): self
    {
        $this->safeMethods = array_filter(
            $this->safeMethods,
            fn($m) => $m !== strtoupper($method)
        );
        
        return $this;
    }

    /**
     * Check if the requested path matches any of the exempt routes
     *
     * @param string $path The request path to check
     * @return bool True if the path is exempt from CSRF protection
     */
    private function isExemptRoute(string $path): bool
    {
        if (empty($this->exemptRoutes)) {
            return false;
        }

        foreach ($this->exemptRoutes as $exemptRoute) {
            // Exact match
            if ($exemptRoute === $path) {
                return true;
            }

            // Wildcard match (if route ends with *)
            if (substr($exemptRoute, -1) === '*') {
                $prefix = rtrim(substr($exemptRoute, 0, -1), '/');
                if (strpos($path, $prefix) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Add a route to be exempt from CSRF protection
     *
     * @param string $route Route path to exempt
     * @return self
     */
    public function addExemptRoute(string $route): self
    {
        if (!in_array($route, $this->exemptRoutes, true)) {
            $this->exemptRoutes[] = $route;
        }

        return $this;
    }

    /**
     * Get the list of exempt routes
     *
     * @return array List of exempt route paths
     */
    public function getExemptRoutes(): array
    {
        return $this->exemptRoutes;
    }
}
