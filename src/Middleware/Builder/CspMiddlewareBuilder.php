<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware\Builder;

use dzentota\Router\Middleware\Contract\TokenGeneratorInterface;
use dzentota\Router\Middleware\CspMiddleware;
use dzentota\Router\Middleware\Security\TokenGenerator;

/**
 * Builder for CspMiddleware
 *
 * Provides a fluent interface for configuring Content Security Policy middleware.
 */
class CspMiddlewareBuilder
{
    /**
     * CSP directives
     *
     * @var array
     */
    private array $directives = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'"],
        'style-src' => ["'self'"],
        'img-src' => ["'self'", 'data:', 'https:'],
        'font-src' => ["'self'"],
        'connect-src' => ["'self'"],
        'media-src' => ["'self'"],
        'object-src' => ["'none'"],
        'child-src' => ["'self'"],
        'frame-ancestors' => ["'none'"],
        'form-action' => ["'self'"],
        'base-uri' => ["'self'"],
        'manifest-src' => ["'self'"],
        'worker-src' => ["'self'"],
        'upgrade-insecure-requests' => [],
        'block-all-mixed-content' => [],
    ];

    /**
     * Whether to use report-only mode
     *
     * @var bool
     */
    private bool $reportOnly = false;

    /**
     * URI for violation reports
     *
     * @var string
     */
    private string $reportUri = '';

    /**
     * Whether to generate nonce for scripts/styles
     *
     * @var bool
     */
    private bool $generateNonce = true;

    /**
     * Token generator for nonce creation
     *
     * @var TokenGeneratorInterface|null
     */
    private ?TokenGeneratorInterface $tokenGenerator = null;

    /**
     * Set a specific CSP directive
     *
     * @param string $directive Directive name (e.g., 'script-src')
     * @param array $sources Sources for the directive
     * @return self
     */
    public function withDirective(string $directive, array $sources): self
    {
        $clone = clone $this;
        $clone->directives[$directive] = $sources;
        return $clone;
    }

    /**
     * Set multiple CSP directives at once
     *
     * @param array $directives Array of directives and their sources
     * @return self
     */
    public function withDirectives(array $directives): self
    {
        $clone = clone $this;
        $clone->directives = array_merge($clone->directives, $directives);
        return $clone;
    }

    /**
     * Enable or disable report-only mode
     *
     * @param bool $enabled Whether to enable report-only mode
     * @return self
     */
    public function reportOnly(bool $enabled = true): self
    {
        $clone = clone $this;
        $clone->reportOnly = $enabled;
        return $clone;
    }

    /**
     * Set URI for violation reports
     *
     * @param string $uri Report URI
     * @return self
     */
    public function withReportUri(string $uri): self
    {
        $clone = clone $this;
        $clone->reportUri = $uri;
        return $clone;
    }

    /**
     * Enable or disable nonce generation for inline scripts/styles
     *
     * @param bool $enabled Whether to enable nonce generation
     * @return self
     */
    public function withNonce(bool $enabled = true): self
    {
        $clone = clone $this;
        $clone->generateNonce = $enabled;
        return $clone;
    }

    /**
     * Set token generator for nonce creation
     *
     * @param TokenGeneratorInterface $tokenGenerator Token generator
     * @return self
     */
    public function withTokenGenerator(TokenGeneratorInterface $tokenGenerator): self
    {
        $clone = clone $this;
        $clone->tokenGenerator = $tokenGenerator;
        return $clone;
    }

    /**
     * Allow inline scripts with nonce
     *
     * @return self
     */
    public function allowInlineScripts(): self
    {
        return $this->modifyDirective('script-src', ["'unsafe-inline'"]);
    }

    /**
     * Allow inline styles with nonce
     *
     * @return self
     */
    public function allowInlineStyles(): self
    {
        return $this->modifyDirective('style-src', ["'unsafe-inline'"]);
    }

    /**
     * Allow eval() in scripts
     *
     * @return self
     */
    public function allowEval(): self
    {
        return $this->modifyDirective('script-src', ["'unsafe-eval'"]);
    }

    /**
     * Add domain to allowed script sources
     *
     * @param string|array $domain Domain(s) to allow
     * @return self
     */
    public function allowScriptFrom($domain): self
    {
        return $this->modifyDirective('script-src', is_array($domain) ? $domain : [$domain]);
    }

    /**
     * Add domain to allowed style sources
     *
     * @param string|array $domain Domain(s) to allow
     * @return self
     */
    public function allowStyleFrom($domain): self
    {
        return $this->modifyDirective('style-src', is_array($domain) ? $domain : [$domain]);
    }

    /**
     * Add domain to allowed image sources
     *
     * @param string|array $domain Domain(s) to allow
     * @return self
     */
    public function allowImageFrom($domain): self
    {
        return $this->modifyDirective('img-src', is_array($domain) ? $domain : [$domain]);
    }

    /**
     * Helper to modify directive by adding sources
     *
     * @param string $directive Directive name
     * @param array $sources Sources to add
     * @return self
     */
    private function modifyDirective(string $directive, array $sources): self
    {
        $clone = clone $this;
        if (!isset($clone->directives[$directive])) {
            $clone->directives[$directive] = [];
        }
        $clone->directives[$directive] = array_merge($clone->directives[$directive], $sources);
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
     * Build the CspMiddleware
     *
     * @return CspMiddleware
     */
    public function build(): CspMiddleware
    {
        return new CspMiddleware(
            $this->directives,
            $this->reportOnly,
            $this->reportUri,
            $this->generateNonce,
            $this->tokenGenerator ?? new TokenGenerator()
        );
    }
}
