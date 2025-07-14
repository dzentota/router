<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * CORS (Cross-Origin Resource Sharing) Middleware
 * 
 * Handles preflight requests and adds CORS headers to responses.
 * Security-focused implementation with configurable policies.
 */
class CorsMiddleware implements MiddlewareInterface
{
    private array $config;
    private Psr17Factory $responseFactory;

    public function __construct(array $config = [])
    {
        $this->responseFactory = new Psr17Factory();
        
        // Security-first default configuration
        $this->config = array_merge([
            'allowed_origins' => [],           // No origins allowed by default
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'X-CSRF-TOKEN'],
            'exposed_headers' => ['X-CSRF-TOKEN'],
            'allow_credentials' => false,      // Disabled by default for security
            'max_age' => 86400,               // 24 hours
            'require_exact_origin' => true,   // No wildcard matching by default
        ], $config);
    }

    /**
     * Process CORS request
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        
        // Handle preflight request
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflightRequest($request, $origin);
        }

        // Process actual request
        $response = $handler->handle($request);

        // Add CORS headers to actual response
        return $this->addCorsHeaders($response, $origin);
    }

    /**
     * Handle OPTIONS preflight request
     */
    private function handlePreflightRequest(ServerRequestInterface $request, string $origin): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(200);
        
        // Validate origin
        if (!$this->isOriginAllowed($origin)) {
            return $response->withStatus(403);
        }

        // Validate requested method
        $requestedMethod = $request->getHeaderLine('Access-Control-Request-Method');
        if (!in_array($requestedMethod, $this->config['allowed_methods'], true)) {
            return $response->withStatus(403);
        }

        // Validate requested headers
        $requestedHeaders = $request->getHeaderLine('Access-Control-Request-Headers');
        if ($requestedHeaders && !$this->areHeadersAllowed($requestedHeaders)) {
            return $response->withStatus(403);
        }

        // Add preflight response headers
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->config['allowed_methods']))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->config['allowed_headers']))
            ->withHeader('Access-Control-Max-Age', (string)$this->config['max_age']);

        if ($this->config['allow_credentials']) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    /**
     * Add CORS headers to actual response
     */
    private function addCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        if (!$this->isOriginAllowed($origin)) {
            return $response;
        }

        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Expose-Headers', implode(', ', $this->config['exposed_headers']));

        if ($this->config['allow_credentials']) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    /**
     * Check if origin is allowed
     */
    private function isOriginAllowed(string $origin): bool
    {
        if (empty($origin)) {
            return false;
        }

        // Check against allowed origins list
        if (in_array($origin, $this->config['allowed_origins'], true)) {
            return true;
        }

        // Check for wildcard (only if explicitly configured)
        if (!$this->config['require_exact_origin'] && in_array('*', $this->config['allowed_origins'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Check if requested headers are allowed
     */
    private function areHeadersAllowed(string $requestedHeaders): bool
    {
        $headers = array_map('trim', explode(',', $requestedHeaders));
        
        foreach ($headers as $header) {
            if (!in_array($header, $this->config['allowed_headers'], true)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Add allowed origin (fluent interface)
     */
    public function allowOrigin(string $origin): self
    {
        if (!in_array($origin, $this->config['allowed_origins'], true)) {
            $this->config['allowed_origins'][] = $origin;
        }
        
        return $this;
    }

    /**
     * Allow credentials (use with caution)
     */
    public function allowCredentials(bool $allow = true): self
    {
        $this->config['allow_credentials'] = $allow;
        return $this;
    }
} 