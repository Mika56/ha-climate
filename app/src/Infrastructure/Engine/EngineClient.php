<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine;

use App\Infrastructure\Engine\Http\InternalRouter;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The web side's handle on the engine: tells it to reload the configuration, reads its status, discovers entities and boosts rooms,
 * through the internal HTTP routes, authenticated with the shared token file.
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
     * Boosts a room (see `POST /internal/rooms/{roomId}/boost`) and returns its new status. At least one of
     * $durationMinutes and $untilNextSlot must be given.
     *
     * @param string $actor the Home Assistant user behind the request, for the audit trail
     *
     * @return array<string, mixed>
     *
     * @throws EngineUnavailableException
     * @throws EngineRejectedException
     */
    public function boost(string $roomId, float $delta, ?int $durationMinutes, bool $untilNextSlot, string $actor): array
    {
        $body = ['delta' => $delta, 'untilNextSlot' => $untilNextSlot, 'actor' => $actor];
        if (null !== $durationMinutes) {
            $body['durationMinutes'] = $durationMinutes;
        }

        return $this->request('POST', \sprintf('/internal/rooms/%s/boost', rawurlencode($roomId)), ['json' => $body], true);
    }

    /**
     * Cancels the boost or manual override of a room and returns its new status.
     *
     * @return array<string, mixed>
     *
     * @throws EngineUnavailableException
     * @throws EngineRejectedException
     */
    public function cancelOverride(string $roomId, string $actor): array
    {
        return $this->request('DELETE', \sprintf('/internal/rooms/%s/override', rawurlencode($roomId)), ['query' => ['actor' => $actor]], true);
    }

    /**
     * What the engine knows about Home Assistant: `connected`, `haLanguage`, `haTimeZone`, `haVersion`.
     *
     * @return array<string, mixed>
     *
     * @throws EngineUnavailableException
     */
    public function context(): array
    {
        return $this->request('GET', '/internal/context');
    }

    /**
     * The Home Assistant entities and services the configuration can use (the `Discovery` payload of the API).
     *
     * @return array<string, mixed>
     *
     * @throws EngineUnavailableException
     */
    public function discovery(): array
    {
        return $this->request('GET', '/internal/discovery');
    }

    /**
     * Live facts of the overview page: `persons`, `weather`, `houseEmptySince`.
     *
     * @return array<string, mixed>
     *
     * @throws EngineUnavailableException
     */
    public function overview(): array
    {
        return $this->request('GET', '/internal/overview');
    }

    /**
     * Sends a test notification to every enabled destination.
     *
     * @return array<string, mixed> the number of destinations reached (`sent`) and failed (`failed`)
     *
     * @throws EngineUnavailableException
     * @throws EngineRejectedException    409 when no destination is enabled
     */
    public function sendTestNotification(): array
    {
        return $this->request('POST', '/internal/notifications/test', [], true);
    }

    /**
     * @param array<string, mixed> $options        extra HttpClient options (`json`, `query`)
     * @param bool                 $answersRefusal whether the engine is expected to refuse the request with a 400, 404 or 409
     *                                             (a business refusal, raised as {@see EngineRejectedException}); for a plain
     *                                             read any such answer means the engine does not behave and counts as unavailable
     *
     * @return array<string, mixed>
     *
     * @throws EngineUnavailableException
     * @throws EngineRejectedException
     */
    private function request(string $method, string $path, array $options = [], bool $answersRefusal = false): array
    {
        $token = $this->tokens->read();
        if (null === $token) {
            throw new EngineUnavailableException('The engine token file is missing: the engine has not been started.');
        }

        try {
            $response = $this->httpClient->request($method, rtrim($this->baseUri, '/').$path, [
                ...$options,
                'headers' => [InternalRouter::TOKEN_HEADER => $token, 'Accept' => 'application/json'],
                'timeout' => 10,
                'max_duration' => 30,
            ]);

            $status = $response->getStatusCode();
            if ($answersRefusal && \in_array($status, [400, 404, 409], true)) {
                /** @var array<string, mixed> $error */
                $error = $response->toArray(false);
                $code = $error['error'] ?? null;
                $message = $error['message'] ?? null;

                throw new EngineRejectedException($status, \is_string($code) ? $code : 'rejected', \is_string($message) ? $message : 'The engine refused the request.');
            }

            /** @var array<string, mixed> $data */
            $data = $response->toArray();

            return $data;
        } catch (ExceptionInterface $e) {
            throw new EngineUnavailableException(\sprintf('Engine request %s %s failed: %s', $method, $path, $e->getMessage()), 0, $e);
        }
    }
}
