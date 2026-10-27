<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\TimeoutCancellation;
use App\Application\Engine\Discovery\DiscoveryBuilder;
use App\Application\Engine\Facts\HouseEmptyContributor;
use App\Application\Engine\Notification\NotificationService;
use App\Application\Engine\Notification\NotificationTexts;
use App\Application\Engine\Overview\OverviewBuilder;
use App\Application\Port\HomeAssistant\Area;
use App\Application\Port\HomeAssistant\CommandFailedException;
use App\Application\Port\HomeAssistant\DeviceEntry;
use App\Application\Port\HomeAssistant\EntityRegistryEntry;
use App\Application\Port\HomeAssistant\ServiceDescription;
use App\Domain\Climate\Season;
use App\Infrastructure\Engine\EngineTokenStore;
use App\Infrastructure\Engine\Http\ContextRoute;
use App\Infrastructure\Engine\Http\DiscoveryRoute;
use App\Infrastructure\Engine\Http\InternalHttpServer;
use App\Infrastructure\Engine\Http\InternalRouter;
use App\Infrastructure\Engine\Http\NotificationTestRoute;
use App\Infrastructure\Engine\Http\OverviewRoute;
use App\Infrastructure\Persistence\Enum\NotificationType;
use App\Infrastructure\Persistence\Repository\NotificationTargetRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

/**
 * The internal routes the web side relies on (context, discovery, overview, test notification), on the real
 * internal HTTP server.
 */
#[CoversClass(ContextRoute::class)]
#[CoversClass(DiscoveryRoute::class)]
#[CoversClass(OverviewRoute::class)]
#[CoversClass(NotificationTestRoute::class)]
#[CoversClass(DiscoveryBuilder::class)]
#[CoversClass(OverviewBuilder::class)]
#[CoversClass(HouseEmptyContributor::class)]
#[CoversClass(NotificationService::class)]
#[CoversClass(NotificationTexts::class)]
#[CoversClass(InternalRouter::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class WebApiRoutesTest extends NotificationTestCase
{
    private HttpClient $http;

    private InternalHttpServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = HttpClientBuilder::buildDefault();
        $this->setSeason(Season::Winter);
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            $this->server->stop();
        }
        parent::tearDown();
    }

    public function testTheContextTellsWhatTheEngineKnowsOfHomeAssistant(): void
    {
        $this->startEngineAndServer();

        $context = $this->json($this->send('GET', '/internal/context'));
        self::assertSame(['connected' => true, 'haLanguage' => 'fr', 'haTimeZone' => 'Europe/Paris', 'haVersion' => '2026.10.0'], $context);

        $this->homeAssistant()->simulateConnectionLoss();
        self::assertFalse($this->json($this->send('GET', '/internal/context'))['connected']);
        $this->homeAssistant()->simulateReconnect();
        self::assertTrue($this->json($this->send('GET', '/internal/context'))['connected']);
    }

    public function testTheContextIsEmptyBeforeTheEngineReadHomeAssistant(): void
    {
        $this->server = $this->service(InternalHttpServer::class);
        $this->server->start();

        self::assertSame(['connected' => false, 'haLanguage' => null, 'haTimeZone' => null, 'haVersion' => null], $this->json($this->send('GET', '/internal/context')));
    }

    public function testDiscoverySortsOutTheEntitiesHomeAssistantOffers(): void
    {
        $ha = $this->homeAssistant();
        $ha->seedState('person.alice', 'home', ['friendly_name' => 'Alice']);
        $ha->seedState('climate.living', 'heat', ['friendly_name' => 'Living thermostat', 'hvac_modes' => ['off', 'heat', 7]]);
        $ha->seedState('climate.broken', 'unavailable', ['friendly_name' => 'No modes']);
        $ha->seedState('sensor.living_temperature', '19.5', ['friendly_name' => 'Living temperature', 'device_class' => 'temperature', 'unit_of_measurement' => '°C']);
        $ha->seedState('sensor.garage_probe', '60', ['unit_of_measurement' => '°F']);
        $ha->seedState('sensor.humidity', '40', ['device_class' => 'humidity', 'unit_of_measurement' => '%']);
        $ha->seedState('binary_sensor.motion', 'off', ['device_class' => 'motion']);
        $ha->seedState('binary_sensor.window', 'off', ['device_class' => 'window', 'friendly_name' => 'Window']);
        $ha->seedState('binary_sensor.battery', 'off', ['device_class' => 'battery']);
        $ha->seedState('input_boolean.guest', 'off');
        $ha->seedState('input_datetime.bedtime', '22:30:00', ['has_time' => true, 'has_date' => false]);
        $ha->seedState('input_datetime.birthday', '2026-10-26', ['has_time' => false, 'has_date' => true]);
        $ha->seedState('sensor.disabled_probe', 'unavailable', ['device_class' => 'temperature']);
        $ha->seedState('weather.home', 'sunny', ['friendly_name' => 'Home']);
        $ha->seedState('light.kitchen', 'on');
        $ha->seedState('switch.heater', 'off');
        $ha->seedState('fan.bedroom', 'off');
        $ha->seedState('media_player.tv', 'playing');
        $ha->seedState('sensor.tv_power', '120', ['device_class' => 'power', 'unit_of_measurement' => 'W']);
        $ha->seedState('sensor.phone_area', 'Living room');
        $ha->seedState('sensor.phone_area_broken', 'unavailable');
        $ha->seedState('sensor.counter', '42');
        $ha->withRegistries(
            [new Area('living', 'Living room'), new Area('attic', 'Attic')],
            [new DeviceEntry('dev-1', 'Probe', null, 'attic')],
            [
                new EntityRegistryEntry('climate.living', null, 'living'),
                new EntityRegistryEntry('sensor.garage_probe', 'dev-1'),
                new EntityRegistryEntry('sensor.disabled_probe', null, null, null, null, null, 'user'),
                new EntityRegistryEntry('binary_sensor.motion', 'dev-unknown'),
            ],
        );
        $ha->withServices(['notify' => [
            'mobile_app_pixel' => new ServiceDescription('notify', 'mobile_app_pixel', 'Pixel 8'),
            'send_message' => new ServiceDescription('notify', 'send_message', 'Send a message'),
            'living_tv' => new ServiceDescription('notify', 'living_tv'),
        ], 'light' => ['turn_on' => new ServiceDescription('light', 'turn_on')]]);
        $this->startEngineAndServer();

        $response = $this->send('GET', '/internal/discovery');

        self::assertSame(200, $response->getStatus());
        $discovery = $this->json($response);
        self::assertSame([['id' => 'attic', 'name' => 'Attic'], ['id' => 'living', 'name' => 'Living room']], $discovery['areas']);
        self::assertSame([['entityId' => 'person.alice', 'name' => 'Alice', 'areaId' => null, 'state' => 'home', 'unit' => null, 'deviceClass' => null]], $discovery['persons']);
        self::assertSame([['entityId' => 'climate.living', 'name' => 'Living thermostat', 'areaId' => 'living', 'state' => 'heat', 'unit' => null, 'deviceClass' => null, 'hvacModes' => ['off', 'heat']]], $discovery['climates']);
        self::assertSame(['sensor.living_temperature', 'sensor.garage_probe'], $this->entityIds($discovery['temperatureSensors']), 'A temperature class or a degree unit qualifies; a disabled entity does not.');
        self::assertSame('attic', $this->itemOf($discovery['temperatureSensors'], 'sensor.garage_probe')['areaId'], 'An entity inherits the area of its device.');
        self::assertSame(['binary_sensor.motion', 'input_boolean.guest'], $this->entityIds($discovery['presenceSensors']));
        self::assertSame(['input_boolean.guest', 'binary_sensor.window'], $this->entityIds($discovery['openingSensors']));
        self::assertSame(
            ['fan.bedroom', 'light.kitchen', 'media_player.tv', 'sensor.phone_area', 'sensor.tv_power', 'switch.heater'],
            $this->entityIds($discovery['activitySources']),
            'Lights, switches, fans, players, power sensors and textual sensors; not numbers, failures or the presence sensors.',
        );
        self::assertSame(
            ['active', 'active', 'active', 'equals', 'above', 'active'],
            array_map(fn (mixed $item): mixed => \is_array($item) ? $item['defaultKind'] : null, \is_array($discovery['activitySources']) ? $discovery['activitySources'] : []),
        );
        self::assertSame(['input_datetime.bedtime'], $this->entityIds($discovery['timeEntities']));
        self::assertSame(['weather.home'], $this->entityIds($discovery['weather']));
        self::assertSame([['service' => 'notify.living_tv', 'name' => 'living_tv'], ['service' => 'notify.mobile_app_pixel', 'name' => 'Pixel 8']], $discovery['notifyServices']);
    }

    public function testDiscoveryIs503WhenHomeAssistantCannotBeReached(): void
    {
        $this->startEngineAndServer();
        $this->homeAssistant()->simulateConnectionLoss();

        $response = $this->send('GET', '/internal/discovery');

        self::assertSame(503, $response->getStatus());
        self::assertSame('home_assistant_unavailable', $this->json($response)['error']);
    }

    public function testTheOverviewListsPersonsWeatherAndSinceWhenTheHouseIsEmpty(): void
    {
        $ha = $this->homeAssistant();
        $ha->seedState('person.alice', 'not_home', ['friendly_name' => 'Alice']);
        $ha->seedState('person.bob', 'home');
        $ha->seedState('weather.home', 'partlycloudy', ['temperature' => 12.5, 'humidity' => '71', 'forecast' => [['templow' => 8, 'temperature' => 15.5]]]);
        $settings = $this->settings();
        $settings->configurePresence(3600, ['person.alice']);
        $settings->changeWeatherEntity('weather.home');
        $this->em()->flush();
        $this->createRoom();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->startEngineAndServer();

        $overview = $this->json($this->send('GET', '/internal/overview'));

        self::assertSame([
            ['entityId' => 'person.alice', 'name' => 'Alice', 'state' => 'not_home'],
            ['entityId' => 'person.bob', 'name' => 'person.bob', 'state' => 'home'],
        ], $overview['persons']);
        self::assertSame(['entityId' => 'weather.home', 'condition' => 'partlycloudy', 'temperature' => 12.5, 'humidity' => 71.0, 'temperatureLow' => 8, 'temperatureHigh' => 15.5], $overview['weather']);
        self::assertSame($this->clock()->now()->setTimezone(new \DateTimeZone('Europe/Paris'))->format(\DATE_ATOM), $overview['houseEmptySince']);
    }

    public function testTheOverviewWithoutWeatherNorTrackedPersons(): void
    {
        $this->homeAssistant()->seedState('weather.home', 'sunny');
        $this->startEngineAndServer();

        $overview = $this->json($this->send('GET', '/internal/overview'));

        self::assertSame([], $overview['persons']);
        self::assertNull($overview['weather']);
        self::assertNull($overview['houseEmptySince']);

        $settings = $this->settings();
        $settings->changeWeatherEntity('weather.home');
        $this->em()->flush();
        $this->service(\App\Application\Engine\Lifecycle\ConfigurationReloader::class)->reload();
        $weather = $this->json($this->send('GET', '/internal/overview'))['weather'];
        self::assertIsArray($weather);
        self::assertNull($weather['temperature']);
        self::assertNull($weather['temperatureLow']);
    }

    public function testTheOverviewIs503WhenHomeAssistantCannotBeReached(): void
    {
        $this->startEngineAndServer();
        $this->homeAssistant()->simulateConnectionLoss();

        self::assertSame(503, $this->send('GET', '/internal/overview')->getStatus());
    }

    public function testTheTestNotificationReachesEveryEnabledDestinationWhateverTheTypes(): void
    {
        $this->addTarget('notify.living_tv');
        $this->addTarget('notify.disabled_one', false);
        $this->configureType(NotificationType::SensorUnavailable, false, null, false);
        $this->startEngineAndServer();

        $response = $this->send('POST', '/internal/notifications/test');

        self::assertSame(202, $response->getStatus());
        self::assertSame(['sent' => 2, 'failed' => 0], $this->json($response));
        $call = $this->notifications()[0];
        self::assertSame('Test HA Climate', $call->serviceData['title']);
        self::assertCount(1, $this->notifications('living_tv'));
        self::assertSame([], $this->notifications('disabled_one'));
        self::assertCount(2, $this->auditEntries('audit.notification.sent'));
    }

    public function testAFailingDestinationIsCountedNotFatal(): void
    {
        $this->startEngineAndServer();
        $this->homeAssistant()->failNextServiceCall(new CommandFailedException('unknown_error', 'boom'));

        $response = $this->send('POST', '/internal/notifications/test');

        self::assertSame(202, $response->getStatus());
        self::assertSame(['sent' => 0, 'failed' => 1], $this->json($response));
        self::assertCount(1, $this->auditEntries('audit.notification.failed'));
    }

    public function testTheTestNotificationIs409WithoutEnabledDestination(): void
    {
        foreach ($this->service(NotificationTargetRepository::class)->findAll() as $target) {
            $target->disable();
        }
        $this->em()->flush();
        $this->startEngineAndServer();

        $response = $this->send('POST', '/internal/notifications/test');

        self::assertSame(409, $response->getStatus());
        self::assertSame('no_enabled_target', $this->json($response)['error']);
    }

    public function testTheRoutesRequireTheToken(): void
    {
        $this->startEngineAndServer();

        foreach ([['GET', '/internal/context'], ['GET', '/internal/discovery'], ['GET', '/internal/overview'], ['POST', '/internal/notifications/test']] as [$method, $path]) {
            self::assertSame(401, $this->send($method, $path, false)->getStatus(), $path);
        }
    }

    private function startEngineAndServer(): void
    {
        $this->startEngine();
        $this->server = $this->service(InternalHttpServer::class);
        $this->server->start();
    }

    /**
     * @param non-empty-string $method
     */
    private function send(string $method, string $path, bool $withToken = true): Response
    {
        $request = new Request(\sprintf('http://127.0.0.1:%d%s', $this->server->port(), $path), $method);
        if ($withToken) {
            $request->setHeader('X-Internal-Token', $this->service(EngineTokenStore::class)->read() ?? '');
        }

        return $this->http->request($request, new TimeoutCancellation(5));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->getBody()->buffer(), true, 512, \JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function entityIds(mixed $items): array
    {
        self::assertIsArray($items);

        return array_values(array_map(static fn (mixed $item): string => \is_array($item) && \is_string($item['entityId'] ?? null) ? $item['entityId'] : '', $items));
    }

    /**
     * @return array<string, mixed>
     */
    private function itemOf(mixed $items, string $entityId): array
    {
        self::assertIsArray($items);
        foreach ($items as $item) {
            if (\is_array($item) && ($item['entityId'] ?? null) === $entityId) {
                return array_combine(array_map(strval(...), array_keys($item)), $item);
            }
        }

        self::fail('Missing '.$entityId);
    }
}
