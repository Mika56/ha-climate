<?php

declare(strict_types=1);

namespace App\Domain\Preset;

use App\Domain\Exception\InvalidValueException;

/**
 * Slug identifying a preset (comfort, eco, night, away...).
 */
final readonly class PresetKey implements \Stringable
{
    private string $value;

    public function __construct(string $value)
    {
        if (1 !== preg_match('/^[a-z0-9_]{1,32}$/D', $value)) {
            throw new InvalidValueException(\sprintf('"%s" is not a valid preset key (expected [a-z0-9_]{1,32}).', $value));
        }

        $this->value = $value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
