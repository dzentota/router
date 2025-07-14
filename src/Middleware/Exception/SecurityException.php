<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware\Exception;

use Exception;

/**
 * General security exception for middleware security violations
 */
class SecurityException extends Exception
{
    public function __construct(string $message = 'Security violation detected', int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
} 