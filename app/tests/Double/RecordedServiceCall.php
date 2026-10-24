<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Application\Port\HomeAssistant\StateContext;

/**
 * A service call received by {@see FakeHomeAssistantGateway}.
 */
final readonly class RecordedServiceCall
{
    /**
     * @param array<string, mixed> $serviceData
     * @param array<string, mixed> $target
     */
    public function __construct(
        public string $domain,
        public string $service,
        public array $serviceData,
        public array $target,
        public StateContext $context,
    ) {
    }
}
