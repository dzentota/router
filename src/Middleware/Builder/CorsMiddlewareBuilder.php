<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware\Builder;

use dzentota\Router\Middleware\CorsMiddleware;

/**
 * Builder for CorsMiddleware
 *
 * Provides a fluent interface for configuring CORS middleware.
 */
class CorsMiddlewareBuilder
{
    /**
     * CORS configuration
     *
     * @var array
     */
    private array $config = [
        'allowed_origins' => [],           // No origins allowed by default
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-CSRF-TOKEN'],
        'exposed_headers' => ['X-CSRF-TOKEN'],
        'allow_credentials' => false,      // Disabled by default for security
        'max_age' => 86400,               // 24 hours
        'require_exact_origin' => true,   // No wildcard matching by default
    ];

    /**
     * Set allowed origins
     *
     * @param string|array $origins Single origin or array of allowed origins
     * @return self
     */
    public function withAllowedOrigins($origins): self
    {
        $clone = clone $this;
        $clone->config['allowed_origins'] = is_array($origins) ? $origins : [$origins];
        return $clone;
    }

    /**
     * Add an allowed origin
     *
     * @param string $origin Origin to allow
     * @return self
     */
    public function addAllowedOrigin(string $origin): self
    {
        $clone = clone $this;
        if (!in_array($origin, $clone->config['allowed_origins'])) {
            $clone->config['allowed_origins'][] = $origin;
        }
        return $clone;
    }

    /**
     * Allow all origins (sets allowed_origins to ['*'])
     * Warning: This is less secure and should be used with caution
     *
     * @return self
     */
    public function allowAllOrigins(): self
    {
        $clone = clone $this;
        $clone->config['allowed_origins'] = ['*'];
        return $clone;
    }

    /**
     * Set allowed HTTP methods
     *
     * @param string|array $methods Single method or array of allowed methods
     * @return self
     */
    public function withAllowedMethods($methods): self
    {
        $clone = clone $this;
        $clone->config['allowed_methods'] = is_array($methods) ? $methods : [$methods];
        return $clone;
    }

    /**
     * Add an allowed HTTP method
     *
     * @param string $method Method to allow
     * @return self
     */
    public function addAllowedMethod(string $method): self
    {
        $clone = clone $this;
        if (!in_array($method, $clone->config['allowed_methods'])) {
            $clone->config['allowed_methods'][] = $method;
        }
        return $clone;
    }

    /**
     * Set allowed headers
     *
     * @param string|array $headers Single header or array of allowed headers
     * @return self
     */
    public function withAllowedHeaders($headers): self
    {
        $clone = clone $this;
        $clone->config['allowed_headers'] = is_array($headers) ? $headers : [$headers];
        return $clone;
    }

    /**
     * Add an allowed header
     *
     * @param string $header Header to allow
     * @return self
     */
    public function addAllowedHeader(string $header): self
    {
        $clone = clone $this;
        if (!in_array($header, $clone->config['allowed_headers'])) {
            $clone->config['allowed_headers'][] = $header;
        }
        return $clone;
    }

    /**
     * Set exposed headers
     *
     * @param string|array $headers Single header or array of exposed headers
     * @return self
     */
    public function withExposedHeaders($headers): self
    {
        $clone = clone $this;
        $clone->config['exposed_headers'] = is_array($headers) ? $headers : [$headers];
        return $clone;
    }

    /**
     * Add an exposed header
     *
     * @param string $header Header to expose
     * @return self
     */
    public function addExposedHeader(string $header): self
    {
        $clone = clone $this;
        if (!in_array($header, $clone->config['exposed_headers'])) {
            $clone->config['exposed_headers'][] = $header;
        }
        return $clone;
    }

    /**
     * Set whether to allow credentials
     *
     * @param bool $allow Whether to allow credentials
     * @return self
     */
    public function allowCredentials(bool $allow = true): self
    {
        $clone = clone $this;
        $clone->config['allow_credentials'] = $allow;
        return $clone;
    }

    /**
     * Set max age for preflight requests
     *
     * @param int $seconds Max age in seconds
     * @return self
     */
    public function withMaxAge(int $seconds): self
    {
        $clone = clone $this;
        $clone->config['max_age'] = $seconds;
        return $clone;
    }

    /**
     * Set whether to require exact origin matching
     *
     * @param bool $require Whether to require exact origin matching
     * @return self
     */
    public function requireExactOrigin(bool $require = true): self
    {
        $clone = clone $this;
        $clone->config['require_exact_origin'] = $require;
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
     * Build the CorsMiddleware
     *
     * @return CorsMiddleware
     */
    public function build(): CorsMiddleware
    {
        return new CorsMiddleware($this->config);
    }
}
