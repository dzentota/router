<?php

declare(strict_types=1);

namespace dzentota\Router\Loader;

use dzentota\Router\Attribute\RouteAttribute;
use dzentota\Router\Router;

/**
 * Loads routes from PHP 8 {@see RouteAttribute} attributes.
 *
 * Supports:
 * - Method-level attributes to register individual routes.
 * - A single class-level attribute to set a URI prefix for all methods in the class.
 * - Repeatable method-level attributes (one method can handle multiple routes).
 *
 * Example:
 * ```php
 * $loader = new AttributeLoader($router);
 * $loader->loadFromClass(UserController::class);
 * ```
 */
class AttributeLoader
{
    public function __construct(private readonly Router $router) {}

    /**
     * Scan all public methods of the given class for {@see RouteAttribute} attributes
     * and register the corresponding routes.
     *
     * If the class itself carries a {@see RouteAttribute}, its `path` is used as a
     * URI prefix prepended to every method-level route path.
     *
     * @param string $class Fully-qualified class name.
     *
     * @throws \ReflectionException when the class does not exist.
     */
    public function loadFromClass(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        // Collect an optional class-level URI prefix.
        $classPrefix = '';
        foreach ($reflection->getAttributes(RouteAttribute::class) as $classAttr) {
            $classPrefix = $classAttr->newInstance()->path;
            break; // Only the first class-level attribute acts as prefix.
        }

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(RouteAttribute::class) as $attrRef) {
                $attr  = $attrRef->newInstance();
                $path  = $classPrefix . $attr->path;
                $route = $this->router->addRoute(
                    (array)$attr->methods,
                    $path,
                    [$class, $method->getName()],
                    $attr->constraints,
                    $attr->name,
                );

                if (!empty($attr->defaults)) {
                    $route->defaults($attr->defaults);
                }

                if (!empty($attr->tags)) {
                    $route->tag($attr->tags);
                }
            }
        }
    }

    /**
     * Recursively scan all PHP files in a directory and load routes from any class
     * that carries {@see RouteAttribute} attributes.
     *
     * The directory is scanned recursively. Each PHP file is `require_once`'d so
     * that new classes become available to `get_declared_classes()`.
     *
     * **Note:** Only classes whose source file matches the discovered file path are
     * processed, preventing re-processing of previously declared classes.
     *
     * @param string $directory Absolute path to the directory to scan.
     */
    public function loadFromDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            require_once $file->getPathname();

            foreach (get_declared_classes() as $declaredClass) {
                try {
                    $ref = new \ReflectionClass($declaredClass);
                } catch (\ReflectionException) {
                    continue;
                }

                if ($ref->getFileName() !== $file->getPathname()) {
                    continue;
                }

                if (!empty($ref->getAttributes(RouteAttribute::class))) {
                    $this->loadFromClass($declaredClass);
                    continue;
                }

                foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    if (!empty($method->getAttributes(RouteAttribute::class))) {
                        $this->loadFromClass($declaredClass);
                        break;
                    }
                }
            }
        }
    }
}
