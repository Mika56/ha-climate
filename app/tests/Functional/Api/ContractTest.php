<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\UI\Http\Api\AuditController;
use App\UI\Http\Api\BackupController;
use App\UI\Http\Api\OverviewController;
use App\UI\Http\Api\PresetController;
use App\UI\Http\Api\RoomController;
use App\UI\Http\Api\ScheduleController;
use App\UI\Http\Api\SettingsController;
use App\UI\Http\Api\SystemController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * The routes of the application and the paths of the OpenAPI contract describe the same API, and the requests
 * the tests rely on follow the request schemas of the contract.
 */
#[CoversClass(SystemController::class)]
#[CoversClass(OverviewController::class)]
#[CoversClass(RoomController::class)]
#[CoversClass(PresetController::class)]
#[CoversClass(ScheduleController::class)]
#[CoversClass(SettingsController::class)]
#[CoversClass(AuditController::class)]
#[CoversClass(BackupController::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\UI')]
#[UsesNamespace('App\Infrastructure')]
#[UsesNamespace('App\Domain')]
final class ContractTest extends ApiTestCase
{
    /** Served by the engine itself (nginx routes it there), not by Symfony. */
    private const string ENGINE_OPERATION = 'streamRoomStatuses';

    public function testEveryOperationOfTheContractHasARoute(): void
    {
        $router = $this->service(RouterInterface::class);

        foreach ($this->contract()->operations() as $operationId => ['path' => $path, 'method' => $method]) {
            if (self::ENGINE_OPERATION === $operationId) {
                continue;
            }

            $router->setContext((new RequestContext())->setMethod(strtoupper($method)));
            $match = $router->match(preg_replace(['/\{presetKey\}/', '/\{\w+\}/'], ['comfort', '0199e3a0-0000-7000-8000-000000000000'], $path) ?? $path);

            self::assertArrayHasKey('_route', $match, $operationId);
        }
    }

    public function testEveryApiRouteIsInTheContract(): void
    {
        $documented = [];
        foreach ($this->contract()->operations() as $operation) {
            $documented[strtoupper($operation['method']).' '.preg_replace('/\{\w+\}/', '{}', $operation['path'])] = true;
        }

        $router = $this->service(RouterInterface::class);
        foreach ($router->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/api')) {
                continue;
            }
            foreach ($route->getMethods() as $method) {
                self::assertArrayHasKey($method.' '.preg_replace('/\{\w+\}/', '{}', $route->getPath()), $documented, \sprintf('Route %s is not in the contract.', $name));
            }
        }
    }

    public function testTheRequestsOfTheTestsFollowTheContract(): void
    {
        $contract = $this->contract();
        $valid = [
            'createRoom' => self::roomBody(),
            'updateRoom' => self::roomBody(),
            'createSchedule' => self::scheduleBody(),
            'updateSchedule' => self::scheduleBody(),
            'createPreset' => ['key' => 'boost', 'label' => 'Boost', 'color' => '#ff0000', 'position' => 3],
            'startBoost' => ['delta' => 1.5, 'durationMinutes' => 60, 'untilNextSlot' => false],
            'updateSeason' => ['season' => 'winter'],
            'updateMyPreferences' => ['locale' => null, 'theme' => 'dark'],
            'reorderRooms' => ['roomIds' => ['0199e3a0-0000-7000-8000-000000000000']],
            'duplicateSchedule' => ['name' => 'Copy'],
        ];

        foreach ($valid as $operationId => $body) {
            // JSON round trip: the schema validator wants objects, not associative arrays.
            $decoded = json_decode(json_encode($body, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);
            self::assertSame([], $contract->validateRequest($operationId, $decoded), $operationId);
        }

        $backup = json_decode($this->call('exportConfiguration', 'GET', '/api/backup')->body(), false, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([], $contract->validateRequest('restoreConfiguration', $backup));
        $settings = json_decode($this->call('getSettings', 'GET', '/api/settings')->body(), false, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([], $contract->validateRequest('updateSettings', $settings));
        $notifications = json_decode($this->call('getNotificationSettings', 'GET', '/api/notifications')->body(), false, 512, \JSON_THROW_ON_ERROR);
        self::assertSame([], $contract->validateRequest('updateNotificationSettings', $notifications));
    }

    public function testTheContractCatchesAnAnswerThatDoesNotMatch(): void
    {
        $problems = $this->contract()->validateJson('listPresets', 200, [(object) ['key' => 'Not A Key', 'label' => '', 'color' => 'red']]);

        self::assertNotSame([], $problems);
        self::assertNotSame([], $this->contract()->validateJson('listPresets', 418, []));
        self::assertNotSame([], $this->contract()->validateJson('exportAudit', 200, []));
        self::assertNotSame([], $this->contract()->validateRequest('deleteRoom', null));
    }
}
