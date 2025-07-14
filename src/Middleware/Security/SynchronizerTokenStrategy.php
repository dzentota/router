<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware\Security;

use dzentota\Router\Middleware\Contract\CsrfProtectionStrategyInterface;
use dzentota\Router\Middleware\Contract\TokenGeneratorInterface;
use dzentota\Router\Middleware\Exception\CsrfException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Synchronizer Token CSRF Protection Strategy
 * 
 * Stateful strategy that stores one-time tokens in PSR-16 compatible cache.
 * Supports multiple tabs by maintaining a pool of valid tokens per session.
 */
class SynchronizerTokenStrategy implements CsrfProtectionStrategyInterface
{
    private TokenGeneratorInterface $tokenGenerator;
    private CacheInterface $cache;
    private string $sessionAttribute;
    private int $maxTokensPerSession;
    private int $tokenTtl;

    public function __construct(
        TokenGeneratorInterface $tokenGenerator,
        CacheInterface $cache,
        string $sessionAttribute = 'session_id',
        int $maxTokensPerSession = 10,
        int $tokenTtl = 3600
    ) {
        $this->tokenGenerator = $tokenGenerator;
        $this->cache = $cache;
        $this->sessionAttribute = $sessionAttribute;
        $this->maxTokensPerSession = $maxTokensPerSession;
        $this->tokenTtl = $tokenTtl;
    }

    /**
     * Attach new CSRF token to response as JSON or hidden form field
     */
    public function attachToken(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $sessionId = $this->getSessionId($request);
        if ($sessionId === null) {
            throw new CsrfException('Session ID not found in request attributes');
        }

        // Generate new token
        $token = $this->tokenGenerator->generateToken();
        
        // Add token to cache pool
        $this->addTokenToPool($sessionId, $token);
        
        // Add token to response headers for JavaScript access
        return $response->withAddedHeader('X-CSRF-TOKEN', $token);
    }

    /**
     * Validate CSRF token from request and remove it from pool (one-time use)
     */
    public function validateRequest(ServerRequestInterface $request): void
    {
        $sessionId = $this->getSessionId($request);
        if ($sessionId === null) {
            throw new CsrfException('Session ID not found in request attributes');
        }

        // Extract token from request
        $token = $this->extractTokenFromRequest($request);
        if ($token === null) {
            throw new CsrfException('CSRF token missing from request');
        }

        // Validate token exists in pool
        if (!$this->validateAndConsumeToken($sessionId, $token)) {
            throw new CsrfException('Invalid or expired CSRF token');
        }
    }

    /**
     * Get session ID from request attributes
     */
    private function getSessionId(ServerRequestInterface $request): ?string
    {
        return $request->getAttribute($this->sessionAttribute);
    }

    /**
     * Add token to the pool for the session
     */
    private function addTokenToPool(string $sessionId, string $token): void
    {
        $cacheKey = $this->getCacheKey($sessionId);
        $tokenPool = $this->cache->get($cacheKey, []);
        
        // Add new token with timestamp
        $tokenPool[$token] = time();
        
        // Remove expired tokens and limit pool size
        $tokenPool = $this->cleanupTokenPool($tokenPool);
        
        // Limit number of tokens per session
        if (count($tokenPool) > $this->maxTokensPerSession) {
            // Remove oldest tokens
            asort($tokenPool);
            $tokenPool = array_slice($tokenPool, -$this->maxTokensPerSession, null, true);
        }
        
        $this->cache->set($cacheKey, $tokenPool, $this->tokenTtl);
    }

    /**
     * Validate token and remove it from pool (one-time use)
     */
    private function validateAndConsumeToken(string $sessionId, string $token): bool
    {
        $cacheKey = $this->getCacheKey($sessionId);
        $tokenPool = $this->cache->get($cacheKey, []);
        
        // Clean expired tokens
        $tokenPool = $this->cleanupTokenPool($tokenPool);
        
        // Check if token exists
        if (!isset($tokenPool[$token])) {
            return false;
        }
        
        // Remove token (one-time use)
        unset($tokenPool[$token]);
        
        // Update cache
        $this->cache->set($cacheKey, $tokenPool, $this->tokenTtl);
        
        return true;
    }

    /**
     * Remove expired tokens from pool
     */
    private function cleanupTokenPool(array $tokenPool): array
    {
        $now = time();
        return array_filter($tokenPool, function ($timestamp) use ($now) {
            return ($now - $timestamp) < $this->tokenTtl;
        });
    }

    /**
     * Generate cache key for session
     */
    private function getCacheKey(string $sessionId): string
    {
        return "csrf_tokens:{$sessionId}";
    }

    /**
     * Extract token from request (header takes precedence over form data)
     */
    private function extractTokenFromRequest(ServerRequestInterface $request): ?string
    {
        // Check X-CSRF-TOKEN header (for AJAX/SPA)
        $headerTokens = $request->getHeader('X-CSRF-TOKEN');
        if (!empty($headerTokens)) {
            return $headerTokens[0];
        }
        
        // Check _token in parsed body (for HTML forms)
        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody) && isset($parsedBody['_token'])) {
            return (string)$parsedBody['_token'];
        }
        
        return null;
    }

    /**
     * Generate a new token for forms/AJAX (public method for controllers)
     */
    public function generateToken(ServerRequestInterface $request): string
    {
        $sessionId = $this->getSessionId($request);
        if ($sessionId === null) {
            throw new CsrfException('Session ID not found in request attributes');
        }

        $token = $this->tokenGenerator->generateToken();
        $this->addTokenToPool($sessionId, $token);
        
        return $token;
    }
} 