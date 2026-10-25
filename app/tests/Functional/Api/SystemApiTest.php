<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\Api\Exception\ApiException;
use App\Application\Api\Exception\ValidationException;
use App\Application\Api\HaTimeZone;
use App\Application\Api\IngressUser;
use App\Application\Api\Input\UserPreferencesRequest;
use App\Application\Api\RequestLocale;
use App\Application\Api\System\ContextService;
use App\Application\Api\System\PreferencesService;
use App\Infrastructure\Engine\EngineClient;
use App\Infrastructure\Persistence\Entity\GlobalSettings;
use App\Infrastructure\Persistence\Entity\UserPreference;
use App\Infrastructure\Persistence\Enum\ThemePreference;
use App\UI\Http\Api\ApiExceptionListener;
use App\UI\Http\Api\Identity\IngressUserFactory;
use App\UI\Http\Api\Identity\IngressUserValueResolver;
use App\UI\Http\Api\RequestLocaleListener;
use App\UI\Http\Api\SystemController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;

#[CoversClass(SystemController::class)]
#[CoversClass(ContextService::class)]
#[CoversClass(PreferencesService::class)]
#[CoversClass(UserPreferencesRequest::class)]
#[CoversClass(IngressUser::class)]
#[CoversClass(IngressUserFactory::class)]
#[CoversClass(IngressUserValueResolver::class)]
#[CoversClass(RequestLocale::class)]
#[CoversClass(RequestLocaleListener::class)]
#[CoversClass(ApiExceptionListener::class)]
#[CoversClass(HaTimeZone::class)]
#[CoversClass(EngineClient::class)]
#[CoversClass(ApiException::class)]
#[CoversClass(ValidationException::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\UI')]
#[UsesNamespace('App\Infrastructure')]
#[UsesNamespace('App\Domain')]
final class SystemApiTest extends ApiTestCase
{
    public function testTheHealthEndpointStaysUp(): void
    {
        $this->call('getHealth', 'GET', '/api/health')->assertStatus(200);
    }

    public function testTheContextTellsWhoYouAreAndWhatTheEngineKnows(): void
    {
        $context = $this->call('getContext', 'GET', '/api/context', null, self::asUser('abc', 'alice', 'Alice A.'))->assertStatus(200)->json();

        self::assertSame(['id' => 'abc', 'name' => 'alice', 'displayName' => 'Alice A.'], $context['user']);
        self::assertSame(['en', 'fr'], $context['supportedLocales']);
        self::assertSame('fr', $context['haLanguage']);
        self::assertSame('Europe/Paris', $context['haTimeZone']);
        self::assertSame('2026.10.0', $context['haVersion']);
        self::assertSame(['locale' => null, 'theme' => 'auto'], $context['preferences']);
        self::assertSame(['running' => true, 'connected' => true], $context['engine']);
    }

    public function testWithoutIngressHeadersThereIsNoUser(): void
    {
        self::assertNull($this->call('getContext', 'GET', '/api/context')->json()['user']);
    }

    public function testMissingNameHeadersFallBackOnTheIdAndTheName(): void
    {
        $onlyId = $this->call('getContext', 'GET', '/api/context', null, ['X-Remote-User-Id' => 'abc'])->json()['user'];
        $noDisplay = $this->call('getContext', 'GET', '/api/context', null, ['X-Remote-User-Id' => 'abc', 'X-Remote-User-Name' => 'alice'])->json()['user'];

        self::assertSame(['id' => 'abc', 'name' => 'abc', 'displayName' => 'abc'], $onlyId);
        self::assertSame(['id' => 'abc', 'name' => 'alice', 'displayName' => 'alice'], $noDisplay);
    }

    public function testAnEngineThatIsDownIsReportedNotFatal(): void
    {
        $this->engine()->goDown();

        $context = $this->call('getContext', 'GET', '/api/context')->assertStatus(200)->json();

        self::assertNull($context['haLanguage']);
        self::assertNull($context['haTimeZone']);
        self::assertNull($context['haVersion']);
        self::assertSame(['running' => false, 'connected' => false], $context['engine']);
    }

    public function testAnEngineNotConnectedToHomeAssistantIsRunningButNotConnected(): void
    {
        $this->engine()->answer('GET', '/internal/context', ['connected' => false, 'haLanguage' => null, 'haTimeZone' => null, 'haVersion' => null]);

        self::assertSame(['running' => true, 'connected' => false], $this->call('getContext', 'GET', '/api/context')->json()['engine']);
    }

    public function testPreferencesAreStoredPerUserAndReturnedByTheContext(): void
    {
        $saved = $this->call('updateMyPreferences', 'PUT', '/api/me/preferences', ['locale' => 'fr', 'theme' => 'dark'], self::asUser('u1'))->assertStatus(200)->json();
        self::assertSame(['locale' => 'fr', 'theme' => 'dark'], $saved);

        self::assertSame($saved, $this->call('getContext', 'GET', '/api/context', null, self::asUser('u1'))->json()['preferences']);
        self::assertSame(['locale' => null, 'theme' => 'auto'], $this->call('getContext', 'GET', '/api/context', null, self::asUser('u2'))->json()['preferences']);

        $this->call('updateMyPreferences', 'PUT', '/api/me/preferences', ['locale' => null, 'theme' => 'light'], self::asUser('u1'))->assertStatus(200);
        $stored = $this->em()->getRepository(UserPreference::class)->findAll();
        self::assertCount(1, $stored, 'The same user keeps one row.');
        self::assertNull($stored[0]->getLocale());
        self::assertSame(ThemePreference::Light, $stored[0]->getTheme());
    }

    public function testPreferencesNeedAUserAndValidValues(): void
    {
        $this->call('updateMyPreferences', 'PUT', '/api/me/preferences', ['locale' => 'fr', 'theme' => 'dark'])->assertError(409, 'no_user');
        $this->call('updateMyPreferences', 'PUT', '/api/me/preferences', ['locale' => 'de', 'theme' => 'dark'], self::asUser())->assertViolation('locale');
        $this->call('updateMyPreferences', 'PUT', '/api/me/preferences', ['locale' => 'fr', 'theme' => 'pink'], self::asUser())->assertViolation('theme');
    }

    public function testTheLocaleOfARequestFollowsThePreferenceThenTheSettingsThenTheBrowser(): void
    {
        $english = 'This value should not be blank.';
        $french = 'Cette valeur ne doit pas être vide.';

        self::assertSame($english, $this->blankRoomMessage([]));
        self::assertSame($french, $this->blankRoomMessage(['Accept-Language' => 'fr']));

        $settings = $this->em()->find(GlobalSettings::class, GlobalSettings::SINGLETON_ID);
        self::assertNotNull($settings);
        $settings->configureLocales('en', null);
        $this->em()->flush();
        self::assertSame($english, $this->blankRoomMessage(['Accept-Language' => 'fr']), 'The default of the settings wins over the browser.');

        $this->call('updateMyPreferences', 'PUT', '/api/me/preferences', ['locale' => 'fr', 'theme' => 'auto'], self::asUser('u1'));
        self::assertSame($french, $this->blankRoomMessage(self::asUser('u1') + ['Accept-Language' => 'en']), 'The preference of the user wins over everything.');
        self::assertSame($english, $this->blankRoomMessage(self::asUser('u2')), 'Another user has no preference.');
    }

    public function testFailuresAreAlwaysJsonAndNeverLeakDetails(): void
    {
        $this->call(null, 'GET', '/api/nothing-here')->assertError(404, 'not_found');
        $method = $this->call(null, 'PATCH', '/api/rooms/abc')->assertStatus(405);
        self::assertSame('method_not_allowed', $method->json()['error']);
        self::assertNotNull($method->header('Allow'));

        $this->engine()->goDown();
        $unavailable = $this->call('listRoomStatuses', 'GET', '/api/status')->assertError(503, 'engine_unavailable');
        self::assertStringNotContainsString('refused', $unavailable->body());
    }

    /**
     * The first violation message of a room whose name is blank, in the language the headers ask for.
     *
     * @param array<string, string> $headers
     */
    private function blankRoomMessage(array $headers): string
    {
        return $this->call('createRoom', 'POST', '/api/rooms', ['name' => '', 'devices' => [], 'presetTemperatures' => []], $headers)->data()->at('violations.0.message')->text();
    }
}
