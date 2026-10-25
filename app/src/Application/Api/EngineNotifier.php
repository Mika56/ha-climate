<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Infrastructure\Engine\EngineClient;
use App\Infrastructure\Engine\EngineUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * Tells the engine that the configuration changed. A write never fails because the engine is down: the engine
 * reads the configuration again when it starts, so the change is only applied later.
 */
final readonly class EngineNotifier
{
    public function __construct(
        private EngineClient $engine,
        private LoggerInterface $logger,
    ) {
    }

    public function configurationChanged(): void
    {
        try {
            $this->engine->reload();
        } catch (EngineUnavailableException $e) {
            $this->logger->warning('The engine could not be told to reload the configuration, it will read it when it starts: {message}', ['message' => $e->getMessage()]);
        }
    }
}
