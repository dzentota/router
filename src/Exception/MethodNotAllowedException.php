<?php

declare(strict_types=1);

namespace dzentota\Router\Exception;

class MethodNotAllowedException extends \Exception
{
    private array $allowedMethods;

    /**
     * @param string $message
     * @param array $allowedMethods
     */
    public function __construct(string $message, array $allowedMethods)
    {
        $this->allowedMethods = $allowedMethods;
        parent::__construct($message);
    }

    /**
     * @return array
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }

}