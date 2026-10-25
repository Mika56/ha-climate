<?php

declare(strict_types=1);

namespace App\Application\Api\Exception;

final class NotFoundException extends ApiException
{
    public function __construct(string $what, string $identifier)
    {
        parent::__construct(404, 'not_found', \sprintf('%s "%s" does not exist.', $what, $identifier));
    }
}
