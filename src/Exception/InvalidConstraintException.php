<?php

declare(strict_types=1);

namespace dzentota\Router\Exception;

/**
 * Thrown when a route parameter has no constraint or an invalid constraint class.
 */
class InvalidConstraintException extends RouterException {}
