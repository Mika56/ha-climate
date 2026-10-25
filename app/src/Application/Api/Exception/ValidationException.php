<?php

declare(strict_types=1);

namespace App\Application\Api\Exception;

/**
 * A request that is well formed but cannot be applied: unknown references, duplicates... Violations carry
 * the property path (`devices[0].entityId`) and a message already translated in the request locale.
 */
final class ValidationException extends ApiException
{
    /**
     * @param list<array{path: string, message: string}> $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(422, 'validation_failed', 'The request cannot be applied.');
    }
}
