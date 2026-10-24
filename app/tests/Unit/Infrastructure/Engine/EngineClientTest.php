<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Engine;

use App\Infrastructure\Engine\EngineClient;
use App\Infrastructure\Engine\EngineRejectedException;
use App\Infrastructure\Engine\EngineTokenStore;
use App\Infrastructure\Engine\EngineUnavailableException;
use App\Infrastructure\Engine\Http\InternalRouter;
use App\Tests\Double\CallRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(EngineClient::class)]
#[CoversClass(EngineRejectedException::class)]
#[UsesClass(EngineTokenStore::class)]
#[UsesClass(EngineUnavailableException::class)]
#[UsesClass(InternalRouter::class)]
final class EngineClientTest extends TestCase
{
    private string $tokenFile;

    protected function setUp(): void
    {
        $this->tokenFile = sys_get_temp_dir().'/engine-client-'.bin2hex(random_bytes(4)).'.token';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tokenFile)) {
            unlink($this->tokenFile);
        }
    }

    public function testReloadPostsWithTheTokenHeader(): void
    {
        file_put_contents($this->tokenFile, 'secret-token');
        $seen = new CallRecorder();
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($seen): MockResponse {
            $headers = $options['headers'] ?? [];
            $seen->record([$method, $url, \is_array($headers) && \in_array('X-Internal-Token: secret-token', $headers, true)]);

            return new MockResponse('{"status":"reloaded"}', ['response_headers' => ['content-type' => 'application/json']]);
        });

        $this->client($http)->reload();

        self::assertSame([['POST', 'http://127.0.0.1:8100/internal/reload', true]], $seen->calls);
    }

    public function testStatusReturnsTheRooms(): void
    {
        file_put_contents($this->tokenFile, 'secret-token');
        $http = new MockHttpClient(new MockResponse('{"rooms":[{"name":"Living room"},{"name":"Bedroom"}]}', ['response_headers' => ['content-type' => 'application/json']]));

        $rooms = $this->client($http)->status();

        self::assertSame([['name' => 'Living room'], ['name' => 'Bedroom']], $rooms);
    }

    public function testAMalformedStatusIsRefused(): void
    {
        file_put_contents($this->tokenFile, 'secret-token');
        $http = new MockHttpClient(new MockResponse('{"rooms":"nope"}', ['response_headers' => ['content-type' => 'application/json']]));

        $this->expectException(EngineUnavailableException::class);

        $this->client($http)->status();
    }

    public function testWithoutTokenFileTheEngineIsConsideredDown(): void
    {
        $this->expectException(EngineUnavailableException::class);
        $this->expectExceptionMessage('has not been started');

        $this->client(new MockHttpClient())->reload();
    }

    public function testAnErrorStatusOrANetworkFailureBecomesAnUnavailableEngine(): void
    {
        file_put_contents($this->tokenFile, 'secret-token');

        foreach ([new MockResponse('{"error":"unauthorized"}', ['http_code' => 401]), new MockResponse('', ['error' => 'Connection refused'])] as $response) {
            try {
                $this->client(new MockHttpClient($response))->reload();
                self::fail('The engine should have been reported unavailable.');
            } catch (EngineUnavailableException $e) {
                self::assertStringContainsString('Engine request POST /internal/reload failed', $e->getMessage());
                self::assertNotNull($e->getPrevious());
            }
        }
    }

    public function testBoostPostsTheJsonBodyAndReturnsTheStatus(): void
    {
        file_put_contents($this->tokenFile, 'secret-token');
        $seen = new CallRecorder();
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($seen): MockResponse {
            $seen->record([$method, $url, $options['body'] ?? null]);

            return new MockResponse('{"roomId":"r 1"}', ['response_headers' => ['content-type' => 'application/json']]);
        });

        $status = $this->client($http)->boost('r 1', 1.5, 60, true, 'alice');

        self::assertSame(['roomId' => 'r 1'], $status);
        self::assertSame([['POST', 'http://127.0.0.1:8100/internal/rooms/r%201/boost', '{"delta":1.5,"untilNextSlot":true,"actor":"alice","durationMinutes":60}']], $seen->calls);
    }

    public function testBoostWithoutDurationOmitsIt(): void
    {
        file_put_contents($this->tokenFile, 'secret-token');
        $seen = new CallRecorder();
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($seen): MockResponse {
            $seen->record($options['body'] ?? null);

            return new MockResponse('{}', ['response_headers' => ['content-type' => 'application/json']]);
        });

        $this->client($http)->boost('r1', 2.0, null, true, 'bob');

        self::assertSame(['{"delta":2.0,"untilNextSlot":true,"actor":"bob"}'], $seen->calls);
    }

    public function testCancelOverrideDeletesWithTheActor(): void
    {
        file_put_contents($this->tokenFile, 'secret-token');
        $seen = new CallRecorder();
        $http = new MockHttpClient(static function (string $method, string $url) use ($seen): MockResponse {
            $seen->record([$method, $url]);

            return new MockResponse('{"override":null}', ['response_headers' => ['content-type' => 'application/json']]);
        });

        $status = $this->client($http)->cancelOverride('r1', 'a b');

        self::assertSame(['override' => null], $status);
        self::assertSame([['DELETE', 'http://127.0.0.1:8100/internal/rooms/r1/override?actor=a%20b']], $seen->calls);
    }

    public function testARefusalOfTheEngineIsReportedWithItsCode(): void
    {
        file_put_contents($this->tokenFile, 'secret-token');
        $http = new MockHttpClient(new MockResponse('{"error":"room_not_found","message":"The room does not exist."}', ['http_code' => 404, 'response_headers' => ['content-type' => 'application/json']]));

        try {
            $this->client($http)->cancelOverride('ghost', 'alice');
            self::fail('The refusal should have been reported.');
        } catch (EngineRejectedException $e) {
            self::assertSame(404, $e->statusCode);
            self::assertSame('room_not_found', $e->error);
            self::assertSame('The room does not exist.', $e->getMessage());
        }
    }

    public function testARefusalWithoutDetailsGetsADefaultCode(): void
    {
        file_put_contents($this->tokenFile, 'secret-token');
        $http = new MockHttpClient(new MockResponse('{}', ['http_code' => 409, 'response_headers' => ['content-type' => 'application/json']]));

        try {
            $this->client($http)->boost('r1', 1.0, 10, false, 'alice');
            self::fail('The refusal should have been reported.');
        } catch (EngineRejectedException $e) {
            self::assertSame('rejected', $e->error);
            self::assertSame(409, $e->statusCode);
        }
    }

    private function client(MockHttpClient $http): EngineClient
    {
        return new EngineClient($http, new EngineTokenStore($this->tokenFile), 'http://127.0.0.1:8100/');
    }
}
