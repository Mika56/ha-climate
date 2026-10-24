<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine\Http;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use App\Application\Engine\Override\OverrideCommands;
use App\Application\Engine\Override\OverrideRejectedException;
use App\Domain\Exception\DomainExceptionInterface;

/**
 * `DELETE /internal/rooms/{roomId}/override`: cancels the boost or manual override of a room and answers
 * with its new status. The optional `actor` query parameter names the Home Assistant user for the audit
 * trail (default `api`). 404 when the room has no override in force.
 */
final readonly class CancelOverrideRoute implements InternalRouteInterface
{
    public function __construct(private OverrideCommands $commands)
    {
    }

    public function method(): string
    {
        return 'DELETE';
    }

    public function path(): string
    {
        return '/internal/rooms/{roomId}/override';
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

        $actor = $request->getQueryParameter('actor') ?? BoostRoute::DEFAULT_ACTOR;
        if ('' === trim($actor) || mb_strlen($actor) > 128) {
            return OverrideRouteSupport::badRequest('"actor" must be a string of 1 to 128 characters.');
        }

        try {
            return JsonResponse::create($this->commands->cancel($roomId, trim($actor)));
        } catch (OverrideRejectedException $e) {
            return OverrideRouteSupport::rejected($e);
        }
    }
}
