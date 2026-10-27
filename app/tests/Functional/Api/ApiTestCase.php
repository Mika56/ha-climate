<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Infrastructure\Engine\EngineTokenStore;
use App\Infrastructure\Persistence\Entity\Preset;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Repository\AuditEntryRepository;
use App\Infrastructure\Persistence\Seed\DefaultDataSeeder;
use App\Tests\Double\FakeEngine;
use App\Tests\Support\OpenApiContract;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Base of the functional tests of the JSON API: the real application and a real SQLite database (rolled back
 * after each test), with the engine replaced by a {@see FakeEngine}. Every JSON answer is checked against the
 * OpenAPI contract.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        // One container for the whole test: the fake engine keeps its script and its recorded requests.
        $this->client->disableReboot();
        $this->cache()->clear();
        $this->service(DefaultDataSeeder::class)->seed();
        $this->service(EngineTokenStore::class)->ensure();
        $this->engine()->reset();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    protected function cache(): CacheItemPoolInterface
    {
        return $this->service(CacheItemPoolInterface::class);
    }

    protected function engine(): FakeEngine
    {
        return $this->service(FakeEngine::class);
    }

    protected function em(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    protected function clock(): MockClock
    {
        return $this->service(MockClock::class);
    }

    /**
     * Sends a JSON request and checks the answer against the contract.
     *
     * @param string                              $operationId the contract operation the request is for; null skips the contract check
     * @param array<array-key, mixed>|string|null $body        array → JSON; a string is sent as is
     * @param array<string, string>               $headers     HTTP headers (`X-Remote-User-Id`, `Accept-Language`...)
     */
    protected function call(?string $operationId, string $method, string $uri, array|string|null $body = null, array $headers = []): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->client->request($method, $uri, [], [], $server, \is_string($body) ? $body : (null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR)));
        $this->em()->clear();

        $response = new TestResponse($this->client->getInternalResponse());
        if (null !== $operationId) {
            $response->assertMatchesContract($operationId);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    protected static function asUser(string $id = 'u1', string $name = 'alice', string $displayName = 'Alice'): array
    {
        return ['X-Remote-User-Id' => $id, 'X-Remote-User-Name' => $name, 'X-Remote-User-Display-Name' => $displayName];
    }

    /**
     * A copy of a JSON document with the value at a dotted path (`rooms.0.name`) replaced, or added.
     *
     * @param array<array-key, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    protected static function with(array $document, string $path, mixed $value): array
    {
        $parts = explode('.', $path, 2);
        $head = $parts[0];
        $rest = $parts[1] ?? null;
        $key = ctype_digit($head) ? (int) $head : $head;
        if (null === $rest) {
            $document[$key] = $value;

            return $document;
        }

        $child = $document[$key] ?? [];
        self::assertIsArray($child);
        $document[$key] = self::with($child, $rest, $value);

        return $document;
    }

    /**
     * Hides a value's static type from the analyser, for the services the test container hands out as the
     * dev-environment container describes them.
     */
    protected static function untyped(mixed $value): mixed
    {
        return $value;
    }

    protected function contract(): OpenApiContract
    {
        return OpenApiContract::load();
    }

    protected function preset(string $key): Preset
    {
        return $this->em()->getRepository(Preset::class)->findOneBy(['key' => $key]) ?? throw new \LogicException('Missing preset '.$key);
    }

    /**
     * A room body that passes validation, with a heating device and comfort/eco temperatures.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected static function roomBody(string $name = 'Living room', string $device = 'climate.living', array $overrides = []): array
    {
        return [
            'name' => $name,
            'areaId' => 'living_room',
            'temperatureSensor' => 'sensor.living_temperature',
            'presenceSignals' => [['entityId' => 'binary_sensor.living_presence', 'kind' => 'active', 'threshold' => null, 'value' => null]],
            'openings' => ['binary_sensor.living_window'],
            'vacancyDelaySeconds' => 900,
            'windowCutDelaySeconds' => 60,
            'windowNotifyDelaySeconds' => 600,
            'preheatEnabled' => true,
            'preheatMaxMinutes' => 90,
            'heatingScheduleId' => null,
            'coolingScheduleId' => null,
            'devices' => [['entityId' => $device, 'role' => 'reversible', 'minOnSeconds' => 300, 'minOffSeconds' => 120, 'hysteresis' => 0.5]],
            'presetTemperatures' => [
                ['preset' => 'comfort', 'heat' => 21.0, 'cool' => 24.5],
                ['preset' => 'eco', 'heat' => 18.0, 'cool' => null],
            ],
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function scheduleBody(string $name = 'Weekdays', string $mode = 'heat'): array
    {
        return [
            'name' => $name,
            'mode' => $mode,
            'slots' => [
                ['weekday' => 1, 'start' => ['type' => 'fixed', 'minuteOfDay' => 390], 'preset' => 'comfort'],
                ['weekday' => 1, 'start' => ['type' => 'anchored', 'entityId' => 'input_datetime.bedtime', 'offsetMinutes' => -30, 'fallbackMinuteOfDay' => 1350], 'preset' => 'night'],
            ],
        ];
    }

    /**
     * A complete room status, as the engine publishes it.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected static function roomStatus(string $roomId, string $name = 'Living room', array $overrides = []): array
    {
        return [
            'roomId' => $roomId,
            'name' => $name,
            'currentTemperature' => 19.5,
            'decision' => ['mode' => 'heat', 'target' => 21.0, 'reason' => ['code' => 'reason.no_rule', 'params' => []]],
            'override' => null,
            'devices' => [['entityId' => 'climate.living', 'role' => 'reversible', 'state' => 'heat', 'targetTemperature' => 21.0, 'currentTemperature' => 19.5, 'running' => true]],
            'facts' => ['openingOpenSince' => null, 'roomVacantSince' => null, 'houseEmptySince' => null, 'heatingRatePerHour' => 1.5],
            'alerts' => [],
            'paused' => false,
            'updatedAt' => '2026-10-26T08:00:00+01:00',
            ...$overrides,
        ];
    }

    /**
     * Creates a room through the API and returns its id.
     *
     * @param array<string, mixed> $overrides
     */
    protected function createRoom(string $name = 'Living room', string $device = 'climate.living', array $overrides = []): string
    {
        $id = $this->call('createRoom', 'POST', '/api/rooms', self::roomBody($name, $device, $overrides))->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * Creates a schedule through the API and returns its id.
     */
    protected function createSchedule(string $name = 'Weekdays', string $mode = 'heat'): string
    {
        $id = $this->call('createSchedule', 'POST', '/api/schedules', self::scheduleBody($name, $mode))->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    protected function findRoom(string $id): ?Room
    {
        return $this->em()->find(Room::class, $id);
    }

    /**
     * @return list<\App\Infrastructure\Persistence\Entity\AuditEntry>
     */
    protected function auditEntries(?string $messageKey = null): array
    {
        $this->em()->clear();
        $entries = $this->service(AuditEntryRepository::class)->findBy(null === $messageKey ? [] : ['messageKey' => $messageKey]);

        return $entries;
    }
}
