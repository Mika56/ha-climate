<?php

declare(strict_types=1);

namespace App\Domain\Room;

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Temperature;
use App\Domain\Exception\InvalidValueException;
use App\Domain\Exception\PresetTemperatureNotFoundException;
use App\Domain\Preset\PresetKey;

/**
 * Immutable map (preset, heat|cool) => temperature for one room.
 */
final readonly class PresetTemperatures
{
    /**
     * @param array<string, Temperature> $temperatures indexed by "<preset>/<mode>"
     */
    private function __construct(private array $temperatures)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function with(PresetKey $key, HvacMode $mode, Temperature $temperature): self
    {
        if (HvacMode::Off === $mode) {
            throw new InvalidValueException('Preset temperatures only exist for heat and cool modes.');
        }

        return new self([...$this->temperatures, self::index($key, $mode) => $temperature]);
    }

    public function has(PresetKey $key, HvacMode $mode): bool
    {
        return isset($this->temperatures[self::index($key, $mode)]);
    }

    public function for(PresetKey $key, HvacMode $mode): Temperature
    {
        return $this->temperatures[self::index($key, $mode)]
            ?? throw PresetTemperatureNotFoundException::forPreset($key, $mode);
    }

    private static function index(PresetKey $key, HvacMode $mode): string
    {
        return $key.'/'.$mode->value;
    }
}
