<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware;

use dzentota\Router\Middleware\Security\TokenGenerator;
use dzentota\Router\Middleware\Contract\TokenGeneratorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Content Security Policy (CSP) Middleware
 * 
 * Adds CSP headers to responses to prevent XSS and other injection attacks.
 * Supports nonce generation for inline scripts/styles.
 */
class CspMiddleware implements MiddlewareInterface
{
    private array $directives;
    private bool $reportOnly;
    private string $reportUri;
    private bool $generateNonce;
    private TokenGeneratorInterface $tokenGenerator;

    /**
     * Constructor
     *
     * @param array $directives CSP directives and their sources
     * @param bool $reportOnly Whether to use report-only mode
     * @param string $reportUri URI for violation reports
     * @param bool $generateNonce Whether to generate nonce for scripts/styles
     * @param TokenGeneratorInterface|null $tokenGenerator Token generator for nonce creation
     */
    public function __construct(
        array $directives = [],
        bool $reportOnly = false,
        string $reportUri = '',
        bool $generateNonce = true,
        ?TokenGeneratorInterface $tokenGenerator = null
    ) {
        $this->reportOnly = $reportOnly;
        $this->reportUri = $reportUri;
        $this->generateNonce = $generateNonce;
        $this->tokenGenerator = $tokenGenerator ?? new TokenGenerator();

        // Security-first default directives
        $this->directives = array_merge([
            'default-src' => ["'self'"],
            'script-src' => ["'self'"],
            'style-src' => ["'self'"],
            'img-src' => ["'self'", 'data:', 'https:'],
            'font-src' => ["'self'"],
            'connect-src' => ["'self'"],
            'media-src' => ["'self'"],
            'object-src' => ["'none'"],                    // Block plugins
            'child-src' => ["'self'"],
            'frame-ancestors' => ["'none'"],               // Prevent framing
            'form-action' => ["'self'"],                   // Restrict form submissions
            'base-uri' => ["'self'"],                      // Restrict base tag
            'manifest-src' => ["'self'"],
            'worker-src' => ["'self'"],
            'upgrade-insecure-requests' => [],             // Force HTTPS
            'block-all-mixed-content' => [],               // Block mixed content
        ], $directives);
    }

    /**
     * Process request and add CSP headers to response
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Generate nonce if enabled
        $nonce = null;
        if ($this->generateNonce) {
            $nonce = $this->tokenGenerator->generateToken(16);

            // Add nonce to request attributes for template access
            $request = $request->withAttribute('csp_nonce', $nonce);
            
            // Add nonce to script-src and style-src
            $this->addNonceToDirective('script-src', $nonce);
            $this->addNonceToDirective('style-src', $nonce);
        }

        // Process request
        $response = $handler->handle($request);

        // Build CSP header
        $cspHeader = $this->buildCspHeader();
        
        // Add CSP header to response
        $headerName = $this->reportOnly ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        
        return $response->withHeader($headerName, $cspHeader);
    }

    /**
     * Add nonce to a directive
     */
    private function addNonceToDirective(string $directive, string $nonce): void
    {
        if (!isset($this->directives[$directive])) {
            $this->directives[$directive] = [];
        }
        
        $nonceValue = "'nonce-{$nonce}'";
        if (!in_array($nonceValue, $this->directives[$directive], true)) {
            $this->directives[$directive][] = $nonceValue;
        }
    }

    /**
     * Build CSP header string
     */
    private function buildCspHeader(): string
    {
        $policies = [];
        
        foreach ($this->directives as $directive => $sources) {
            if (empty($sources)) {
                // Directive without sources (like upgrade-insecure-requests)
                $policies[] = $directive;
            } else {
                $policies[] = $directive . ' ' . implode(' ', $sources);
            }
        }
        
        $csp = implode('; ', $policies);
        
        // Add report-uri if configured
        if (!empty($this->reportUri)) {
            $csp .= '; report-uri ' . $this->reportUri;
        }
        
         return $csp;
    }

    /**
     * Add source to directive (fluent interface)
     */
    public function addSource(string $directive, string $source): self
    {
        if (!isset($this->directives[$directive])) {
            $this->directives[$directive] = [];
        }
        
        if (!in_array($source, $this->directives[$directive], true)) {
            $this->directives[$directive][] = $source;
        }
        
        return $this;
    }

    /**
     * Set directive sources (fluent interface)
     */
    public function setDirective(string $directive, array $sources): self
    {
        $this->directives[$directive] = $sources;
        return $this;
    }

    /**
     * Get the token generator instance
     *
     * @return TokenGeneratorInterface
     */
    public function getTokenGenerator(): TokenGeneratorInterface
    {
        return $this->tokenGenerator;
    }

    /**
     * Set the token generator instance
     *
     * @param TokenGeneratorInterface $tokenGenerator
     * @return self
     */
    public function setTokenGenerator(TokenGeneratorInterface $tokenGenerator): self
    {
        $this->tokenGenerator = $tokenGenerator;
        return $this;
    }

    /**
     * Allow inline scripts (use with caution)
     */
    public function allowInlineScripts(): self
    {
        return $this->addSource('script-src', "'unsafe-inline'");
    }

    /**
     * Allow inline styles (use with caution)
     */
    public function allowInlineStyles(): self
    {
        return $this->addSource('style-src', "'unsafe-inline'");
    }

    /**
     * Allow eval (highly discouraged)
     */
    public function allowEval(): self
    {
        return $this->addSource('script-src', "'unsafe-eval'");
    }

    /**
     * Enable report-only mode
     */
    public function reportOnly(bool $enable = true): self
    {
        $this->reportOnly = $enable;
        return $this;
    }

    /**
     * Set report URI
     */
    public function setReportUri(string $uri): self
    {
        $this->reportUri = $uri;
        return $this;
    }
}

