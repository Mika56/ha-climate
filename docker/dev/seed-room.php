<?php

declare(strict_types=1);

// Dev fixture: one room ("Living room") wired to the entities of docker/dev/ha-config/configuration.yaml,
// heated by a weekly schedule, winter season. Idempotent: does nothing when a room already exists.
//
//   docker run ... php /workspace/docker/dev/seed-room.php       (after doctrine:migrations:migrate)

use App\Domain\Climate\HvacMode;
use App\Domain\Climate\Season;
use App\Domain\Device\DeviceRole;
use App\Infrastructure\Persistence\Entity\GlobalSettings;
use App\Infrastructure\Persistence\Entity\Preset;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Entity\Schedule;
use App\Infrastructure\Persistence\Enum\SlotStartType;
use App\Infrastructure\Persistence\Seed\DefaultDataSeeder;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require \dirname(__DIR__, 2).'/app/vendor/autoload.php';

(new Dotenv())->bootEnv(\dirname(__DIR__, 2).'/app/.env');
$kernel = new Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $entityManager */
$entityManager = $kernel->getContainer()->get('doctrine.orm.entity_manager');
$kernel->getContainer()->get(DefaultDataSeeder::class)->seed();

if ([] !== $entityManager->getRepository(Room::class)->findAll()) {
    echo "A room already exists, nothing to do.\n";

    return;
}

$presets = $entityManager->getRepository(Preset::class);
$comfort = $presets->findOneByKey('comfort');
$eco = $presets->findOneByKey('eco');
$night = $presets->findOneByKey('night');
$away = $presets->findOneByKey('away');

$room = new Room('Living room');
$room->configureSensors('sensor.living_room_temperature_sensor', 'input_boolean.living_presence', ['binary_sensor.living_room_window']);
$room->addDevice('climate.living_room_heater', DeviceRole::Heating);
foreach ([[$comfort, 21.0], [$eco, 18.0], [$night, 17.0], [$away, 15.0]] as [$preset, $temperature]) {
    $room->setPresetTemperature($preset, HvacMode::Heat, $temperature);
}

// Comfort from 06:30, night when bedtime (input_datetime.bedtime, 22:30 if unavailable), every day.
$schedule = new Schedule('Living room heating', HvacMode::Heat);
foreach (range(1, 7) as $weekday) {
    $schedule->addSlot($weekday, SlotStartType::Fixed, 6 * 60 + 30, $comfort);
    $schedule->addSlot($weekday, SlotStartType::Anchored, 22 * 60 + 30, $night, 'input_datetime.bedtime', 0, 22 * 60 + 30);
}
$room->assignSchedule(HvacMode::Heat, $schedule);

$entityManager->persist($schedule);
$entityManager->persist($room);
$entityManager->getRepository(GlobalSettings::class)->get()->changeSeason(Season::Winter);
$entityManager->flush();

echo "Seeded the living room (winter season).\n";
