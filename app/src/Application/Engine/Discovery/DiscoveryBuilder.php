<?php

declare(strict_types=1);

namespace App\Application\Engine\Discovery;

use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\HomeAssistantException;
use App\Application\Port\HomeAssistant\HomeAssistantGatewayInterface;
use App\Domain\Room\PresenceSignalKind;

/**
 * Reads Home Assistant (states, registries, services) and sorts out the entities and services the add-on can be
 * configured with: this is the payload of the `Discovery` schema of the API contract.
 *
 * - climates: `climate` entities that expose `hvac_modes`;
 * - temperature sensors: `sensor` with the temperature device class or a °C/°F unit;
 * - presence sensors: `binary_sensor` occupancy/presence/motion, and `input_boolean`;
 * - activity sources (other presence signals, with their default kind): `light`, `switch`, `fan` and `media_player`
 *   (active), `sensor` with the power device class (above), `sensor` whose state is neither numeric nor
 *   unavailable/unknown and that has no unit (equals: a room-presence sensor such as Bermuda or ESPresense);
 *   the presence sensors are not repeated;
 * - opening sensors: `binary_sensor` window/door/opening/garage_door, and `input_boolean`;
 * - time entities: `input_datetime` that have a time;
 * - weather and persons: the `weather` and `person` entities;
 * - notify services: every `notify.*` service except `notify.send_message` (it targets entities, not people).
 *
 * The area of an entity is its own, else the one of its device. Names are the friendly names.
 */
final readonly class DiscoveryBuilder
{
    private const array PRESENCE_CLASSES = ['occupancy', 'presence', 'motion'];
    private const array OPENING_CLASSES = ['window', 'door', 'opening', 'garage_door'];
    private const array TEMPERATURE_UNITS = ['°C', '°F'];
    private const string EXCLUDED_NOTIFY_SERVICE = 'send_message';

    public function __construct(private HomeAssistantGatewayInterface $gateway)
    {
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     *
     * @throws HomeAssistantException
     */
    public function build(): array
    {
        $areas = [];
        foreach ($this->gateway->listAreas() as $area) {
            $areas[$area->id] = $area->name;
        }

        $deviceAreas = [];
        foreach ($this->gateway->listDevices() as $device) {
            if (null !== $device->areaId) {
                $deviceAreas[$device->id] = $device->areaId;
            }
        }

        $entityAreas = [];
        $disabled = [];
        foreach ($this->gateway->listEntityRegistry() as $entry) {
            if (null !== $entry->disabledBy) {
                $disabled[$entry->entityId] = true;
            }
            $areaId = $entry->areaId ?? (null === $entry->deviceId ? null : ($deviceAreas[$entry->deviceId] ?? null));
            if (null !== $areaId) {
                $entityAreas[$entry->entityId] = $areaId;
            }
        }

        $climates = $persons = $temperatures = $presences = $activities = $openings = $times = $weather = [];
        foreach ($this->gateway->getStates() as $state) {
            if (isset($disabled[$state->entityId])) {
                continue;
            }

            $domain = explode('.', $state->entityId, 2)[0];
            $deviceClass = self::string($state->attributes['device_class'] ?? null);
            $item = self::item($state, $entityAreas[$state->entityId] ?? null, $deviceClass);

            switch ($domain) {
                case 'person':
                    $persons[] = $item;
                    break;
                case 'weather':
                    $weather[] = $item;
                    break;
                case 'climate':
                    $modes = self::modes($state->attributes['hvac_modes'] ?? null);
                    if ([] !== $modes) {
                        $climates[] = [...$item, 'hvacModes' => $modes];
                    }
                    break;
                case 'light':
                case 'switch':
                case 'fan':
                case 'media_player':
                    $activities[] = [...$item, 'defaultKind' => PresenceSignalKind::Active->value];
                    break;
                case 'sensor':
                    if ('power' === $deviceClass) {
                        $activities[] = [...$item, 'defaultKind' => PresenceSignalKind::Above->value];
                    } elseif (self::isTextual($state)) {
                        $activities[] = [...$item, 'defaultKind' => PresenceSignalKind::Equals->value];
                    }
                    if ('temperature' === $deviceClass || \in_array(self::string($state->attributes['unit_of_measurement'] ?? null), self::TEMPERATURE_UNITS, true)) {
                        $temperatures[] = $item;
                    }
                    break;
                case 'binary_sensor':
                    if (\in_array($deviceClass, self::PRESENCE_CLASSES, true)) {
                        $presences[] = $item;
                    }
                    if (\in_array($deviceClass, self::OPENING_CLASSES, true)) {
                        $openings[] = $item;
                    }
                    break;
                case 'input_boolean':
                    $presences[] = $item;
                    $openings[] = $item;
                    break;
                case 'input_datetime':
                    if (true === ($state->attributes['has_time'] ?? null)) {
                        $times[] = $item;
                    }
                    break;
            }
        }

        $areaList = [];
        foreach ($areas as $id => $name) {
            $areaList[] = ['id' => $id, 'name' => $name];
        }

        return [
            'areas' => self::sorted($areaList),
            'persons' => self::sorted($persons),
            'climates' => self::sorted($climates),
            'temperatureSensors' => self::sorted($temperatures),
            'presenceSensors' => self::sorted($presences),
            'activitySources' => self::sorted($activities),
            'openingSensors' => self::sorted($openings),
            'timeEntities' => self::sorted($times),
            'weather' => self::sorted($weather),
            'notifyServices' => $this->notifyServices(),
        ];
    }

    /**
     * @return list<array{service: string, name: string}>
     *
     * @throws HomeAssistantException
     */
    private function notifyServices(): array
    {
        $services = [];
        foreach ($this->gateway->getServices()['notify'] ?? [] as $service => $description) {
            if (self::EXCLUDED_NOTIFY_SERVICE === $service) {
                continue;
            }
            $services[] = ['service' => 'notify.'.$service, 'name' => $description->name ?? $service];
        }

        return self::sorted($services);
    }

    /**
     * @return array{entityId: string, name: string, areaId: string|null, state: string, unit: string|null, deviceClass: string|null}
     */
    private static function item(EntityState $state, ?string $areaId, ?string $deviceClass): array
    {
        return [
            'entityId' => $state->entityId,
            'name' => self::string($state->attributes['friendly_name'] ?? null) ?? $state->entityId,
            'areaId' => $areaId,
            'state' => $state->state,
            'unit' => self::string($state->attributes['unit_of_measurement'] ?? null),
            'deviceClass' => $deviceClass,
        ];
    }

    /**
     * A state that names something (a room) rather than measures it: not numeric, not a failure, no unit.
     */
    private static function isTextual(EntityState $state): bool
    {
        return !is_numeric($state->state)
            && !\in_array($state->state, ['unavailable', 'unknown'], true)
            && null === self::string($state->attributes['unit_of_measurement'] ?? null);
    }

    /**
     * @return list<string>
     */
    private static function modes(mixed $modes): array
    {
        return \is_array($modes) ? array_values(array_filter($modes, \is_string(...))) : [];
    }

    private static function string(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @template T of array<string, mixed>
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    private static function sorted(array $items): array
    {
        usort($items, static fn (array $a, array $b): int => [strtolower(self::sortKey($a['name'] ?? null)), self::sortKey($a['entityId'] ?? $a['id'] ?? $a['service'] ?? null)]
            <=> [strtolower(self::sortKey($b['name'] ?? null)), self::sortKey($b['entityId'] ?? $b['id'] ?? $b['service'] ?? null)]);

        return $items;
    }

    private static function sortKey(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
