<?php

declare(strict_types=1);

namespace App\Tests\Integration\Engine;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\TimeoutCancellation;
use App\Domain\Climate\Season;
use App\Infrastructure\Engine\EngineTokenStore;
use App\Infrastructure\Engine\Http\BoostRoute;
use App\Infrastructure\Engine\Http\CancelOverrideRoute;
use App\Infrastructure\Engine\Http\InternalHttpServer;
use App\Infrastructure\Engine\Http\InternalRouter;
use App\Infrastructure\Engine\Http\OverrideRouteSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesNamespace;

/**
 * The boost and cancel routes on the real internal HTTP server (random loopback port, real HTTP client).
 */
#[CoversClass(BoostRoute::class)]
#[CoversClass(CancelOverrideRoute::class)]
#[CoversClass(OverrideRouteSupport::class)]
#[CoversClass(InternalRouter::class)]
#[UsesNamespace('App\Application')]
#[UsesNamespace('App\Domain')]
#[UsesNamespace('App\Infrastructure')]
final class OverrideRoutesTest extends EngineTestCase
{
    private HttpClient $http;

    private InternalHttpServer $server;

    private string $roomId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = HttpClientBuilder::buildDefault();
        $this->setSeason(Season::Winter);
        $this->roomId = $this->createRoom()->getId()->toRfc4122();
        $this->seedDevice(mode: 'heat', target: 21.0);
        $this->seedSensor();
        $this->startEngine();
        $this->server = $this->service(InternalHttpServer::class);
        $this->server->start();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        parent::tearDown();
    }

    public function testTheRoutesRequireTheToken(): void
    {
        self::assertSame(401, $this->send('POST', $this->boostPath(), '{"delta":1,"durationMinutes":30}', null)->getStatus());
        self::assertSame(401, $this->send('POST', $this->boostPath(), '{"delta":1,"durationMinutes":30}', 'wrong')->getStatus());
        self::assertSame(401, $this->send('DELETE', $this->overridePath(), null, null)->getStatus());
        self::assertSame([], $this->homeAssistant()->serviceCalls());
    }

    public function testABoostIsAppliedAndTheNewStatusReturned(): void
    {
        $response = $this->send('POST', $this->boostPath(), '{"delta":1.5,"durationMinutes":60,"actor":"alice"}');

        self::assertSame(200, $response->getStatus());
        $payload = $this->json($response);
        self::assertSame($this->roomId, $payload['roomId']);
        self::assertSame(['mode' => 'heat', 'target' => 22.5, 'reason' => ['code' => 'reason.boost', 'params' => ['delta' => 1.5, 'until' => '2026-10-26T09:00:00+01:00']]], $payload['decision']);
        self::assertIsArray($payload['override']);
        self::assertSame('boost', $payload['override']['type']);
        self::assertSame(22.5, $this->homeAssistant()->serviceCalls()[0]->serviceData['temperature']);
        self::assertSame('alice', $this->auditEntries('audit.override.boost_started')[0]->getActorId());
    }

    public function testABoostUntilTheNextSlotDefaultsTheActor(): void
    {
        $response = $this->send('POST', $this->boostPath(), '{"delta":2,"untilNextSlot":true}');

        self::assertSame(200, $response->getStatus());
        self::assertSame('api', $this->auditEntries('audit.override.boost_started')[0]->getActorId());
    }

    public function testTheBoostIsCancelled(): void
    {
        $this->send('POST', $this->boostPath(), '{"delta":2,"durationMinutes":60}');
        $this->deviceAppliesCommand($this->homeAssistant()->serviceCalls()[0]);
        $this->homeAssistant()->clearServiceCalls();

        $response = $this->send('DELETE', $this->overridePath('?actor=bob'));

        self::assertSame(200, $response->getStatus());
        $payload = $this->json($response);
        self::assertNull($payload['override']);
        self::assertSame(21.0, $this->homeAssistant()->serviceCalls()[0]->serviceData['temperature']);
        self::assertSame('bob', $this->auditEntries('audit.override.cancelled')[0]->getActorId());

        $again = $this->send('DELETE', $this->overridePath());
        self::assertSame(404, $again->getStatus());
        self::assertSame('no_active_override', $this->json($again)['error']);
    }

    /**
     * @param array<string, mixed>|string $body
     */
    #[DataProvider('invalidBoosts')]
    public function testInvalidInputIsRefusedWithA400(array|string $body): void
    {
        $response = $this->send('POST', $this->boostPath(), \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR));

        self::assertSame(400, $response->getStatus());
        $payload = $this->json($response);
        self::assertSame('invalid_request', $payload['error']);
        self::assertIsString($payload['message']);
        self::assertSame([], $this->homeAssistant()->serviceCalls());
        self::assertSame([], $this->auditEntries('audit.override.boost_started'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string}>
     */
    public static function invalidBoosts(): iterable
    {
        yield 'not json' => ['nope'];
        yield 'a scalar' => ['5'];
        yield 'a list' => ['[1,2]'];
        yield 'empty body' => [''];
        yield 'no delta' => [['durationMinutes' => 30]];
        yield 'delta as a string' => [['delta' => '1.5', 'durationMinutes' => 30]];
        yield 'delta too small' => [['delta' => 0.4, 'durationMinutes' => 30]];
        yield 'delta too large' => [['delta' => 5.1, 'durationMinutes' => 30]];
        yield 'duration as a float' => [['delta' => 1, 'durationMinutes' => 30.5]];
        yield 'duration zero' => [['delta' => 1, 'durationMinutes' => 0]];
        yield 'duration too long' => [['delta' => 1, 'durationMinutes' => 1441]];
        yield 'untilNextSlot as a string' => [['delta' => 1, 'untilNextSlot' => 'yes']];
        yield 'no end at all' => [['delta' => 1]];
        yield 'untilNextSlot false and no duration' => [['delta' => 1, 'untilNextSlot' => false]];
        yield 'blank actor' => [['delta' => 1, 'durationMinutes' => 30, 'actor' => '  ']];
        yield 'actor not a string' => [['delta' => 1, 'durationMinutes' => 30, 'actor' => 12]];
        yield 'actor too long' => [['delta' => 1, 'durationMinutes' => 30, 'actor' => str_repeat('a', 129)]];
        yield 'unknown field' => [['delta' => 1, 'durationMinutes' => 30, 'target' => 25]];
    }

    public function testTheLimitsOfTheInputAreAccepted(): void
    {
        self::assertSame(200, $this->send('POST', $this->boostPath(), '{"delta":0.5,"durationMinutes":1}')->getStatus());
        self::assertSame(200, $this->send('POST', $this->boostPath(), '{"delta":5,"durationMinutes":1440}')->getStatus());
    }

    public function testAnUnknownRoomIs404AndASeasonWithoutDeviceIs409(): void
    {
        $unknown = $this->send('POST', '/internal/rooms/ghost/boost', '{"delta":1,"durationMinutes":30}');
        self::assertSame(404, $unknown->getStatus());
        self::assertSame('room_not_found', $this->json($unknown)['error']);
        self::assertSame(404, $this->send('DELETE', '/internal/rooms/ghost/override')->getStatus());

        $this->setSeason(Season::Summer);
        $this->service(\App\Application\Engine\Lifecycle\ConfigurationReloader::class)->reload();
        $summer = $this->send('POST', $this->boostPath(), '{"delta":1,"durationMinutes":30}');
        self::assertSame(409, $summer->getStatus());
        self::assertSame('no_active_mode', $this->json($summer)['error']);
    }

    public function testBoostAndCancelAreRefusedWith409WhilePaused(): void
    {
        $this->setPaused(true);
        $this->service(\App\Application\Engine\Lifecycle\ConfigurationReloader::class)->reload();

        $boost = $this->send('POST', $this->boostPath(), '{"delta":1,"durationMinutes":30}');
        $cancel = $this->send('DELETE', $this->overridePath());

        self::assertSame(409, $boost->getStatus());
        self::assertSame('paused', $this->json($boost)['error']);
        self::assertSame(409, $cancel->getStatus());
        self::assertSame('paused', $this->json($cancel)['error']);
    }

    public function testABlankRoomIdAndABadActorQueryAreRefused(): void
    {
        self::assertSame(400, $this->send('POST', '/internal/rooms/%20/boost', '{"delta":1,"durationMinutes":30}')->getStatus());
        self::assertSame(400, $this->send('DELETE', $this->overridePath('?actor='))->getStatus());
        self::assertSame(400, $this->send('DELETE', $this->overridePath('?actor='.str_repeat('a', 129)))->getStatus());
    }

    public function testOtherMethodsAndPathsDoNotMatch(): void
    {
        $wrongMethod = $this->send('GET', $this->boostPath());
        self::assertSame(405, $wrongMethod->getStatus());
        self::assertSame('POST', $wrongMethod->getHeader('allow'));
        self::assertSame(404, $this->send('POST', '/internal/rooms/'.$this->roomId.'/boost/extra', '{}')->getStatus());
        self::assertSame(404, $this->send('POST', '/internal/rooms//boost', '{}')->getStatus());
    }

    private function boostPath(): string
    {
        return '/internal/rooms/'.$this->roomId.'/boost';
    }

    private function overridePath(string $query = ''): string
    {
        return '/internal/rooms/'.$this->roomId.'/override'.$query;
    }

    /**
     * @param non-empty-string $method
     */
    private function send(string $method, string $path, ?string $body = null, ?string $token = 'valid'): Response
    {
        $request = new Request(\sprintf('http://127.0.0.1:%d%s', $this->server->port(), $path), $method);
        if ('valid' === $token) {
            $token = $this->service(EngineTokenStore::class)->read();
        }
        if (null !== $token) {
            $request->setHeader('X-Internal-Token', $token);
        }
        if (null !== $body) {
            $request->setBody($body);
        }

        return $this->http->request($request, new TimeoutCancellation(5));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->getBody()->buffer(), true, 512, \JSON_THROW_ON_ERROR);

        return $payload;
    }
}
