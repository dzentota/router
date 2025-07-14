<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware\Exception;

use Exception;

/**
 * Exception thrown when CSRF token validation fails
 */
class CsrfException extends Exception
{
    public function __construct(string $message = 'CSRF token validation failed', int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
} 