<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Engine;

use App\Infrastructure\Engine\EngineClient;
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

    private function client(MockHttpClient $http): EngineClient
    {
        return new EngineClient($http, new EngineTokenStore($this->tokenFile), 'http://127.0.0.1:8100/');
    }
}
