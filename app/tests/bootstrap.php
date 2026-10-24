<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ('test' === ($_SERVER['APP_ENV'] ?? null)) {
    // Rebuild the SQLite test database from scratch with the real migrations, once per run;
    // each test then runs inside a DAMA-managed rolled-back transaction.
    $database = dirname(__DIR__).'/var/data_test.db';
    foreach ([$database, $database.'-wal', $database.'-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    $kernel = new Kernel('test', true);
    $application = new Application($kernel);
    $application->setAutoExit(false);

    foreach ([
        ['command' => 'doctrine:migrations:migrate', '--no-interaction' => true, '--quiet' => true],
    ] as $input) {
        $output = new BufferedOutput();
        if (0 !== $application->run(new ArrayInput($input), $output)) {
            throw new RuntimeException('Could not prepare the test database: '.$output->fetch());
        }
    }

    $kernel->shutdown();
}
