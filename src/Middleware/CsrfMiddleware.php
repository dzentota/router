<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware;

use dzentota\Router\Middleware\Contract\CsrfProtectionStrategyInterface;
use dzentota\Router\Middleware\Exception\CsrfException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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

    /**
     * Constructor
     *
     * @param CsrfProtectionStrategyInterface $strategy CSRF protection strategy
     * @param LoggerInterface|null $logger PSR-3 logger for security events
     * @param array $exemptRoutes Array of route paths to exempt from CSRF protection
     */
    public function __construct(
        CsrfProtectionStrategyInterface $strategy,
        ?LoggerInterface $logger = null,
        array $exemptRoutes = []
    ) {
        $this->strategy = $strategy;
        $this->logger = $logger ?? new NullLogger();
        $this->responseFactory = new Psr17Factory();
        $this->exemptRoutes = $exemptRoutes;
    }

    /**
     * Process the request through CSRF protection
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Check if method requires CSRF protection
        if (!in_array($request->getMethod(), $this->safeMethods, true) &&
            !$this->isExemptRoute($request->getUri()->getPath())) {
            try {
                $this->strategy->validateRequest($request);
            } catch (CsrfException $e) {
                // Secure logging of failed attempt
                $this->logFailedAttempt($request, $e);
                
                // Return 403 Forbidden response
                return $this->createForbiddenResponse($e->getMessage());
            }
        }

        // Process request through handler
        $response = $handler->handle($request);

        // Attach token to response (for safe methods and after successful validation)
        return $this->strategy->attachToken($request, $response);
    }

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
    private function createForbiddenResponse(string $message = 'CSRF token validation failed'): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(403);
        
        // Generic error message (don't leak implementation details)
        $errorBody = json_encode([
            'error' => 'Forbidden',
            'message' => 'Request validation failed',
            'timestamp' => date('c')
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
