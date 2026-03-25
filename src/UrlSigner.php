<?php

declare(strict_types=1);

namespace dzentota\Router;

use dzentota\Router\Exception\InvalidRouteException;

/**
 * Generates and verifies HMAC-SHA256 signed URLs with optional TTL.
 *
 * Signed URLs allow sharing time-limited links that cannot be tampered with.
 * The signature covers the full path and query string (including the expiry
 * timestamp), preventing both parameter substitution and expiry manipulation.
 *
 * Usage:
 * ```php
 * $signer = new UrlSigner($router, $_ENV['APP_KEY']);
 *
 * // Generate a signed URL that expires in 1 hour (default)
 * $url = $signer->sign('invoices.download', ['id' => '42']);
 * // → /invoices/42/download?expires=1234567890&signature=<hmac>
 *
 * // Verify before serving
 * if (!$signer->verify($url)) {
 *     // 403 or 410
 * }
 * ```
 *
 * Security notes:
 * - Store the signing key in an environment variable; never commit it.
 * - The key should be at least 32 random bytes (256 bits).
 * - HMAC uses SHA-256 and is compared with hash_equals() to prevent timing attacks.
 */
class UrlSigner
{
    /**
     * @param Router $router     Router used to generate the base URL.
     * @param string $signingKey Secret HMAC key (minimum 16 characters; 32+ recommended).
     * @param int    $defaultTtl Default time-to-live in seconds (default: 3600 = 1 hour).
     *
     * @throws \InvalidArgumentException when the key is shorter than 16 characters.
     */
    public function __construct(
        private readonly Router $router,
        #[\SensitiveParameter]
        private readonly string $signingKey,
        private readonly int $defaultTtl = 3600,
    ) {
        if (strlen($signingKey) < 32) {
            throw new \InvalidArgumentException(
                'UrlSigner: signing key must be at least 32 characters (256 bits recommended)'
            );
        }
    }

    /**
     * Generate a signed URL for a named route.
     *
     * The returned URL contains `?expires=<unix-timestamp>&signature=<hmac-sha256>`.
     * Parameters are validated against their Typed constraints via the router.
     *
     * @param  string   $name       Named route identifier.
     * @param  array    $parameters Route parameter values.
     * @param  int|null $ttl        TTL in seconds; null uses the constructor default.
     *
     * @throws InvalidRouteException Propagated from Router::generateUrl().
     */
    public function sign(string $name, array $parameters = [], ?int $ttl = null): string
    {
        $url     = $this->router->generateUrl($name, $parameters);
        $expires = time() + ($ttl ?? $this->defaultTtl);
        // Use http_build_query for consistent encoding with verify()
        $base    = $url . '?' . http_build_query(['expires' => $expires]);
        $sig     = hash_hmac('sha256', $base, $this->signingKey);

        return $base . '&' . http_build_query(['signature' => $sig]);
    }

    /**
     * Verify a signed URL produced by {@see sign()}.
     *
     * Returns false when:
     * - The `signature` or `expires` query parameters are absent.
     * - The URL has expired (`expires` is in the past).
     * - The signature does not match (tampered URL or wrong key).
     *
     * Uses {@see hash_equals()} to prevent timing-based side-channel attacks.
     */
    public function verify(string $url): bool
    {
        $qPos = strpos($url, '?');
        if ($qPos === false) {
            return false;
        }

        $path     = substr($url, 0, $qPos);
        $queryStr = substr($url, $qPos + 1);

        parse_str($queryStr, $query);

        if (!isset($query['signature'], $query['expires'])) {
            return false;
        }

        // Reject expired URLs before computing the HMAC (avoids timing oracle).
        if ((int)$query['expires'] < time()) {
            return false;
        }

        $signature = $query['signature'];
        unset($query['signature']);

        // Reconstruct the string that was originally signed.
        $rebuilt  = $path . '?' . http_build_query($query);
        $expected = hash_hmac('sha256', $rebuilt, $this->signingKey);

        return hash_equals($expected, $signature);
    }
}
