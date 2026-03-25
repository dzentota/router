<?php

declare(strict_types=1);

namespace dzentota\Router;

use dzentota\Router\Exception\InvalidConstraintException;
use dzentota\TypedValue\Typed;

/**
 * Represents a single route definition with its pattern, method map, action, and metadata.
 *
 * Provides a fluent interface for setting constraints, defaults, name, and tags after
 * the route has been registered with the router.
 *
 * All parameter constraints MUST implement {@see Typed} — "parse, don't validate".
 */
class Route
{
    private array $constraints = [];
    private ?string $name = null;
    private array $defaults = [];
    private array $tags = [];

    /**
     * @param string  $pattern   The URI pattern (e.g. '/users/{id}').
     * @param array   $methodMap Map of HTTP-method → action (e.g. ['GET' => 'handler']).
     * @param mixed   $action    The handler (callable, 'Class@method', 'Class::method', or [class, method]).
     * @param Router  $router    Back-reference used by name() to register the name immediately.
     */
    public function __construct(
        private readonly string $pattern,
        private readonly array  $methodMap,
        private readonly mixed  $action,
        private readonly Router $router,
    ) {}

    // -------------------------------------------------------------------------
    // Fluent setters
    // -------------------------------------------------------------------------

    /**
     * Set TypedValue constraints for route parameters.
     *
     * Every value must be a fully-qualified class-string that implements {@see Typed}.
     * This enforces the "parse, don't validate" principle from the AppSecManifesto.
     *
     * @param  array<string, class-string<Typed>> $constraints
     * @throws InvalidConstraintException when a constraint class does not implement Typed.
     */
    public function where(array $constraints): self
    {
        foreach ($constraints as $param => $class) {
            if (!is_string($class) || !is_a($class, Typed::class, true)) {
                throw new InvalidConstraintException(
                    "Constraint for '{$param}' must be a class-string implementing " . Typed::class
                );
            }
        }
        $this->constraints = array_merge($this->constraints, $constraints);
        return $this;
    }

    /**
     * Assign a name to this route and register it in the router's reverse index.
     *
     * Can be called more than once — calling again renames the route (old name is
     * removed from the index).
     */
    public function name(string $name): self
    {
        $oldName    = $this->name;
        $this->name = $name;
        $this->router->_registerRouteName($this, $name, $oldName);
        return $this;
    }

    /**
     * Set default values for optional route parameters.
     *
     * Defaults are applied when an optional parameter is absent from the request URI.
     * They are developer-controlled values that bypass TypedValue parsing and are set
     * directly as request attributes.
     *
     * @param array<string, mixed> $defaults Parameter-name → default value.
     */
    public function defaults(array $defaults): self
    {
        $this->defaults = $defaults;
        return $this;
    }

    /**
     * Assign one or more tags to this route.
     *
     * Tags allow grouping and filtering routes (e.g. 'api', 'admin', 'public').
     * Duplicates are silently ignored.
     *
     * @param string|string[] $tags
     */
    public function tag(string|array $tags): self
    {
        foreach ((array)$tags as $t) {
            if (!in_array($t, $this->tags, true)) {
                $this->tags[] = $t;
            }
        }
        return $this;
    }

    // -------------------------------------------------------------------------
    // Getters
    // -------------------------------------------------------------------------

    public function getPattern(): string { return $this->pattern; }
    public function getMethodMap(): array { return $this->methodMap; }

    /** @return mixed callable|string|array */
    public function getAction(): mixed { return $this->action; }

    /** @return array<string, class-string<Typed>> */
    public function getConstraints(): array { return $this->constraints; }

    public function getName(): ?string { return $this->name; }

    /** @return array<string, mixed> */
    public function getDefaults(): array { return $this->defaults; }

    /** @return string[] */
    public function getTags(): array { return $this->tags; }
}
