<?php

declare(strict_types=1);

namespace App\Application\Engine\Heating;

use App\Application\Engine\EngineSnapshot;
use App\Application\Engine\Facts\CurrentTemperatureContributor;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Temperature;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Exception\InvalidValueException;
use App\Infrastructure\Persistence\Entity\HeatingRate;
use App\Infrastructure\Persistence\Repository\HeatingRateRepository;
use App\Infrastructure\Persistence\Repository\RoomRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Learns how fast each room warms up (or cools down), in °C per hour, to size the preheat lead.
 *
 * An episode starts when a device of the room runs in the season's mode with a setpoint at least one degree
 * beyond the room temperature, and ends when the room reaches that setpoint. A finished episode of at least
 * ten minutes yields a sample, folded into the stored rate with an exponentially weighted moving average
 * (weight 0.3 for the new sample). An episode is dropped, without a sample, as soon as it is interrupted:
 * the device leaves the mode (window cut, season change, switched off), its setpoint changes, or it lasts
 * longer than eight hours.
 *
 * Episodes live in memory only; one cut short by an engine restart is simply not learned from. The learner is
 * fed by {@see \App\Application\Engine\Facts\HeatingRateContributor} on every evaluation of the room.
 */
final class HeatingRateLearner
{
    public const float ALPHA = 0.3;
    public const float MIN_DISTANCE = 1.0;
    public const int MIN_EPISODE_SECONDS = 600;
    public const int MAX_EPISODE_SECONDS = 28800;
    public const float MAX_RATE = 10.0;

    /** @var array<string, HeatingEpisode> by room and mode */
    private array $episodes = [];

    /** @var array<string, float|null> rates read or written so far, by room and mode */
    private array $rates = [];

    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly HeatingRateRepository $storedRates,
    ) {
    }

    /**
     * The learned rate of the room in the given mode, if there is one.
     */
    public function rate(RoomConfiguration $room, HvacMode $mode): ?float
    {
        if (HvacMode::Off === $mode) {
            return null;
        }

        $key = self::key($room, $mode);
        if (!\array_key_exists($key, $this->rates)) {
            $entity = $this->rooms->find(Uuid::fromString((string) $room->id));
            $this->rates[$key] = null === $entity ? null : $this->storedRates->findOneByRoomAndMode($entity, $mode)?->getDegreesPerHour();
        }

        return $this->rates[$key];
    }

    /**
     * Follows the room's episode with what the devices and the sensor report at this instant.
     */
    public function observe(RoomConfiguration $room, EngineSnapshot $snapshot): void
    {
        $active = $snapshot->configuration->season->activeHvacMode();
        $this->forgetOtherModes($room, $active);
        if (HvacMode::Off === $active) {
            return;
        }

        $key = self::key($room, $active);
        $setpoint = $this->setpoint($room, $active, $snapshot);
        $episode = $this->episodes[$key] ?? null;
        if (null !== $episode && (null === $setpoint || !$setpoint->equals($episode->target) || $this->isTooLong($episode, $snapshot->now))) {
            unset($this->episodes[$key]);
            $episode = null;
        }

        $current = CurrentTemperatureContributor::read($room, $snapshot->states);
        if (null === $setpoint || null === $current) {
            return;
        }

        if (null === $episode) {
            $this->start($key, $active, $setpoint, $current, $snapshot->now);

            return;
        }

        if ($episode->isReachedBy($current)) {
            unset($this->episodes[$key]);
            $this->learn($room, $episode, $current, $snapshot->now);
        }
    }

    private function start(string $key, HvacMode $mode, Temperature $setpoint, Temperature $current, \DateTimeImmutable $now): void
    {
        $distance = HvacMode::Heat === $mode ? $setpoint->toFloat() - $current->toFloat() : $current->toFloat() - $setpoint->toFloat();
        if ($distance >= self::MIN_DISTANCE) {
            $this->episodes[$key] = new HeatingEpisode($mode, $setpoint, $current, $now);
        }
    }

    private function learn(RoomConfiguration $room, HeatingEpisode $episode, Temperature $current, \DateTimeImmutable $now): void
    {
        $seconds = $now->getTimestamp() - $episode->startedAt->getTimestamp();
        if ($seconds < self::MIN_EPISODE_SECONDS) {
            return;
        }

        $sample = min(self::MAX_RATE, $episode->degreesTravelled($current) / ($seconds / 3600));
        $this->record($room, $episode->mode, $sample, $now);
    }

    private function record(RoomConfiguration $room, HvacMode $mode, float $sample, \DateTimeImmutable $now): void
    {
        $entity = $this->rooms->find(Uuid::fromString((string) $room->id));
        if (null === $entity) {
            return;
        }

        $stored = $this->storedRates->findOneByRoomAndMode($entity, $mode);
        if (null === $stored) {
            $stored = new HeatingRate($entity, $mode, $sample, $now);
            $rate = $sample;
        } else {
            $rate = self::ALPHA * $sample + (1 - self::ALPHA) * $stored->getDegreesPerHour();
            $stored->learn($rate, $now);
        }

        $this->storedRates->save($stored);
        $this->rates[self::key($room, $mode)] = $rate;
    }

    /**
     * The setpoint of the first device of the room running in the given mode.
     */
    private function setpoint(RoomConfiguration $room, HvacMode $mode, EngineSnapshot $snapshot): ?Temperature
    {
        foreach ($room->devices as $device) {
            $state = $snapshot->states->get($device->entityId);
            $value = $state?->attributes['temperature'] ?? null;
            if ($mode->value !== $state?->state || !is_numeric($value)) {
                continue;
            }

            try {
                return new Temperature((float) $value);
            } catch (InvalidValueException) {
                continue;
            }
        }

        return null;
    }

    private function isTooLong(HeatingEpisode $episode, \DateTimeImmutable $now): bool
    {
        return $now->getTimestamp() - $episode->startedAt->getTimestamp() > self::MAX_EPISODE_SECONDS;
    }

    private function forgetOtherModes(RoomConfiguration $room, HvacMode $active): void
    {
        foreach ([HvacMode::Heat, HvacMode::Cool] as $mode) {
            if ($mode !== $active) {
                unset($this->episodes[self::key($room, $mode)]);
            }
        }
    }

    private static function key(RoomConfiguration $room, HvacMode $mode): string
    {
        return $room->id.'/'.$mode->value;
    }
}
