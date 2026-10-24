<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\HomeAssistant;

use App\Application\Port\HomeAssistant\Area;
use App\Application\Port\HomeAssistant\DeviceEntry;
use App\Application\Port\HomeAssistant\EntityRegistryEntry;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\HomeAssistantConfig;
use App\Application\Port\HomeAssistant\InvalidPayloadException;
use App\Application\Port\HomeAssistant\ServiceCallResult;
use App\Application\Port\HomeAssistant\ServiceDescription;
use App\Application\Port\HomeAssistant\StateContext;
use App\Infrastructure\HomeAssistant\CompressedStateDecoder;
use App\Infrastructure\HomeAssistant\Payload;
use App\Infrastructure\HomeAssistant\ResultMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResultMapper::class)]
#[UsesClass(Payload::class)]
#[UsesClass(CompressedStateDecoder::class)]
#[UsesClass(Area::class)]
#[UsesClass(DeviceEntry::class)]
#[UsesClass(EntityRegistryEntry::class)]
#[UsesClass(EntityState::class)]
#[UsesClass(HomeAssistantConfig::class)]
#[UsesClass(ServiceCallResult::class)]
#[UsesClass(ServiceDescription::class)]
#[UsesClass(StateContext::class)]
final class ResultMapperTest extends TestCase
{
    public function testConfig(): void
    {
        $config = ResultMapper::config([
            'language' => 'fr',
            'time_zone' => 'Europe/Paris',
            'version' => '2025.1.2',
            'unit_system' => ['temperature' => '°C', 'length' => 'km'],
        ]);

        self::assertSame('fr', $config->language);
        self::assertSame('Europe/Paris', $config->timeZone);
        self::assertSame('°C', $config->temperatureUnit);
        self::assertSame('2025.1.2', $config->version);
    }

    public function testConfigWithoutUnitSystemIsRejected(): void
    {
        $this->expectException(InvalidPayloadException::class);

        ResultMapper::config(['language' => 'fr', 'time_zone' => 'UTC', 'version' => '1']);
    }

    public function testStates(): void
    {
        $states = ResultMapper::states([
            ['entity_id' => 'sensor.a', 'state' => '1', 'attributes' => [], 'last_changed' => '2024-01-01T00:00:00+00:00', 'last_updated' => '2024-01-01T00:00:01+00:00', 'context' => ['id' => 'c', 'parent_id' => null, 'user_id' => null]],
            ['entity_id' => 'sensor.b', 'state' => '2', 'attributes' => ['x' => 1], 'last_changed' => '2024-01-01T00:00:00+00:00', 'context' => ['id' => 'd']],
        ]);

        self::assertCount(2, $states);
        self::assertSame('sensor.b', $states[1]->entityId);
        self::assertSame(['x' => 1], $states[1]->attributes);
    }

    public function testServiceCall(): void
    {
        $result = ResultMapper::serviceCall(['context' => ['id' => 'ctx', 'parent_id' => null, 'user_id' => 'u'], 'response' => null]);

        self::assertSame('ctx', $result->context->id);
        self::assertSame('u', $result->context->userId);
    }

    public function testServiceCallWithoutContextIsRejected(): void
    {
        $this->expectException(InvalidPayloadException::class);

        ResultMapper::serviceCall(['response' => null]);
    }

    public function testServices(): void
    {
        $services = ResultMapper::services([
            'notify' => [
                'mobile_app_phone' => ['name' => 'Send', 'description' => 'Notify the phone', 'fields' => ['message' => ['required' => true]]],
                'persistent_notification' => [],
            ],
            'climate' => ['set_temperature' => ['fields' => []]],
        ]);

        self::assertSame(['notify', 'climate'], array_keys($services));
        self::assertSame(['mobile_app_phone', 'persistent_notification'], array_keys($services['notify']));
        $phone = $services['notify']['mobile_app_phone'];
        self::assertSame('notify', $phone->domain);
        self::assertSame('mobile_app_phone', $phone->service);
        self::assertSame('Send', $phone->name);
        self::assertSame('Notify the phone', $phone->description);
        self::assertSame(['message' => ['required' => true]], $phone->fields);
        self::assertNull($services['climate']['set_temperature']->name);
    }

    public function testRegistries(): void
    {
        $areas = ResultMapper::areas([['area_id' => 'living', 'name' => 'Living room', 'floor_id' => 'ground', 'aliases' => []], ['area_id' => 'attic', 'name' => 'Attic']]);
        self::assertSame('living', $areas[0]->id);
        self::assertSame('ground', $areas[0]->floorId);
        self::assertNull($areas[1]->floorId);

        $devices = ResultMapper::devices([['id' => 'd1', 'name' => 'Thermostat', 'name_by_user' => 'Salon', 'area_id' => 'living', 'manufacturer' => 'ACME', 'model' => 'T1'], ['id' => 'd2']]);
        self::assertSame('Salon', $devices[0]->displayName());
        self::assertSame('ACME', $devices[0]->manufacturer);
        self::assertNull($devices[1]->displayName());

        $entries = ResultMapper::entityRegistry([
            ['entity_id' => 'climate.living', 'device_id' => 'd1', 'area_id' => null, 'platform' => 'tado', 'name' => null, 'original_name' => 'Living', 'disabled_by' => null, 'hidden_by' => 'user'],
        ]);
        self::assertSame('climate.living', $entries[0]->entityId);
        self::assertSame('d1', $entries[0]->deviceId);
        self::assertSame('tado', $entries[0]->platform);
        self::assertSame('user', $entries[0]->hiddenBy);
        self::assertNull($entries[0]->disabledBy);
    }

    public function testNotAListIsRejected(): void
    {
        $this->expectException(InvalidPayloadException::class);

        ResultMapper::areas('nope');
    }
}
