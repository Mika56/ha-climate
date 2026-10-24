<?php

declare(strict_types=1);

namespace App\Application\Engine;

use App\Application\Engine\Configuration\ConfigurationRepository;
use App\Application\Engine\State\HomeAssistantContext;
use App\Application\Engine\State\StateStore;
use Symfony\Component\Clock\ClockInterface;

final readonly class EngineSnapshotFactory
{
    public function __construct(
        private ClockInterface $clock,
        private HomeAssistantContext $context,
        private ConfigurationRepository $configuration,
        private StateStore $states,
    ) {
    }

    public function create(): EngineSnapshot
    {
        return new EngineSnapshot(
            $this->clock->now()->setTimezone($this->context->timeZone()),
            $this->configuration->snapshot(),
            $this->states,
        );
    }
}
