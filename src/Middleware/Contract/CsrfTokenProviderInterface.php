<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware\Contract;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Optional companion to CsrfProtectionStrategyInterface for strategies that can
 * expose a form token to the request handler (server-rendered forms).
 *
 * CsrfMiddleware calls prepareRequest() before invoking the handler and later
 * passes the returned request to attachToken(), so a strategy may stash state
 * (e.g. a freshly generated cookie token) in request attributes.
 */
interface CsrfTokenProviderInterface
{
    /**
     * Return the request with the form token stored under $tokenAttribute.
     * If no token can be produced (e.g. no session), return the request unchanged.
     */
    public function prepareRequest(ServerRequestInterface $request, string $tokenAttribute): ServerRequestInterface;
}
