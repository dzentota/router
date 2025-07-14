<?php
declare(strict_types=1);

namespace dzentota\TypedValue;

use dzentota\TypedValue\Typed;
use dzentota\TypedValue\TypedValue;
use dzentota\TypedValue\ValidationResult;

/**
 * Mock UserId class for testing
 */
class UserId implements Typed
{
    use TypedValue;

    public static function validate($value): ValidationResult
    {
        $result = new ValidationResult();
        if (!is_numeric($value) || $value <= 0) {
            $result->addError('Invalid user ID');
        }
        return $result;
    }

    /**
     * Convert to native PHP type
     *
     * @return int
     */
    public function toNative(): int
    {
        return (int)$this->value;
    }
}
