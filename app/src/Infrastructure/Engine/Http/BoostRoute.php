<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine\Http;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use App\Application\Engine\Override\OverrideCommands;
use App\Application\Engine\Override\OverrideRejectedException;
use App\Domain\Climate\TemperatureDelta;
use App\Domain\Exception\DomainExceptionInterface;

/**
 * `POST /internal/rooms/{roomId}/boost`: boosts a room, then answers with its new status.
 *
 * Body (JSON object, no other key accepted):
 * - `delta`: number from 0.5 to 5 (°C added when heating, removed when cooling);
 * - `durationMinutes`: optional integer from 1 to 1440;
 * - `untilNextSlot`: optional boolean, the boost ends when the next schedule slot starts;
 * - `actor`: optional name or id of the Home Assistant user (default `api`), kept in the audit trail.
 * At least one of `durationMinutes` and `untilNextSlot` is required; with both, the earlier end wins.
 */
final readonly class BoostRoute implements InternalRouteInterface
{
    public const float MIN_DELTA = 0.5;
    public const float MAX_DELTA = 5.0;
    public const int MAX_DURATION_MINUTES = 1440;
    public const string DEFAULT_ACTOR = 'api';

    private const array KEYS = ['delta', 'durationMinutes', 'untilNextSlot', 'actor'];

    public function __construct(private OverrideCommands $commands)
    {
    }

    public function method(): string
    {
        return 'POST';
    }

    public function path(): string
    {
        return '/internal/rooms/{roomId}/boost';
    }

    public function requiresToken(): bool
    {
        return true;
    }

    public function handle(Request $request): Response
    {
        try {
            $roomId = OverrideRouteSupport::roomId($request);
        } catch (DomainExceptionInterface $e) {
            return OverrideRouteSupport::badRequest($e->getMessage());
        }

        $body = json_decode($request->getBody()->buffer(), true);
        if (!\is_array($body) || ([] !== $body && array_is_list($body))) {
            return OverrideRouteSupport::badRequest('The body must be a JSON object.');
        }

        $unknown = array_diff(array_keys($body), self::KEYS);
        if ([] !== $unknown) {
            return OverrideRouteSupport::badRequest(\sprintf('Unknown field "%s".', implode('", "', array_map(strval(...), $unknown))));
        }

        $delta = $body['delta'] ?? null;
        if (!\is_int($delta) && !\is_float($delta)) {
            return OverrideRouteSupport::badRequest('"delta" is required and must be a number.');
        }
        if ($delta < self::MIN_DELTA || $delta > self::MAX_DELTA) {
            return OverrideRouteSupport::badRequest(\sprintf('"delta" must be between %.1f and %.1f.', self::MIN_DELTA, self::MAX_DELTA));
        }

        $duration = $body['durationMinutes'] ?? null;
        if (null !== $duration && (!\is_int($duration) || $duration < 1 || $duration > self::MAX_DURATION_MINUTES)) {
            return OverrideRouteSupport::badRequest(\sprintf('"durationMinutes" must be an integer between 1 and %d.', self::MAX_DURATION_MINUTES));
        }

        $untilNextSlot = $body['untilNextSlot'] ?? false;
        if (!\is_bool($untilNextSlot)) {
            return OverrideRouteSupport::badRequest('"untilNextSlot" must be a boolean.');
        }
        if (null === $duration && !$untilNextSlot) {
            return OverrideRouteSupport::badRequest('Give "durationMinutes" or set "untilNextSlot".');
        }

        $actor = $body['actor'] ?? self::DEFAULT_ACTOR;
        if (!\is_string($actor) || '' === trim($actor) || mb_strlen($actor) > 128) {
            return OverrideRouteSupport::badRequest('"actor" must be a string of 1 to 128 characters.');
        }

        try {
            $status = $this->commands->boost($roomId, new TemperatureDelta((float) $delta), $duration, $untilNextSlot, trim($actor));
        } catch (OverrideRejectedException $e) {
            return OverrideRouteSupport::rejected($e);
        }

        return JsonResponse::create($status);
    }
}
