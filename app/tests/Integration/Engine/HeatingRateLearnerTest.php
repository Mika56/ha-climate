<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Configuration\ConfigurationRepository;
use App\Application\Engine\Configuration\EngineConfiguration;
use App\Application\Engine\EngineSnapshot;
use App\Application\Engine\Facts\HeatingRateContributor;
use App\Application\Engine\Heating\HeatingRateLearner;
use App\Application\Engine\State\StateStore;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Decision\RoomConfiguration;
use App\Domain\Decision\RoomFacts;
use App\Domain\Device\DeviceRole;
use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Entity\HeatingRate;
use App\Infrastructure\Persistence\Repository\HeatingRateRepository;
use App\Infrastructure\Persistence\Repository\RoomRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Symfony\Component\Uid\Uuid;

#[CoversClass(HeatingRateLearner::class)]
#[CoversClass(HeatingRateContributor::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class HeatingRateLearnerTest extends EngineTestCase
{
    private \DateTimeImmutable $start;

    protected function setUp(): void
    {
        parent::setUp();
        $this->start = new \DateTimeImmutable('2026-10-26 07:00:00');
    }

    public function testAHeatingEpisodeYieldsADegreesPerHourRate(): void
    {
        $room = $this->heatedRoom(Season::Winter);
        $learner = $this->service(HeatingRateLearner::class);

        $learner->observe($room, $this->snapshot($room, 0, 'heat', 21.0, '19.0'));
        self::assertNull($learner->rate($room, HvacMode::Heat));
        $learner->observe($room, $this->snapshot($room, 30, 'heat', 21.0, '20.0'));
        self::assertNull($learner->rate($room, HvacMode::Heat), 'Still on its way.');
        $learner->observe($room, $this->snapshot($room, 60, 'heat', 21.0, '21.0'));

        self::assertEqualsWithDelta(2.0, $learner->rate($room, HvacMode::Heat), 0.0001);
        self::assertNull($learner->rate($room, HvacMode::Cool));
        $stored = $this->storedRate($room, HvacMode::Heat);
        self::assertSame(1, $stored->getSamples());
        self::assertEqualsWithDelta(2.0, $stored->getDegreesPerHour(), 0.0001);
    }

    public function testSamplesAreSmoothedWithAnExponentialAverage(): void
    {
        $room = $this->heatedRoom(Season::Winter);
        $learner = $this->service(HeatingRateLearner::class);

        // 2 degrees in one hour, then 4 degrees in one hour.
        $learner->observe($room, $this->snapshot($room, 0, 'heat', 21.0, '19.0'));
        $learner->observe($room, $this->snapshot($room, 60, 'heat', 21.0, '21.0'));
        $learner->observe($room, $this->snapshot($room, 120, 'heat', 24.0, '20.0'));
        $learner->observe($room, $this->snapshot($room, 180, 'heat', 24.0, '24.0'));

        // 0.3 * 4 + 0.7 * 2
        self::assertEqualsWithDelta(2.6, $learner->rate($room, HvacMode::Heat), 0.0001);
        self::assertSame(2, $this->storedRate($room, HvacMode::Heat)->getSamples());
    }

    public function testAStoredRateIsReadBackByAFreshLearner(): void
    {
        $room = $this->heatedRoom(Season::Winter);
        $learner = $this->service(HeatingRateLearner::class);
        $learner->observe($room, $this->snapshot($room, 0, 'heat', 21.0, '19.0'));
        $learner->observe($room, $this->snapshot($room, 60, 'heat', 21.0, '21.0'));

        $fresh = new HeatingRateLearner($this->service(RoomRepository::class), $this->service(HeatingRateRepository::class));

        self::assertEqualsWithDelta(2.0, $fresh->rate($room, HvacMode::Heat), 0.0001);
    }

    public function testEpisodesShorterThanTenMinutesAreIgnoredButTenMinutesCount(): void
    {
        $room = $this->heatedRoom(Season::Winter);
        $learner = $this->service(HeatingRateLearner::class);

        $learner->observe($room, $this->snapshotAt($room, 0, 'heat', 20.0, '19.0'));
        $learner->observe($room, $this->snapshotAt($room, 599, 'heat', 20.0, '20.0'));
        self::assertNull($learner->rate($room, HvacMode::Heat));

        // One degree in exactly 600 seconds: 6 degrees per hour.
        $learner->observe($room, $this->snapshotAt($room, 1000, 'heat', 20.0, '19.0'));
        $learner->observe($room, $this->snapshotAt($room, 1600, 'heat', 20.0, '20.0'));
        self::assertEqualsWithDelta(6.0, $learner->rate($room, HvacMode::Heat), 0.0001);
    }

    public function testAnImplausiblyFastEpisodeIsClamped(): void
    {
        $room = $this->heatedRoom(Season::Winter);
        $learner = $this->service(HeatingRateLearner::class);

        $learner->observe($room, $this->snapshotAt($room, 0, 'heat', 30.0, '19.0'));
        $learner->observe($room, $this->snapshotAt($room, 600, 'heat', 30.0, '30.0'));

        self::assertSame(HeatingRateLearner::MAX_RATE, $learner->rate($room, HvacMode::Heat));
    }

    public function testAnEpisodeNeedsAtLeastOneDegreeToCover(): void
    {
        $room = $this->heatedRoom(Season::Winter);
        $learner = $this->service(HeatingRateLearner::class);

        $learner->observe($room, $this->snapshot($room, 0, 'heat', 21.0, '20.1'));
        $learner->observe($room, $this->snapshot($room, 60, 'heat', 21.0, '21.0'));

        self::assertNull($learner->rate($room, HvacMode::Heat));
    }

    public function testADeviceThatLeavesTheModeInterruptsTheEpisode(): void
    {
        $room = $this->heatedRoom(Season::Winter);
        $learner = $this->service(HeatingRateLearner::class);

        $learner->observe($room, $this->snapshot($room, 0, 'heat', 21.0, '19.0'));
        $learner->observe($room, $this->snapshot($room, 20, 'off', null, '19.5')); // window opened
        $learner->observe($room, $this->snapshot($room, 40, 'heat', 21.0, '20.9'));
        $learner->observe($room, $this->snapshot($room, 60, 'heat', 21.0, '21.0'));

        self::assertNull($learner->rate($room, HvacMode::Heat));
    }

    public function testAChangedSetpointInterruptsTheEpisodeAndStartsAnother(): void
    {
        $room = $this->heatedRoom(Season::Winter);
        $learner = $this->service(HeatingRateLearner::class);

        $learner->observe($room, $this->snapshot($room, 0, 'heat', 21.0, '19.0'));
        $learner->observe($room, $this->snapshot($room, 30, 'heat', 23.0, '20.0'));
        $learner->observe($room, $this->snapshot($room, 90, 'heat', 23.0, '23.0'));

        // The second episode started at 20.0 at minute 30: 3 degrees in one hour.
        self::assertEqualsWithDelta(3.0, $learner->rate($room, HvacMode::Heat), 0.0001);
    }

    public function testEpisodesLongerThanEightHoursAreDropped(): void
    {
        $room = $this->heatedRoom(Season::Winter);
        $learner = $this->service(HeatingRateLearner::class);

        $learner->observe($room, $this->snapshot($room, 0, 'heat', 21.0, '19.0'));
        $learner->observe($room, $this->snapshotAt($room, 8 * 3600 + 1, 'heat', 21.0, '21.0'));

        self::assertNull($learner->rate($room, HvacMode::Heat));
    }

    public function testAMissingTemperatureNeitherStartsNorEndsAnEpisode(): void
    {
        $room = $this->heatedRoom(Season::Winter);
        $learner = $this->service(HeatingRateLearner::class);

        $learner->observe($room, $this->snapshot($room, 0, 'heat', 21.0, 'unavailable'));
        $learner->observe($room, $this->snapshot($room, 10, 'heat', 21.0, '19.0'));
        $learner->observe($room, $this->snapshot($room, 40, 'heat', 21.0, 'unavailable'));
        self::assertNull($learner->rate($room, HvacMode::Heat));
        $learner->observe($room, $this->snapshot($room, 70, 'heat', 21.0, '21.0'));

        self::assertEqualsWithDelta(2.0, $learner->rate($room, HvacMode::Heat), 0.0001);
    }

    public function testADeviceWithoutUsableSetpointCannotRunAnEpisode(): void
    {
        $room = $this->heatedRoom(Season::Winter);
        $learner = $this->service(HeatingRateLearner::class);

        $learner->observe($room, $this->snapshot($room, 0, 'heat', null, '19.0'));
        $learner->observe($room, $this->snapshot($room, 60, 'heat', 99.0, '21.0'));
        $learner->observe($room, $this->snapshot($room, 120, 'heat', null, '21.0'));

        self::assertNull($learner->rate($room, HvacMode::Heat));
    }

    public function testCoolingEpisodesAreLearnedSeparately(): void
    {
        $room = $this->heatedRoom(Season::Summer, DeviceRole::Reversible);
        $learner = $this->service(HeatingRateLearner::class);

        $learner->observe($room, $this->snapshot($room, 0, 'cool', 24.0, '27.0'));
        $learner->observe($room, $this->snapshot($room, 90, 'cool', 24.0, '24.0'));

        self::assertEqualsWithDelta(2.0, $learner->rate($room, HvacMode::Cool), 0.0001);
        self::assertNull($learner->rate($room, HvacMode::Heat));
    }

    public function testNothingIsLearnedWhenTheSeasonChangesMidEpisode(): void
    {
        $room = $this->heatedRoom(Season::Winter);
        $learner = $this->service(HeatingRateLearner::class);
        $learner->observe($room, $this->snapshot($room, 0, 'heat', 21.0, '19.0'));

        $learner->observe($room, $this->snapshot($room, 30, 'cool', 24.0, '20.0', Season::Summer));
        $learner->observe($room, $this->snapshot($room, 60, 'heat', 21.0, '21.0'));

        self::assertNull($learner->rate($room, HvacMode::Heat));
        self::assertNull($learner->rate($room, HvacMode::Off));
    }

    public function testASeasonChangeDropsTheEpisodeOfTheOtherMode(): void
    {
        $room = $this->heatedRoom(Season::Winter, DeviceRole::Reversible);
        $learner = $this->service(HeatingRateLearner::class);
        $learner->observe($room, $this->snapshot($room, 0, 'heat', 21.0, '19.0'));

        $learner->observe($room, $this->snapshot($room, 30, 'cool', 24.0, '23.0', Season::Summer));
        $learner->observe($room, $this->snapshot($room, 60, 'heat', 21.0, '21.0'));

        self::assertNull($learner->rate($room, HvacMode::Heat));
    }

    public function testARoomUnknownToTheDatabaseHasNoRateAndLearnsNothing(): void
    {
        $this->heatedRoom(Season::Winter);
        $ghost = $this->ghost();
        $learner = $this->service(HeatingRateLearner::class);

        $learner->observe($ghost, $this->snapshot($ghost, 0, 'heat', 21.0, '19.0'));
        $learner->observe($ghost, $this->snapshot($ghost, 60, 'heat', 21.0, '21.0'));

        self::assertNull($learner->rate($ghost, HvacMode::Heat));
        self::assertCount(0, $this->service(HeatingRateRepository::class)->findAll());
    }

    public function testTheContributorFeedsTheLearnerAndExposesTheRate(): void
    {
        $room = $this->heatedRoom(Season::Winter);
        $contributor = $this->service(HeatingRateContributor::class);

        $first = $contributor->contribute($room, RoomFacts::none(), $this->snapshot($room, 0, 'heat', 21.0, '19.0'));
        $second = $contributor->contribute($room, RoomFacts::none(), $this->snapshot($room, 60, 'heat', 21.0, '21.0'));

        self::assertNull($first->heatingRatePerHour);
        self::assertEqualsWithDelta(2.0, $second->heatingRatePerHour, 0.0001);
    }

    private function heatedRoom(Season $season, DeviceRole $role = DeviceRole::Heating): RoomConfiguration
    {
        $this->setSeason($season);
        $this->createRoom(role: $role, withCoolingSchedule: true);

        return $this->service(ConfigurationRepository::class)->rooms()[0];
    }

    private function ghost(): RoomConfiguration
    {
        $real = $this->service(ConfigurationRepository::class)->rooms()[0];

        return new RoomConfiguration(
            new RoomId(Uuid::v7()->toRfc4122()),
            'Ghost',
            $real->devices,
            $real->presetTemperatures,
            null,
            null,
            $real->temperatureSensor,
            null,
            [],
            600,
            120,
            1800,
        );
    }

    private function snapshotAt(RoomConfiguration $room, int $seconds, string $deviceMode, ?float $setpoint, string $temperature, ?Season $season = null): EngineSnapshot
    {
        $store = new StateStore();
        $now = $this->start->modify(\sprintf('+%d seconds', $seconds));
        $context = new StateContext('c');
        $store->load([
            new EntityState(self::DEVICE, $deviceMode, null === $setpoint ? [] : ['temperature' => $setpoint], $now, $now, $context),
            new EntityState(self::SENSOR, $temperature, [], $now, $now, $context),
        ]);
        $configuration = $this->service(ConfigurationRepository::class)->snapshot();
        if (null !== $season) {
            $configuration = new EngineConfiguration(
                $configuration->rooms,
                $configuration->schedules,
                $configuration->policy,
                $season,
                $configuration->trackedPersons,
                $configuration->vacation,
                $configuration->weatherEntity,
                $configuration->auditRetentionDays,
                $configuration->manualOverrideMinutes,
            );
        }

        return new EngineSnapshot($now, $configuration, $store);
    }

    private function snapshot(RoomConfiguration $room, int $minutes, string $deviceMode, ?float $setpoint, string $temperature, ?Season $season = null): EngineSnapshot
    {
        return $this->snapshotAt($room, $minutes * 60, $deviceMode, $setpoint, $temperature, $season);
    }

    private function storedRate(RoomConfiguration $room, HvacMode $mode): HeatingRate
    {
        $this->em()->clear();
        $rates = $this->service(HeatingRateRepository::class)->findAll();
        self::assertCount(1, $rates);
        self::assertSame((string) $room->id, $rates[0]->getRoom()->getId()->toRfc4122());
        self::assertSame($mode, $rates[0]->getMode());

        return $rates[0];
    }
}
