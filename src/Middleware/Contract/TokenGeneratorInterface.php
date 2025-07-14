<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware\Contract;

/**
 * Interface for generating cryptographically secure random tokens
 */
interface TokenGeneratorInterface
{
    /**
     * Generate a cryptographically secure random token
     *
     * @param int $length Token length in bytes
     * @return string Base64-encoded token
     */
    public function generateToken(int $length = 32): string;
} 