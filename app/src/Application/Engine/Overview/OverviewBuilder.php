<?php

declare(strict_types=1);

namespace App\Application\Engine\Overview;

use App\Application\Engine\Configuration\ConfigurationRepository;
use App\Application\Engine\Facts\HouseEmptyContributor;
use App\Application\Engine\State\HomeAssistantContext;
use App\Application\Engine\State\StateStore;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\HomeAssistantException;
use App\Application\Port\HomeAssistant\HomeAssistantGatewayInterface;

/**
 * The live facts of the overview page that only the engine can read: every person of Home Assistant with its
 * state, the state of the configured weather entity, and since when the tracked persons have all been away.
 */
final readonly class OverviewBuilder
{
    public function __construct(
        private HomeAssistantGatewayInterface $gateway,
        private ConfigurationRepository $configuration,
        private StateStore $states,
        private HomeAssistantContext $context,
    ) {
    }

    /**
     * @return array{persons: list<array{entityId: string, name: string, state: string}>, weather: array<string, mixed>|null, houseEmptySince: string|null}
     *
     * @throws HomeAssistantException
     */
    public function build(): array
    {
        $configuration = $this->configuration->snapshot();
        $weatherEntity = null === $configuration->weatherEntity ? null : (string) $configuration->weatherEntity;

        $persons = [];
        $weather = null;
        foreach ($this->gateway->getStates() as $state) {
            if (str_starts_with($state->entityId, 'person.')) {
                $persons[] = [
                    'entityId' => $state->entityId,
                    'name' => self::text($state->attributes['friendly_name'] ?? null) ?? $state->entityId,
                    'state' => $state->state,
                ];
            }
            if ($state->entityId === $weatherEntity) {
                $weather = self::weather($state);
            }
        }

        $since = HouseEmptyContributor::emptySince($configuration->trackedPersons, $this->states);

        return [
            'persons' => $persons,
            'weather' => $weather,
            'houseEmptySince' => $since?->setTimezone($this->context->timeZone())->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function weather(EntityState $state): array
    {
        $forecast = $state->attributes['forecast'] ?? null;
        $today = \is_array($forecast) && \is_array($forecast[0] ?? null) ? $forecast[0] : [];

        return [
            'entityId' => $state->entityId,
            'condition' => $state->state,
            'temperature' => self::number($state->attributes['temperature'] ?? null),
            'humidity' => self::number($state->attributes['humidity'] ?? null),
            'temperatureLow' => self::number($today['templow'] ?? null),
            'temperatureHigh' => self::number($today['temperature'] ?? null),
        ];
    }

    private static function number(mixed $value): int|float|null
    {
        return \is_int($value) || \is_float($value) ? $value : (is_numeric($value) ? (float) $value : null);
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
