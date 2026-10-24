<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Raised when a value object or an aggregate invariant is violated.
 */
final class InvalidValueException extends \InvalidArgumentException implements DomainExceptionInterface
{
}
