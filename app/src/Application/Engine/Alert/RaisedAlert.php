<?php

declare(strict_types=1);

namespace App\Application\Engine\Alert;

use App\Infrastructure\Persistence\Entity\Alert;

/**
 * Outcome of {@see AlertService::raise()}: the alert, and whether this call opened it (as opposed to finding
 * it already open).
 */
final readonly class RaisedAlert
{
    public function __construct(
        public Alert $alert,
        public bool $opened,
    ) {
    }
}
