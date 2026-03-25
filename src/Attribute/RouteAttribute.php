<?php

declare(strict_types=1);

namespace dzentota\Router\Attribute;

/**
 * PHP 8 attribute for declarative route registration.
 *
 * Can be applied at **method level** to register a route, and at **class level**
 * to set a URI prefix that is prepended to all method-level routes.
 *
 * Method-level usage:
 * ```php
 * class UserController
 * {
 *     #[RouteAttribute('/users', methods: 'GET', name: 'users.index', tags: ['api'])]
 *     public function index(): ResponseInterface { ... }
 *
 *     #[RouteAttribute('/users/{id}', methods: 'GET', constraints: ['id' => UserId::class])]
 *     public function show(UserId $id): ResponseInterface { ... }
 * }
 * ```
 *
 * Class-level prefix:
 * ```php
 * #[RouteAttribute('/api/v1')]
 * class PostController
 * {
 *     #[RouteAttribute('/posts', methods: 'GET')]   // becomes /api/v1/posts
 *     public function index(): ResponseInterface { ... }
 * }
 * ```
 *
 * The `constraints` array must contain class-strings that implement
 * {@see \dzentota\TypedValue\Typed} — no plain regex strings.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class RouteAttribute
{
    /**
     * @param string            $path        URI pattern (e.g. '/users/{id}').
     * @param string|string[]   $methods     HTTP method(s) or 'ANY'. Defaults to 'GET'.
     * @param string|null       $name        Optional route name (e.g. 'users.show').
     * @param array             $constraints Typed constraints keyed by parameter name.
     * @param array             $defaults    Default values for optional parameters.
     * @param string[]          $tags        Tags for grouping/filtering (e.g. ['api', 'public']).
     */
    public function __construct(
        public readonly string       $path,
        public readonly string|array $methods     = 'GET',
        public readonly ?string      $name        = null,
        public readonly array        $constraints = [],
        public readonly array        $defaults    = [],
        public readonly array        $tags        = [],
    ) {}
}
