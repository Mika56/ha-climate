<?php

declare(strict_types=1);

namespace App\Application\Engine;

use App\Application\Engine\Configuration\EngineConfiguration;
use App\Application\Engine\State\StateStore;

/**
 * What the engine knows at the instant of an evaluation: the current time (in the Home Assistant time
 * zone), the configuration and the entity states. Handed to fact contributors and command guards.
 */
final readonly class EngineSnapshot
{
    public function __construct(
        public \DateTimeImmutable $now,
        public EngineConfiguration $configuration,
        public StateStore $states,
    ) {
    }
}
