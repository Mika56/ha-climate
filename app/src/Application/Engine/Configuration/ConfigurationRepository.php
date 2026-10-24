<?php

declare(strict_types=1);

namespace App\Application\Engine\Configuration;

use App\Domain\Climate\Season;
use App\Domain\Decision\GlobalPolicy;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\VacationPeriod;
use App\Domain\HomeAssistant\EntityId;
use App\Domain\Room\RoomId;
use App\Domain\Schedule\ScheduleId;
use App\Domain\Schedule\WeeklySchedule;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The engine's view of the configuration: loaded once from the database, kept in memory, and refreshed
 * only by {@see reload()} (called when the web side says the configuration changed).
 */
final class ConfigurationRepository
{
    private ?EngineConfiguration $cached = null;

    public function __construct(
        private readonly ConfigurationLoader $loader,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function snapshot(): EngineConfiguration
    {
        return $this->cached ??= $this->loader->load();
    }

    /**
     * Forgets the cache and reads the database again. The entity manager is cleared first because the engine
     * is a long-running process: its identity map would otherwise serve stale entities forever.
     */
    public function reload(): EngineConfiguration
    {
        $this->entityManager->clear();
        $this->cached = null;

        return $this->snapshot();
    }

    /**
     * @return list<RoomConfiguration>
     */
    public function rooms(): array
    {
        return $this->snapshot()->rooms;
    }

    public function room(RoomId $id): ?RoomConfiguration
    {
        return $this->snapshot()->room($id);
    }

    public function schedule(ScheduleId $id): ?WeeklySchedule
    {
        return $this->snapshot()->schedule($id);
    }

    public function policy(): GlobalPolicy
    {
        return $this->snapshot()->policy;
    }

    public function season(): Season
    {
        return $this->snapshot()->season;
    }

    /**
     * @return list<EntityId>
     */
    public function trackedPersons(): array
    {
        return $this->snapshot()->trackedPersons;
    }

    public function vacation(): ?VacationPeriod
    {
        return $this->snapshot()->vacation;
    }

    public function weatherEntity(): ?EntityId
    {
        return $this->snapshot()->weatherEntity;
    }
}
