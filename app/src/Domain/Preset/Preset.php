<?php

declare(strict_types=1);

namespace App\Domain\Preset;

use App\Domain\Exception\InvalidValueException;

final readonly class Preset
{
    public function __construct(
        public PresetKey $key,
        public string $label,
    ) {
        if ('' === trim($label)) {
            throw new InvalidValueException('Preset label must not be empty.');
        }
    }
}
