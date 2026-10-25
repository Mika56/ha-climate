<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Stands in for the engine's internal HTTP server behind the web side's HTTP client: answers the requests of
 * {@see \App\Infrastructure\Engine\EngineClient} with scripted responses and remembers what was asked.
 *
 *     $engine->answer('GET', '/internal/status', ['rooms' => [...]]);
 *     $engine->answer('POST', '/internal/rooms/abc/boost', ['error' => 'no_active_mode'], 409);
 *     $engine->goDown();                       // every request fails like a refused connection
 *     $engine->requests();                     // [['POST', '/internal/reload', null], ...]
 *
 * By default the engine is up, connected to a Paris Home Assistant, and accepts a reload.
 */
final class FakeEngine
{
    /** @var array<string, array{int, mixed}> */
    private array $answers = [];

    /** @var list<array{string, string, mixed}> */
    private array $requests = [];

    private bool $down = false;

    public function __construct()
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->down = false;
        $this->requests = [];
        $this->answers = [
            'GET /internal/context' => [200, ['connected' => true, 'haLanguage' => 'fr', 'haTimeZone' => 'Europe/Paris', 'haVersion' => '2026.10.0']],
            'POST /internal/reload' => [200, ['status' => 'reloaded']],
        ];
    }

    public function answer(string $method, string $path, mixed $body, int $status = 200): self
    {
        $this->answers[$method.' '.$path] = [$status, $body];

        return $this;
    }

    public function goDown(): self
    {
        $this->down = true;

        return $this;
    }

    /**
     * @return list<array{string, string, mixed}> method, path and decoded JSON body (null when none) of each request
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @return list<array{string, string, mixed}>
     */
    public function requestsTo(string $path): array
    {
        return array_values(array_filter($this->requests, static fn (array $request): bool => $request[1] === $path));
    }

    /**
     * The JSON body of the nth request to a path.
     *
     * @return array<string, mixed>
     */
    public function bodyOf(string $path, int $index = 0): array
    {
        $body = $this->requestsTo($path)[$index][2] ?? null;
        if (!\is_array($body)) {
            throw new \LogicException(\sprintf('The request %d to %s had no JSON object.', $index, $path));
        }

        return array_combine(array_map(strval(...), array_keys($body)), $body);
    }

    /**
     * The path (with its query) of the latest request.
     */
    public function lastPath(): string
    {
        return $this->requests[array_key_last($this->requests) ?? throw new \LogicException('No request yet.')][1];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        if ($this->down) {
            throw new TransportException('Connection refused (fake engine is down).');
        }

        $parts = parse_url($url);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $body = $options['body'] ?? null;
        $this->requests[] = [$method, $path, \is_string($body) && '' !== $body ? json_decode($body, true) : null];

        [$status, $content] = $this->answers[$method.' '.($parts['path'] ?? '/')] ?? [404, ['error' => 'not_found']];

        return new MockResponse(json_encode($content, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
