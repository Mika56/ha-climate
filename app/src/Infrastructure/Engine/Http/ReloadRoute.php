<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine\Http;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use App\Application\Engine\Lifecycle\ConfigurationReloader;

/**
 * `POST /internal/reload`: the web side saved a configuration change, apply it.
 */
final readonly class ReloadRoute implements InternalRouteInterface
{
    public function __construct(private ConfigurationReloader $reloader)
    {
    }

    public function method(): string
    {
        return 'POST';
    }

    public function path(): string
    {
        return '/internal/reload';
    }

    public function requiresToken(): bool
    {
        return true;
    }

    public function handle(Request $request): Response
    {
        $this->reloader->reload();

        return JsonResponse::create(['status' => 'reloaded']);
    }
}
