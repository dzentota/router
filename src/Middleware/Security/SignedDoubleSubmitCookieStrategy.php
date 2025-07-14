<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware\Security;

use dzentota\Router\Middleware\Contract\CsrfProtectionStrategyInterface;
use dzentota\Router\Middleware\Contract\TokenGeneratorInterface;
use dzentota\Router\Middleware\Exception\CsrfException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Signed Double Submit Cookie CSRF Protection Strategy
 * 
 * This is the recommended stateless strategy that uses:
 * - A random token stored in a cookie (readable by JavaScript)
 * - HMAC signature of the token sent in X-CSRF-TOKEN header
 */
class SignedDoubleSubmitCookieStrategy implements CsrfProtectionStrategyInterface
{
    private TokenGeneratorInterface $tokenGenerator;
    private string $serverSecret;
    private string $cookieName;
    private array $cookieOptions;

    public function __construct(
        TokenGeneratorInterface $tokenGenerator,
        string $serverSecret,
        string $cookieName = '__Host-csrf-token',
        array $cookieOptions = []
    ) {
        $this->tokenGenerator = $tokenGenerator;
        $this->serverSecret = $serverSecret;
        $this->cookieName = $cookieName;
        
        // Security-first cookie configuration
        $this->cookieOptions = array_merge([
            'httponly' => false,  // Must be false so JavaScript can read it
            'secure' => true,     // HTTPS only
            'samesite' => 'Lax',  // CSRF protection
            'path' => '/',        // Site-wide
            'domain' => null,     // Current host only
        ], $cookieOptions);
    }

    /**
     * Attach CSRF token cookie to the response
     */
    public function attachToken(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Check if token already exists in cookie
        $cookies = $request->getCookieParams();
        $existingToken = $cookies[$this->cookieName] ?? null;
        
        if ($existingToken === null) {
            // Generate new token
            $token = $this->tokenGenerator->generateToken();
            
            // Build cookie string with security attributes
            $cookieValue = $this->buildCookieString($token);
            
            return $response->withAddedHeader('Set-Cookie', $cookieValue);
        }
        
        return $response;
    }

    /**
     * Validate CSRF token from request
     */
    public function validateRequest(ServerRequestInterface $request): void
    {
        // Extract token from cookie
        $cookies = $request->getCookieParams();
        $cookieToken = $cookies[$this->cookieName] ?? null;
        
        if ($cookieToken === null) {
            throw new CsrfException('CSRF token cookie missing');
        }
        
        // Extract signature from header (preferred) or form data
        $signature = $this->extractTokenFromRequest($request);
        
        if ($signature === null) {
            throw new CsrfException('CSRF token signature missing');
        }
        
        // Verify HMAC signature
        $expectedSignature = $this->generateSignature($cookieToken);
        
        if (!hash_equals($expectedSignature, $signature)) {
            throw new CsrfException('CSRF token signature invalid');
        }
    }

    /**
     * Generate HMAC signature for the token
     */
    private function generateSignature(string $token): string
    {
        return hash_hmac('sha256', $token, $this->serverSecret);
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
     * Build secure cookie string with all security attributes
     */
    private function buildCookieString(string $token): string
    {
        $cookie = "{$this->cookieName}=" . urlencode($token);
        
        if ($this->cookieOptions['path']) {
            $cookie .= "; Path={$this->cookieOptions['path']}";
        }
        
        if ($this->cookieOptions['domain']) {
            $cookie .= "; Domain={$this->cookieOptions['domain']}";
        }
        
        if ($this->cookieOptions['secure']) {
            $cookie .= "; Secure";
        }
        
        if ($this->cookieOptions['httponly']) {
            $cookie .= "; HttpOnly";
        }
        
        if ($this->cookieOptions['samesite']) {
            $cookie .= "; SameSite={$this->cookieOptions['samesite']}";
        }
        
        return $cookie;
    }

    /**
     * Get the signature for a token (for JavaScript to use)
     */
    public function getTokenSignature(string $token): string
    {
        return $this->generateSignature($token);
    }
} 