<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\Api\ConfigurationAudit;
use App\Application\Api\EngineNotifier;
use App\Application\Api\Exception\ConflictException;
use App\Application\Api\Exception\NotFoundException;
use App\Application\Api\Exception\ValidationException;
use App\Application\Api\Input\PresetRequest;
use App\Application\Api\Preset\PresetService;
use App\Infrastructure\Persistence\Repository\ScheduleRepository;
use App\UI\Http\Api\ApiExceptionListener;
use App\UI\Http\Api\ApiJsonResponse;
use App\UI\Http\Api\PresetController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

#[CoversClass(PresetController::class)]
#[CoversClass(PresetService::class)]
#[CoversClass(PresetRequest::class)]
#[CoversClass(ConflictException::class)]
#[CoversClass(NotFoundException::class)]
#[CoversClass(ApiExceptionListener::class)]
#[CoversClass(ApiJsonResponse::class)]
#[CoversClass(ConfigurationAudit::class)]
#[CoversClass(EngineNotifier::class)]
#[CoversClass(ScheduleRepository::class)]
#[CoversClass(ValidationException::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\UI')]
#[UsesNamespace('App\Infrastructure')]
#[UsesNamespace('App\Domain')]
final class PresetApiTest extends ApiTestCase
{
    public function testThePresetsAreListedInOrder(): void
    {
        $presets = $this->call('listPresets', 'GET', '/api/presets')->assertStatus(200)->jsonList();

        self::assertSame(['comfort', 'eco', 'night', 'away'], array_column($presets, 'key'));
        self::assertSame(['key' => 'comfort', 'label' => 'Comfort', 'color' => '#ff9800', 'position' => 0], $presets[0]);
    }

    public function testAPresetIsCreatedUpdatedAndDeleted(): void
    {
        $created = $this->call('createPreset', 'POST', '/api/presets', ['key' => 'boost', 'label' => ' Boost ', 'color' => '#ff0000', 'position' => 7], self::asUser())->assertStatus(201)->json();
        self::assertSame(['key' => 'boost', 'label' => 'Boost', 'color' => '#ff0000', 'position' => 7], $created);

        $updated = $this->call('updatePreset', 'PUT', '/api/presets/boost', ['key' => 'ignored', 'label' => 'Turbo', 'color' => '#00ff00', 'position' => 2], self::asUser())->assertStatus(200)->json();
        self::assertSame(['key' => 'boost', 'label' => 'Turbo', 'color' => '#00ff00', 'position' => 2], $updated);

        $this->call('deletePreset', 'DELETE', '/api/presets/boost', null, self::asUser())->assertStatus(204);
        self::assertNotContains('boost', array_column($this->call('listPresets', 'GET', '/api/presets')->jsonList(), 'key'));

        $audit = array_map(static fn ($entry): string => $entry->getMessageKey(), $this->auditEntries());
        self::assertContains('audit.configuration.preset_created', $audit);
        self::assertContains('audit.configuration.preset_updated', $audit);
        self::assertContains('audit.configuration.preset_deleted', $audit);
        self::assertCount(3, $this->engine()->requestsTo('/internal/reload'));
    }

    public function testAPresetThatExistsCannotBeCreatedAgain(): void
    {
        $this->call('createPreset', 'POST', '/api/presets', ['key' => 'comfort', 'label' => 'Again', 'color' => '#ff0000'])->assertError(409, 'preset_exists');
    }

    public function testAnInvalidPresetIsRefused(): void
    {
        $response = $this->call('createPreset', 'POST', '/api/presets', ['key' => 'Bad Key', 'label' => '', 'color' => 'red', 'position' => -1]);

        $response->assertViolation('key')->assertViolation('label')->assertViolation('color')->assertViolation('position');
        $this->call('updatePreset', 'PUT', '/api/presets/comfort', ['key' => 'comfort', 'label' => str_repeat('x', 33), 'color' => '#ffffff'])->assertViolation('label');
    }

    public function testUnknownPresetsAreNotFound(): void
    {
        $this->call('updatePreset', 'PUT', '/api/presets/ghost', ['key' => 'ghost', 'label' => 'Ghost', 'color' => '#ffffff'])->assertError(404, 'not_found');
        $this->call('deletePreset', 'DELETE', '/api/presets/ghost')->assertError(404, 'not_found');
    }

    public function testThePoliciesPresetsCannotBeDeleted(): void
    {
        $this->call('deletePreset', 'DELETE', '/api/presets/eco')->assertError(409, 'preset_in_use');
        $this->call('deletePreset', 'DELETE', '/api/presets/away')->assertError(409, 'preset_in_use');
    }

    public function testAPresetUsedByAScheduleSlotCannotBeDeleted(): void
    {
        $this->createSchedule();

        $this->call('deletePreset', 'DELETE', '/api/presets/night')->assertError(409, 'preset_in_use');

        $this->call('createPreset', 'POST', '/api/presets', ['key' => 'spare', 'label' => 'Spare', 'color' => '#123456'])->assertStatus(201);
        $this->call('deletePreset', 'DELETE', '/api/presets/spare')->assertStatus(204);
    }

    public function testDeletingAPresetDropsTheTemperaturesRoomsHadForIt(): void
    {
        $this->call('createPreset', 'POST', '/api/presets', ['key' => 'spare', 'label' => 'Spare', 'color' => '#123456'])->assertStatus(201);
        $room = $this->createRoom(overrides: ['presetTemperatures' => [['preset' => 'spare', 'heat' => 19.0, 'cool' => null], ['preset' => 'comfort', 'heat' => 21.0, 'cool' => null]]]);

        $this->call('deletePreset', 'DELETE', '/api/presets/spare')->assertStatus(204);

        self::assertSame([['preset' => 'comfort', 'heat' => 21.0, 'cool' => null]], $this->call('getRoom', 'GET', '/api/rooms/'.$room)->json()['presetTemperatures']);
    }
}
