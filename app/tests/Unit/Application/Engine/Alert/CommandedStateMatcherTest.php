<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Engine\Alert;

use App\Application\Engine\Alert\CommandedStateMatcher;
use App\Application\Port\HomeAssistant\EntityState;
use App\Application\Port\HomeAssistant\StateContext;
use App\Domain\Climate\HvacMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommandedStateMatcher::class)]
#[UsesClass(EntityState::class)]
#[UsesClass(StateContext::class)]
final class CommandedStateMatcherTest extends TestCase
{
    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('cases')]
    public function testComparesAModeAndATarget(string $state, array $attributes, HvacMode $mode, ?float $temperature, bool $expected): void
    {
        $entity = new EntityState('climate.living', $state, $attributes, new \DateTimeImmutable(), new \DateTimeImmutable(), new StateContext('c'));

        self::assertSame($expected, (new CommandedStateMatcher())->matches($entity, $mode, $temperature));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, HvacMode, float|null, bool}>
     */
    public static function cases(): iterable
    {
        yield 'same mode and target' => ['heat', ['temperature' => 21.0], HvacMode::Heat, 21.0, true];
        yield 'target within the device precision' => ['heat', ['temperature' => 21.02], HvacMode::Heat, 21.0, true];
        yield 'another target' => ['heat', ['temperature' => 19.0], HvacMode::Heat, 21.0, false];
        yield 'another mode' => ['cool', ['temperature' => 21.0], HvacMode::Heat, 21.0, false];
        yield 'off wanted and reached' => ['off', ['temperature' => 19.0], HvacMode::Off, null, true];
        yield 'off wanted, still heating' => ['heat', ['temperature' => 21.0], HvacMode::Off, null, false];
        yield 'mode-only command' => ['heat', ['temperature' => 19.0], HvacMode::Heat, null, true];
        yield 'device reporting no target' => ['heat', [], HvacMode::Heat, 21.0, true];
    }
}
