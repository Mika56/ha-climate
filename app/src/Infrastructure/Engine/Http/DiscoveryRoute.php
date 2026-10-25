<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use App\Application\Engine\Discovery\DiscoveryBuilder;
use App\Application\Port\HomeAssistant\HomeAssistantException;

/**
 * `GET /internal/discovery`: the Home Assistant entities and services the configuration can use, read live
 * (see {@see DiscoveryBuilder}). 503 when Home Assistant cannot be reached.
 */
final readonly class DiscoveryRoute implements InternalRouteInterface
{
    public function __construct(private DiscoveryBuilder $discovery)
    {
    }

    public function method(): string
    {
        return 'GET';
    }

    public function path(): string
    {
        return '/internal/discovery';
    }

    public function requiresToken(): bool
    {
        return true;
    }

    public function handle(Request $request): Response
    {
        try {
            return JsonResponse::create($this->discovery->build());
        } catch (HomeAssistantException $e) {
            return JsonResponse::create(['error' => 'home_assistant_unavailable', 'message' => $e->getMessage()], HttpStatus::SERVICE_UNAVAILABLE);
        }
    }
}
