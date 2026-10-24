<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use App\Application\Engine\Overview\OverviewBuilder;
use App\Application\Port\HomeAssistant\HomeAssistantException;

/**
 * `GET /internal/overview`: the persons of Home Assistant with their state, the configured weather entity and
 * since when the house has been empty (see {@see OverviewBuilder}). 503 when Home Assistant cannot be reached.
 */
final readonly class OverviewRoute implements InternalRouteInterface
{
    public function __construct(private OverviewBuilder $overview)
    {
    }

    public function method(): string
    {
        return 'GET';
    }

    public function path(): string
    {
        return '/internal/overview';
    }

    public function requiresToken(): bool
    {
        return true;
    }

    public function handle(Request $request): Response
    {
        try {
            return JsonResponse::create($this->overview->build());
        } catch (HomeAssistantException $e) {
            return JsonResponse::create(['error' => 'home_assistant_unavailable', 'message' => $e->getMessage()], HttpStatus::SERVICE_UNAVAILABLE);
        }
    }
}
