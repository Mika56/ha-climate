<?php

declare(strict_types=1);

namespace App\Infrastructure\HomeAssistant;

use App\Application\Port\HomeAssistant\Area;
use App\Application\Port\HomeAssistant\DeviceEntry;
use App\Application\Port\HomeAssistant\EntityRegistryEntry;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\HomeAssistantConfig;
use App\Application\Port\HomeAssistant\InvalidPayloadException;
use App\Application\Port\HomeAssistant\ServiceCallResult;
use App\Application\Port\HomeAssistant\ServiceDescription;

/**
 * Maps the `result` payload of WS commands to port DTOs, validating the shape on the way.
 */
final class ResultMapper
{
    private function __construct()
    {
    }

    /**
     * @throws InvalidPayloadException
     */
    public static function config(mixed $result): HomeAssistantConfig
    {
        $config = Payload::map($result, 'config');
        $units = Payload::optionalMap($config, 'unit_system');

        return new HomeAssistantConfig(
            Payload::string($config, 'language'),
            Payload::string($config, 'time_zone'),
            Payload::string($units, 'temperature'),
            Payload::string($config, 'version'),
        );
    }

    /**
     * @return list<EntityState>
     *
     * @throws InvalidPayloadException
     */
    public static function states(mixed $result): array
    {
        return array_map(
            CompressedStateDecoder::fromStateObject(...),
            Payload::listOfMaps($result, 'states'),
        );
    }

    /**
     * @throws InvalidPayloadException
     */
    public static function serviceCall(mixed $result): ServiceCallResult
    {
        $map = Payload::map($result, 'service call result');

        return new ServiceCallResult(CompressedStateDecoder::parseContext($map['context'] ?? null));
    }

    /**
     * @return array<string, array<string, ServiceDescription>>
     *
     * @throws InvalidPayloadException
     */
    public static function services(mixed $result): array
    {
        $services = [];
        foreach (Payload::map($result, 'services') as $domain => $domainServices) {
            foreach (Payload::map($domainServices, 'services of '.$domain) as $service => $description) {
                $fields = Payload::map($description, 'service '.$domain.'.'.$service);
                $services[$domain][$service] = new ServiceDescription(
                    $domain,
                    $service,
                    Payload::optionalString($fields, 'name'),
                    Payload::optionalString($fields, 'description'),
                    Payload::optionalMap($fields, 'fields'),
                );
            }
        }

        return $services;
    }

    /**
     * @return list<Area>
     *
     * @throws InvalidPayloadException
     */
    public static function areas(mixed $result): array
    {
        return array_map(
            static fn (array $area): Area => new Area(
                Payload::string($area, 'area_id'),
                Payload::string($area, 'name'),
                Payload::optionalString($area, 'floor_id'),
            ),
            Payload::listOfMaps($result, 'areas'),
        );
    }

    /**
     * @return list<DeviceEntry>
     *
     * @throws InvalidPayloadException
     */
    public static function devices(mixed $result): array
    {
        return array_map(
            static fn (array $device): DeviceEntry => new DeviceEntry(
                Payload::string($device, 'id'),
                Payload::optionalString($device, 'name'),
                Payload::optionalString($device, 'name_by_user'),
                Payload::optionalString($device, 'area_id'),
                Payload::optionalString($device, 'manufacturer'),
                Payload::optionalString($device, 'model'),
            ),
            Payload::listOfMaps($result, 'devices'),
        );
    }

    /**
     * @return list<EntityRegistryEntry>
     *
     * @throws InvalidPayloadException
     */
    public static function entityRegistry(mixed $result): array
    {
        return array_map(
            static fn (array $entry): EntityRegistryEntry => new EntityRegistryEntry(
                Payload::string($entry, 'entity_id'),
                Payload::optionalString($entry, 'device_id'),
                Payload::optionalString($entry, 'area_id'),
                Payload::optionalString($entry, 'platform'),
                Payload::optionalString($entry, 'name'),
                Payload::optionalString($entry, 'original_name'),
                Payload::optionalString($entry, 'disabled_by'),
                Payload::optionalString($entry, 'hidden_by'),
            ),
            Payload::listOfMaps($result, 'entity registry'),
        );
    }
}
