<?php

declare(strict_types=1);

namespace App\Application\Api;

/**
 * The Home Assistant user behind a request, as the Supervisor reports it through the ingress headers.
 */
final readonly class IngressUser
{
    public function __construct(
        public string $id,
        public string $name,
        public string $displayName,
    ) {
    }
}
