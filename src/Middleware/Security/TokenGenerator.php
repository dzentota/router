<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware\Security;

use dzentota\Router\Middleware\Contract\TokenGeneratorInterface;

/**
 * Cryptographically secure token generator using random_bytes()
 */
class TokenGenerator implements TokenGeneratorInterface
{
    /**
     * Generate a cryptographically secure random token
     *
     * @param int $length Token length in bytes
     * @return string Base64-encoded token
     */
    public function generateToken(int $length = 32): string
    {
        if ($length < 16) {
            throw new \InvalidArgumentException('Token length must be at least 16 bytes for security');
        }
        
        return bin2hex(random_bytes($length));
    }
} 