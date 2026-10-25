<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Infrastructure\Persistence\Repository\PresetRepository;
use App\Infrastructure\Persistence\Seed\DefaultDataSeeder;
use App\UI\Console\SeedDefaultsCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(SeedDefaultsCommand::class)]
#[CoversClass(DefaultDataSeeder::class)]
#[UsesNamespace('App\Infrastructure')]
#[UsesNamespace('App\Domain')]
final class SeedDefaultsCommandTest extends KernelTestCase
{
    public function testItSeedsTheDefaultsOnceAndIsIdempotent(): void
    {
        $kernel = self::bootKernel();
        $tester = new CommandTester((new Application($kernel))->find('app:seed-defaults'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression('/^\d+ default row\(s\) created\.$/', trim($tester->getDisplay()));

        self::assertNotNull(self::getContainer()->get(PresetRepository::class)->findOneByKey('eco'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame('0 default row(s) created.', trim($tester->getDisplay()));
    }
}
