<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Infrastructure\Persistence\Seed\DefaultDataSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Inserts the default rows (presets, notification types, settings) that are missing, without touching existing
 * ones. Run by the add-on at start, after the migrations, so the web API is usable before the engine has run.
 */
#[AsCommand(name: 'app:seed-defaults', description: 'Insert the missing default presets, notification types and settings')]
final class SeedDefaultsCommand extends Command
{
    public function __construct(
        private readonly DefaultDataSeeder $seeder,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $created = $this->seeder->seed();
        $output->writeln(\sprintf('%d default row(s) created.', $created));

        return Command::SUCCESS;
    }
}
