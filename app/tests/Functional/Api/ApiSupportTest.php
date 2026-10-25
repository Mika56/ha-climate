<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Application\Api\ApiTime;
use App\Application\Api\Audit\AuditMessageRenderer;
use App\Application\Api\HaTimeZone;
use App\Application\Api\IngressUser;
use App\Application\Api\Input\EntityIdPattern;
use App\Application\Api\RequestLocale;
use App\Application\Api\Settings\NotificationSettingsApplier;
use App\Infrastructure\Persistence\Entity\AuditEntry;
use App\Infrastructure\Persistence\Entity\NotificationTypeSetting;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\Infrastructure\Persistence\Enum\NotificationType;
use App\UI\Http\Api\ApiExceptionListener;
use App\UI\Http\Api\Identity\IngressUserFactory;
use App\UI\Http\Api\Identity\IngressUserValueResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesNamespace;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

#[CoversClass(HaTimeZone::class)]
#[CoversClass(ApiTime::class)]
#[CoversClass(RequestLocale::class)]
#[CoversClass(AuditMessageRenderer::class)]
#[CoversClass(IngressUserValueResolver::class)]
#[CoversClass(IngressUserFactory::class)]
#[CoversClass(IngressUser::class)]
#[CoversClass(ApiExceptionListener::class)]
#[CoversClass(NotificationSettingsApplier::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\UI')]
#[UsesNamespace('App\Infrastructure')]
#[UsesNamespace('App\Domain')]
final class ApiSupportTest extends ApiTestCase
{
    public function testTheTimeZoneComesFromTheEngineAndIsRemembered(): void
    {
        self::assertSame('Europe/Paris', $this->service(HaTimeZone::class)->get()->getName());
        self::assertCount(1, $this->engine()->requestsTo('/internal/context'));

        // A new request (a new service instance) finds it in the cache instead of asking again.
        $this->engine()->goDown();
        self::assertSame('Europe/Paris', (new HaTimeZone($this->service(\App\Infrastructure\Engine\EngineClient::class), $this->cache()))->get()->getName());
    }

    public function testWithoutAnEngineNorACachedValueThePhpTimeZoneIsUsed(): void
    {
        $this->engine()->goDown();

        self::assertSame(date_default_timezone_get(), $this->service(HaTimeZone::class)->get()->getName());
    }

    public function testAnUnknownOrMissingTimeZoneIsIgnored(): void
    {
        $zone = $this->service(HaTimeZone::class);

        self::assertNull($zone->remember(null));
        self::assertNull($zone->remember('Mars/Olympus'));
        self::assertSame('Asia/Tokyo', $zone->remember('Asia/Tokyo')?->getName());
        self::assertSame('Asia/Tokyo', $zone->get()->getName());

        $this->engine()->answer('GET', '/internal/context', ['connected' => true, 'haLanguage' => 'en', 'haTimeZone' => 'Mars/Olympus', 'haVersion' => null]);
        $this->cache()->clear();
        self::assertSame(date_default_timezone_get(), (new HaTimeZone($this->service(\App\Infrastructure\Engine\EngineClient::class), $this->cache()))->get()->getName());
    }

    public function testTimesAreFormattedInTheHomeAssistantTimeZone(): void
    {
        $time = $this->service(ApiTime::class);

        self::assertNull($time->format(null));
        self::assertSame('2026-10-26T08:00:00+01:00', $time->format(new \DateTimeImmutable('2026-10-26 07:00:00', new \DateTimeZone('UTC'))));
        self::assertSame('Europe/Paris', $time->zone()->getName());
    }

    public function testTheLocaleFallsBackOnEnglish(): void
    {
        $locale = $this->service(RequestLocale::class);

        self::assertSame('en', $locale->resolve(null, null));
        self::assertSame('en', $locale->resolve(null, 'de'));
        self::assertSame('fr', $locale->resolve(null, 'fr'));
    }

    public function testOnlyTheIngressUserIsResolved(): void
    {
        $resolver = new IngressUserValueResolver(new IngressUserFactory());
        $request = Request::create('/api/context', 'GET', [], [], [], ['HTTP_X_REMOTE_USER_ID' => 'u1']);

        self::assertSame([], $resolver->resolve($request, new ArgumentMetadata('roomId', 'string', false, false, null)));
        $resolved = $resolver->resolve($request, new ArgumentMetadata('user', IngressUser::class, false, false, null, true));
        self::assertEquals([new IngressUser('u1', 'u1', 'u1')], \is_array($resolved) ? $resolved : iterator_to_array($resolved));
    }

    public function testAnInstantThatLooksValidButIsNotIsShownAsIs(): void
    {
        $entry = new AuditEntry(new \DateTimeImmutable(), ActorType::Automation, 'command', 'audit.command.deferred', ['device' => 'climate.x', 'until' => '2026-13-45T25:61:00Z']);

        self::assertSame('Command to climate.x postponed until 2026-13-45T25:61:00Z.', $this->service(AuditMessageRenderer::class)->render($entry, 'en'));
    }

    public function testPagesOutsideTheApiAreNotHandledByTheApiErrorFormat(): void
    {
        $this->client->request('GET', '/not-the-api');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testNotificationTypesThatAreMissingAreCreated(): void
    {
        $this->em()->createQuery('DELETE FROM '.NotificationTypeSetting::class.' t WHERE t.type = :type')->setParameter('type', NotificationType::WindowOpen)->execute();
        $this->em()->clear();

        $types = $this->call('updateNotificationSettings', 'PUT', '/api/notifications', [
            'targets' => [],
            'types' => [['type' => 'window_open', 'enabled' => true, 'delaySeconds' => 60, 'persistentNotification' => false]],
        ])->assertStatus(200)->data()->at('types');

        self::assertSame(['type' => 'window_open', 'enabled' => true, 'delaySeconds' => 60, 'persistentNotification' => false], $types->at('0')->value());
    }

    public function testThePatternsOfTheContract(): void
    {
        self::assertSame(1, preg_match(EntityIdPattern::ENTITY_ID, 'climate.living_room'));
        self::assertSame(0, preg_match(EntityIdPattern::ENTITY_ID, 'Climate.Living'));
        self::assertSame(1, preg_match(EntityIdPattern::PRESET_KEY, 'comfort_2'));
        self::assertSame(0, preg_match(EntityIdPattern::PRESET_KEY, str_repeat('a', 33)));
        self::assertSame(1, preg_match(EntityIdPattern::NOTIFY_SERVICE, 'notify.mobile_app_pixel'));
        self::assertSame(0, preg_match(EntityIdPattern::NOTIFY_SERVICE, 'light.kitchen'));
    }
}
