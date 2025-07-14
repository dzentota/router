<?php
declare(strict_types=1);

namespace dzentota\Router\Middleware\Contract;

use Psr\SimpleCache\CacheInterface;

/**
 * Interface for token storage, extends PSR-16 for compatibility
 */
interface TokenStorageInterface extends CacheInterface
{
    // Inherits all PSR-16 methods:
    // get($key, $default = null)
    // set($key, $value, $ttl = null)
    // delete($key)
    // clear()
    // getMultiple($keys, $default = null)
    // setMultiple($values, $ttl = null)
    // deleteMultiple($keys)
    // has($key)
} 