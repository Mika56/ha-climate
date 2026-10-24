<?php

declare(strict_types=1);

namespace App\Domain\Decision;

use App\Domain\Exception\InvalidValueException;

/**
 * Why a decision was taken: an i18n key plus its parameters.
 */
final readonly class Reason
{
    /**
     * @param array<string, scalar|null> $params
     */
    public function __construct(
        public string $code,
        public array $params = [],
    ) {
        if ('' === trim($code)) {
            throw new InvalidValueException('Reason code must not be empty.');
        }
    }
}
