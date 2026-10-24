<?php

declare(strict_types=1);

namespace App\Domain\HomeAssistant;

use App\Domain\Exception\InvalidValueException;

/**
 * A Home Assistant entity id such as "climate.living_room".
 */
final readonly class EntityId implements \Stringable
{
    private const string PATTERN = '/^(?!.+__)(?!_)[\da-z_]+(?<!_)\.(?!_)[\da-z_]+(?<!_)$/D';

    private string $value;

    public function __construct(string $value)
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw new InvalidValueException(\sprintf('"%s" is not a valid entity id (expected "domain.object_id").', $value));
        }

        $this->value = $value;
    }

    public function domain(): string
    {
        return explode('.', $this->value, 2)[0];
    }

    public function objectId(): string
    {
        return explode('.', $this->value, 2)[1];
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
