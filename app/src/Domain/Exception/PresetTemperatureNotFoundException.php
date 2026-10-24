<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\Climate\HvacMode;
use App\Domain\Preset\PresetKey;

final class PresetTemperatureNotFoundException extends \DomainException implements DomainExceptionInterface
{
    public static function forPreset(PresetKey $key, HvacMode $mode): self
    {
        return new self(\sprintf('No temperature defined for preset "%s" in mode "%s".', $key, $mode->value));
    }
}
