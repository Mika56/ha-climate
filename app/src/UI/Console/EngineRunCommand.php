<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Engine\Lifecycle\Engine;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the engine in the foreground until SIGTERM/SIGINT. This is the long-running process s6 supervises.
 */
#[AsCommand(name: 'app:engine:run', description: 'Run the climate engine (long-running, logs to stderr)')]
final class EngineRunCommand extends Command
{
    public function __construct(
        private readonly Engine $engine,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->engine->run();
        } catch (\Throwable $e) {
            $this->logger->critical('The engine stopped: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
