<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\Api\ApiTime;
use App\Application\Api\HaTimeZone;
use App\Application\Api\Input\NotificationSettingsRequest;
use App\Application\Api\Input\NotificationTargetRequest;
use App\Application\Api\Input\NotificationTypeRequest;
use App\Application\Api\Input\SeasonRequest;
use App\Application\Api\Input\SettingsRequest;
use App\Application\Api\Input\VacationRequest;
use App\Application\Api\Settings\NotificationSettingsApplier;
use App\Application\Api\Settings\NotificationSettingsService;
use App\Application\Api\Settings\SettingsApplier;
use App\Application\Api\Settings\SettingsNormalizer;
use App\Application\Api\Settings\SettingsService;
use App\Infrastructure\Persistence\Repository\NotificationTargetRepository;
use App\UI\Http\Api\SettingsController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

#[CoversClass(SettingsController::class)]
#[CoversClass(SettingsService::class)]
#[CoversClass(SettingsNormalizer::class)]
#[CoversClass(SettingsApplier::class)]
#[CoversClass(NotificationSettingsService::class)]
#[CoversClass(NotificationSettingsApplier::class)]
#[CoversClass(SettingsRequest::class)]
#[CoversClass(VacationRequest::class)]
#[CoversClass(SeasonRequest::class)]
#[CoversClass(NotificationSettingsRequest::class)]
#[CoversClass(NotificationTargetRequest::class)]
#[CoversClass(NotificationTypeRequest::class)]
#[CoversClass(ApiTime::class)]
#[CoversClass(HaTimeZone::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\UI')]
#[UsesNamespace('App\Infrastructure')]
#[UsesNamespace('App\Domain')]
final class SettingsApiTest extends ApiTestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function settingsBody(): array
    {
        return [
            'season' => 'winter',
            'trackedPersons' => ['person.alice', 'person.bob'],
            'houseEmptyDelaySeconds' => 1800,
            'manualOverrideMinutes' => 90,
            'vacation' => ['enabled' => true, 'startAt' => '2026-12-20T08:00:00+01:00', 'returnAt' => '2027-01-03T18:30:00Z', 'offDuringVacation' => false],
            'weatherEntity' => 'weather.home',
            'uiLocale' => 'fr',
            'notificationLocale' => null,
            'auditRetentionDays' => 30,
            'auditEntryCount' => 12345,
        ];
    }

    public function testTheDefaultSettingsAreShown(): void
    {
        $settings = $this->call('getSettings', 'GET', '/api/settings')->assertStatus(200)->json();

        self::assertSame('off', $settings['season']);
        self::assertSame([], $settings['trackedPersons']);
        self::assertSame(['enabled' => false, 'startAt' => null, 'returnAt' => null, 'offDuringVacation' => true], $settings['vacation']);
        self::assertNull($settings['weatherEntity']);
        self::assertSame(90, $settings['auditRetentionDays']);
        self::assertIsInt($settings['auditEntryCount']);
    }

    public function testTheSettingsAreSavedAuditedAndShownInTheHomeAssistantTimeZone(): void
    {
        $saved = $this->call('updateSettings', 'PUT', '/api/settings', self::settingsBody(), self::asUser())->assertStatus(200)->json();

        self::assertSame('winter', $saved['season']);
        self::assertSame(['person.alice', 'person.bob'], $saved['trackedPersons']);
        self::assertSame(['enabled' => true, 'startAt' => '2026-12-20T08:00:00+01:00', 'returnAt' => '2027-01-03T19:30:00+01:00', 'offDuringVacation' => false], $saved['vacation']);
        self::assertSame('fr', $saved['uiLocale']);
        self::assertNotSame(12345, $saved['auditEntryCount'], 'The count is read-only.');
        self::assertSame($saved, $this->call('getSettings', 'GET', '/api/settings')->json());

        $entry = $this->auditEntries('audit.configuration.settings_updated')[0];
        self::assertSame('Alice', $entry->getActorName());
        $payload = $entry->getPayload();
        self::assertIsArray($payload['before']);
        self::assertIsArray($payload['after']);
        self::assertSame('off', $payload['before']['season']);
        self::assertSame('winter', $payload['after']['season']);
        self::assertCount(1, $this->engine()->requestsTo('/internal/reload'));
    }

    public function testInvalidSettingsAreRefused(): void
    {
        $body = [...self::settingsBody(), 'houseEmptyDelaySeconds' => -5, 'manualOverrideMinutes' => 0, 'auditRetentionDays' => 9999, 'trackedPersons' => ['bad'], 'weatherEntity' => 'x', 'uiLocale' => 'de'];

        $this->call('updateSettings', 'PUT', '/api/settings', $body)
            ->assertViolation('houseEmptyDelaySeconds')->assertViolation('manualOverrideMinutes')->assertViolation('auditRetentionDays')
            ->assertViolation('trackedPersons[0]')->assertViolation('weatherEntity')->assertViolation('uiLocale');
    }

    public function testAVacationNeedsAReturnDateAfterItsStart(): void
    {
        $noReturn = [...self::settingsBody(), 'vacation' => ['enabled' => true, 'startAt' => null, 'returnAt' => null, 'offDuringVacation' => true]];
        $this->call('updateSettings', 'PUT', '/api/settings', $noReturn)->assertViolation('vacation.returnAt');

        $backwards = [...self::settingsBody(), 'vacation' => ['enabled' => false, 'startAt' => '2026-12-20T08:00:00+01:00', 'returnAt' => '2026-12-19T08:00:00+01:00', 'offDuringVacation' => true]];
        $this->call('updateSettings', 'PUT', '/api/settings', $backwards)->assertViolation('vacation.returnAt');

        $disabled = [...self::settingsBody(), 'vacation' => ['enabled' => false, 'startAt' => null, 'returnAt' => null, 'offDuringVacation' => true]];
        $this->call('updateSettings', 'PUT', '/api/settings', $disabled)->assertStatus(200);
    }

    public function testTheSeasonIsChangedAndAudited(): void
    {
        $response = $this->call('updateSeason', 'PUT', '/api/season', ['season' => 'summer'], self::asUser())->assertStatus(200);

        self::assertSame(['season' => 'summer'], $response->json());
        self::assertSame('summer', $this->call('getSettings', 'GET', '/api/settings')->json()['season']);
        self::assertSame(['season' => 'summer'], $this->auditEntries('audit.configuration.season_changed')[0]->getParams());
        self::assertCount(1, $this->engine()->requestsTo('/internal/reload'));

        $this->call('updateSeason', 'PUT', '/api/season', ['season' => 'spring'])->assertViolation('season');
    }

    public function testTheNotificationSettingsListEveryDestinationHomeAssistantOffers(): void
    {
        $this->engine()->answer('GET', '/internal/discovery', ['notifyServices' => [['service' => 'notify.mobile_app_pixel', 'name' => 'Pixel'], ['service' => 'notify.living_tv', 'name' => 'TV'], 'junk', ['service' => 3]]]);
        $this->service(NotificationTargetRepository::class)->save(new \App\Infrastructure\Persistence\Entity\NotificationTarget('notify.mobile_app_pixel', true));
        $this->service(NotificationTargetRepository::class)->save(new \App\Infrastructure\Persistence\Entity\NotificationTarget('notify.old_phone', false));

        $settings = $this->call('getNotificationSettings', 'GET', '/api/notifications')->assertStatus(200)->data();

        self::assertSame([
            ['service' => 'notify.living_tv', 'enabled' => false, 'name' => 'TV', 'available' => true],
            ['service' => 'notify.mobile_app_pixel', 'enabled' => true, 'name' => 'Pixel', 'available' => true],
            ['service' => 'notify.old_phone', 'enabled' => false, 'name' => null, 'available' => false],
        ], $settings->at('targets')->value());
        self::assertSame(['window_open', 'setpoint_not_applicable', 'device_unresponsive', 'sensor_unavailable'], $settings->at('types')->column('type'));
        self::assertSame(['type' => 'device_unresponsive', 'enabled' => true, 'delaySeconds' => 300, 'persistentNotification' => true], $settings->at('types.2')->value());
    }

    public function testTheStoredDestinationsAreShownWhileTheEngineIsDown(): void
    {
        $this->engine()->goDown();
        $this->service(NotificationTargetRepository::class)->save(new \App\Infrastructure\Persistence\Entity\NotificationTarget('notify.mobile_app_pixel', true));

        $targets = $this->call('getNotificationSettings', 'GET', '/api/notifications')->assertStatus(200)->json()['targets'];

        self::assertSame([['service' => 'notify.mobile_app_pixel', 'enabled' => true, 'name' => null, 'available' => true]], $targets);
    }

    public function testTheNotificationSettingsAreSavedAndReplaceTheDestinations(): void
    {
        $this->engine()->answer('GET', '/internal/discovery', ['notifyServices' => [['service' => 'notify.a', 'name' => 'A'], ['service' => 'notify.b', 'name' => 'B']]]);
        $this->service(NotificationTargetRepository::class)->save(new \App\Infrastructure\Persistence\Entity\NotificationTarget('notify.gone', true));
        $this->service(NotificationTargetRepository::class)->save(new \App\Infrastructure\Persistence\Entity\NotificationTarget('notify.a', true));

        $saved = $this->call('updateNotificationSettings', 'PUT', '/api/notifications', [
            'targets' => [['service' => 'notify.a', 'enabled' => false, 'name' => 'ignored'], ['service' => 'notify.b', 'enabled' => true]],
            'types' => [['type' => 'window_open', 'enabled' => false, 'delaySeconds' => 600, 'persistentNotification' => false]],
        ], self::asUser())->assertStatus(200)->data();

        self::assertSame([['service' => 'notify.a', 'enabled' => false, 'name' => 'A', 'available' => true], ['service' => 'notify.b', 'enabled' => true, 'name' => 'B', 'available' => true]], $saved->at('targets')->value());
        self::assertSame(['type' => 'window_open', 'enabled' => false, 'delaySeconds' => 600, 'persistentNotification' => false], $saved->at('types.0')->value());
        self::assertSame(['type' => 'sensor_unavailable', 'enabled' => true, 'delaySeconds' => 600, 'persistentNotification' => true], $saved->at('types.3')->value(), 'Types that are not listed stay as they were.');
        self::assertCount(1, $this->auditEntries('audit.configuration.notifications_updated'));
        self::assertCount(1, $this->engine()->requestsTo('/internal/reload'));
    }

    public function testAnInvalidNotificationSettingIsRefused(): void
    {
        $this->call('updateNotificationSettings', 'PUT', '/api/notifications', [
            'targets' => [['service' => 'light.kitchen', 'enabled' => true]],
            'types' => [['type' => 'window_open', 'enabled' => true]],
        ])->assertViolation('targets[0].service');
        $this->call('updateNotificationSettings', 'PUT', '/api/notifications', ['targets' => [], 'types' => [['type' => 'earthquake', 'enabled' => true]]])->assertViolation('types[0].type');
        $this->call('updateNotificationSettings', 'PUT', '/api/notifications', [
            'targets' => [],
            'types' => [['type' => 'window_open', 'enabled' => true, 'delaySeconds' => -1]],
        ])->assertViolation('types[0].delaySeconds');
    }

    public function testATestNotificationIsSentThroughTheEngine(): void
    {
        $this->engine()->answer('POST', '/internal/notifications/test', ['sent' => 1, 'failed' => 0], 202);

        $response = $this->call('sendTestNotification', 'POST', '/api/notifications/test')->assertStatus(202);

        self::assertSame('', $response->body());
        self::assertCount(1, $this->engine()->requestsTo('/internal/notifications/test'));
    }

    public function testATestNotificationNeedsAnEnabledDestinationAndAnEngine(): void
    {
        $this->engine()->answer('POST', '/internal/notifications/test', ['error' => 'no_enabled_target', 'message' => 'None.'], 409);
        $this->call('sendTestNotification', 'POST', '/api/notifications/test')->assertError(409, 'no_enabled_target');

        $this->engine()->goDown();
        $this->call('sendTestNotification', 'POST', '/api/notifications/test')->assertError(503, 'engine_unavailable');
    }

    public function testDiscoveryComesFromTheEngine(): void
    {
        $discovery = [
            'areas' => [['id' => 'living', 'name' => 'Living room']],
            'persons' => [['entityId' => 'person.alice', 'name' => 'Alice', 'areaId' => null, 'state' => 'home', 'unit' => null, 'deviceClass' => null]],
            'climates' => [['entityId' => 'climate.living', 'name' => 'Living', 'areaId' => 'living', 'state' => 'heat', 'unit' => null, 'deviceClass' => null, 'hvacModes' => ['off', 'heat']]],
            'temperatureSensors' => [],
            'presenceSensors' => [],
            'openingSensors' => [],
            'timeEntities' => [],
            'weather' => [],
            'notifyServices' => [['service' => 'notify.mobile_app_pixel', 'name' => 'Pixel']],
        ];
        $this->engine()->answer('GET', '/internal/discovery', $discovery);

        self::assertSame($discovery, $this->call('getDiscovery', 'GET', '/api/discovery')->assertStatus(200)->json());

        $this->engine()->answer('GET', '/internal/discovery', ['error' => 'home_assistant_unavailable'], 503);
        $this->call('getDiscovery', 'GET', '/api/discovery')->assertError(503, 'engine_unavailable');
    }
}
