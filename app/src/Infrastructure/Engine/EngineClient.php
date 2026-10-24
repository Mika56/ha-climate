<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine;

use App\Infrastructure\Engine\Http\InternalRouter;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The web side's handle on the engine: tells it to reload the configuration and reads its status, through
 * the internal HTTP routes, authenticated with the shared token file.
 */
final readonly class EngineClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private EngineTokenStore $tokens,
        private string $baseUri,
    ) {
    }

    /**
     * Asks the engine to re-read the configuration and re-evaluate every room.
     *
     * @throws EngineUnavailableException
     */
    public function reload(): void
    {
        $this->request('POST', '/internal/reload');
    }

    /**
     * @return list<array<string, mixed>> the status of every room, as published by the engine
     *
     * @throws EngineUnavailableException
     */
    public function status(): array
    {
        $rooms = $this->request('GET', '/internal/status')['rooms'] ?? null;
        if (!\is_array($rooms) || !array_is_list($rooms)) {
            throw new EngineUnavailableException('The engine returned an unexpected status payload.');
        }

        /** @var list<array<string, mixed>> $list */
        $list = $rooms;

        return $list;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws EngineUnavailableException
     */
    private function request(string $method, string $path): array
    {
        $token = $this->tokens->read();
        if (null === $token) {
            throw new EngineUnavailableException('The engine token file is missing: the engine has not been started.');
        }

        try {
            $response = $this->httpClient->request($method, rtrim($this->baseUri, '/').$path, [
                'headers' => [InternalRouter::TOKEN_HEADER => $token, 'Accept' => 'application/json'],
                'timeout' => 10,
                'max_duration' => 30,
            ]);

            /** @var array<string, mixed> $data */
            $data = $response->toArray();

            return $data;
        } catch (ExceptionInterface $e) {
            throw new EngineUnavailableException(\sprintf('Engine request %s %s failed: %s', $method, $path, $e->getMessage()), 0, $e);
        }
    }
}
