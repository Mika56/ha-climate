<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use App\Application\Engine\Override\OverrideRejectedException;
use App\Domain\Exception\DomainExceptionInterface;
use App\Domain\Room\RoomId;

/**
 * Shared by the routes working on `/internal/rooms/{roomId}/...`.
 */
final class OverrideRouteSupport
{
    private function __construct()
    {
    }

    /**
     * @throws DomainExceptionInterface when the path carries an unusable room id
     */
    public static function roomId(Request $request): RoomId
    {
        $parameters = $request->getAttribute(InternalRouter::PARAMETERS_ATTRIBUTE);
        $value = \is_array($parameters) ? ($parameters['roomId'] ?? null) : null;

        return new RoomId(\is_string($value) ? rawurldecode($value) : '');
    }

    public static function badRequest(string $message): Response
    {
        return JsonResponse::create(['error' => 'invalid_request', 'message' => $message], HttpStatus::BAD_REQUEST);
    }

    public static function rejected(OverrideRejectedException $e): Response
    {
        $status = match ($e->reason) {
            OverrideRejectedException::ROOM_NOT_FOUND, OverrideRejectedException::NO_ACTIVE_OVERRIDE => HttpStatus::NOT_FOUND,
            OverrideRejectedException::NO_END => HttpStatus::BAD_REQUEST,
            default => HttpStatus::CONFLICT,
        };

        return JsonResponse::create(['error' => $e->reason, 'message' => $e->getMessage()], $status);
    }
}
