<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware\Contract;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use dzentota\Router\Middleware\Exception\CsrfException;

/**
 * Interface for CSRF protection strategies
 */
interface CsrfProtectionStrategyInterface
{
    /**
     * Attach CSRF token/cookie to the response
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    public function attachToken(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface;

    /**
     * Validate the request for CSRF protection
     *
     * @param ServerRequestInterface $request
     * @throws CsrfException When validation fails
     */
    public function validateRequest(ServerRequestInterface $request): void;
} 