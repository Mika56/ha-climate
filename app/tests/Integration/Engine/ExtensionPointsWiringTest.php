<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use App\Application\Engine\Alert\DeviceUnresponsiveHandler;
use App\Application\Engine\Command\CommandExecutor;
use App\Application\Engine\Facts\ActiveOverrideContributor;
use App\Application\Engine\Facts\CurrentTemperatureContributor;
use App\Application\Engine\Facts\HeatingRateContributor;
use App\Application\Engine\Facts\HouseEmptyContributor;
use App\Application\Engine\Facts\OpeningContributor;
use App\Application\Engine\Facts\RoomFactsBuilder;
use App\Application\Engine\Facts\RoomVacancyContributor;
use App\Application\Engine\Facts\VacationContributor;
use App\Application\Engine\Opening\OpeningNotifyHandler;
use App\Application\Engine\Timer\DailyMaintenanceHandler;
use App\Application\Engine\Timer\RoomReevaluateHandler;
use App\Application\Engine\Timer\TimerScheduler;
use App\Domain\Decision\Rule\HouseEmptyRule;
use App\Domain\Decision\Rule\NoDeviceForModeRule;
use App\Domain\Decision\Rule\OpeningRule;
use App\Domain\Decision\Rule\OverrideRule;
use App\Domain\Decision\Rule\PreheatRule;
use App\Domain\Decision\Rule\RoomVacancyRule;
use App\Domain\Decision\Rule\ScheduleRule;
use App\Domain\Decision\Rule\VacationRule;
use App\Domain\Decision\TargetResolver;
use App\Infrastructure\Engine\Http\InternalRouter;
use App\Infrastructure\Engine\Http\LiveRoute;
use App\Infrastructure\Engine\Http\ReloadRoute;
use App\Infrastructure\Engine\Http\StatusRoute;
use App\Tests\Double\ConfigurableCommandGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The extension points are discovered by tag (config/services.yaml): adding a rule, a fact contributor, a
 * command guard, a timer handler or an internal route only takes a new class.
 */
#[CoversClass(TargetResolver::class)]
#[CoversClass(RoomFactsBuilder::class)]
#[CoversClass(CommandExecutor::class)]
#[CoversClass(TimerScheduler::class)]
#[CoversClass(InternalRouter::class)]
#[UsesNamespace('App')]
final class ExtensionPointsWiringTest extends KernelTestCase
{
    public function testRulesAreChainedByPriority(): void
    {
        self::bootKernel();

        self::assertSame(
            [
                NoDeviceForModeRule::class,
                VacationRule::class,
                HouseEmptyRule::class,
                OpeningRule::class,
                OverrideRule::class,
                RoomVacancyRule::class,
                PreheatRule::class,
                ScheduleRule::class,
            ],
            array_map(get_debug_type(...), self::iterable(TargetResolver::class, 'rules')),
        );
    }

    public function testFactContributorsAreDiscovered(): void
    {
        self::bootKernel();

        $contributors = array_map(get_debug_type(...), self::iterable(RoomFactsBuilder::class, 'contributors'));

        self::assertContains(CurrentTemperatureContributor::class, $contributors);
        self::assertContains(ActiveOverrideContributor::class, $contributors);
        self::assertContains(HouseEmptyContributor::class, $contributors);
        self::assertContains(RoomVacancyContributor::class, $contributors);
        self::assertContains(VacationContributor::class, $contributors);
        self::assertContains(HeatingRateContributor::class, $contributors);
        self::assertContains(OpeningContributor::class, $contributors);
    }

    public function testCommandGuardsAreDiscovered(): void
    {
        self::bootKernel();

        self::assertContains(ConfigurableCommandGuard::class, array_map(get_debug_type(...), self::iterable(CommandExecutor::class, 'guards')));
    }

    public function testTimerHandlersAreDiscovered(): void
    {
        self::bootKernel();

        $handlers = array_map(get_debug_type(...), self::iterable(TimerScheduler::class, 'handlerServices'));

        self::assertContains(RoomReevaluateHandler::class, $handlers);
        self::assertContains(DailyMaintenanceHandler::class, $handlers);
        self::assertContains(OpeningNotifyHandler::class, $handlers);
        self::assertContains(DeviceUnresponsiveHandler::class, $handlers);
    }

    public function testInternalRoutesAreDiscovered(): void
    {
        self::bootKernel();

        $routes = array_map(get_debug_type(...), self::iterable(InternalRouter::class, 'routes'));

        self::assertContains(ReloadRoute::class, $routes);
        self::assertContains(StatusRoute::class, $routes);
        self::assertContains(LiveRoute::class, $routes);
    }

    /**
     * @param class-string $class
     *
     * @return list<object>
     */
    private static function iterable(string $class, string $property): array
    {
        $service = self::getContainer()->get($class);
        $value = (new \ReflectionProperty($class, $property))->getValue($service);
        self::assertIsIterable($value);

        $objects = [];
        foreach ($value as $item) {
            self::assertIsObject($item);
            $objects[] = $item;
        }

        return $objects;
    }
}
