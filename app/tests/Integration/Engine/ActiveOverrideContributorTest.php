<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\EngineSnapshotFactory;
use App\Application\Engine\Evaluation\LiveStatusPublisher;
use App\Application\Engine\Facts\ActiveOverrideContributor;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Decision\OverrideType;
use App\Domain\Decision\RoomFacts;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Entity\RoomOverride;
use App\Infrastructure\Persistence\Repository\RoomOverrideRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

#[CoversClass(ActiveOverrideContributor::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class ActiveOverrideContributorTest extends EngineTestCase
{
    public function testAnActiveBoostBecomesAFact(): void
    {
        $room = $this->roomWithSeason(Season::Winter);
        $now = $this->clock()->now();
        $this->save(RoomOverride::boost($room, HvacMode::Heat, 2.5, $now->modify('-10 minutes'), $now->modify('+1 hour'), true, 'user-1'));

        $facts = $this->contribute();

        self::assertNotNull($facts->override);
        self::assertSame(OverrideType::Boost, $facts->override->type);
        self::assertSame(2.5, $facts->override->delta?->toFloat());
        self::assertTrue($facts->override->untilNextSlot);
        self::assertSame($now->modify('+1 hour')->getTimestamp(), $facts->override->endsAt?->getTimestamp());
    }

    public function testAnActiveManualOverrideBecomesAFact(): void
    {
        $room = $this->roomWithSeason(Season::Winter);
        $now = $this->clock()->now();
        $this->save(RoomOverride::manual($room, HvacMode::Heat, 23.0, $now->modify('-10 minutes'), $now->modify('+2 hours'), 'device'));

        $facts = $this->contribute();

        self::assertSame(OverrideType::Manual, $facts->override?->type);
        self::assertSame(23.0, $facts->override->target?->toFloat());
    }

    public function testAnEndedOverrideIsIgnored(): void
    {
        $room = $this->roomWithSeason(Season::Winter);
        $now = $this->clock()->now();
        $this->save(RoomOverride::boost($room, HvacMode::Heat, 2.0, $now->modify('-3 hours'), $now->modify('-1 hour'), false, 'user-1'));

        self::assertNull($this->contribute()->override);
    }

    public function testAnOverrideForTheOtherModeIsIgnored(): void
    {
        $room = $this->roomWithSeason(Season::Summer);
        $now = $this->clock()->now();
        $this->save(RoomOverride::boost($room, HvacMode::Heat, 2.0, $now->modify('-10 minutes'), $now->modify('+1 hour'), false, 'user-1'));

        self::assertNull($this->contribute()->override);
    }

    public function testAnOverrideWithAnOutOfRangeValueIsIgnored(): void
    {
        $room = $this->roomWithSeason(Season::Winter);
        $now = $this->clock()->now();
        $this->save(RoomOverride::manual($room, HvacMode::Heat, 80.0, $now->modify('-10 minutes'), $now->modify('+2 hours'), 'device'));

        self::assertNull($this->contribute()->override);
    }

    public function testNoOverrideNoFact(): void
    {
        $room = $this->roomWithSeason(Season::Winter);

        self::assertNull($this->contribute()->override);
    }

    public function testTheActiveOverrideIsShownInTheRoomStatus(): void
    {
        $room = $this->roomWithSeason(Season::Winter);
        $this->seedDevice(mode: 'heat', target: 21.0);
        $now = $this->clock()->now();
        $this->save(RoomOverride::boost($room, HvacMode::Heat, 2.0, $now->modify('-10 minutes'), $now->modify('+1 hour'), false, 'user-1'));

        $this->startEngine();

        $override = $this->service(LiveStatusPublisher::class)->snapshot()[0]->override;
        self::assertSame(OverrideType::Boost, $override?->type);
    }

    private function roomWithSeason(Season $season): Room
    {
        $this->setSeason($season);
        $room = $this->createRoom(role: \App\Domain\Device\DeviceRole::Reversible, withCoolingSchedule: true);
        $managed = $this->em()->find(Room::class, $room->getId());
        self::assertNotNull($managed);

        return $managed;
    }

    private function save(RoomOverride $override): void
    {
        $this->service(RoomOverrideRepository::class)->save($override);
    }

    private function contribute(): RoomFacts
    {
        $snapshot = $this->service(EngineSnapshotFactory::class)->create();
        $configuration = $snapshot->configuration->rooms[0];

        return $this->service(ActiveOverrideContributor::class)->contribute($configuration, RoomFacts::none(), $snapshot);
    }
}
