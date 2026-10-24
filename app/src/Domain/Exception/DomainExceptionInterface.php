<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Marker for every exception raised by the domain layer.
 */
interface DomainExceptionInterface extends \Throwable
{
}
