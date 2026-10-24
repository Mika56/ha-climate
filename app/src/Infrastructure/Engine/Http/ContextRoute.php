<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine\Http;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use App\Application\Engine\State\HomeAssistantContext;

/**
 * `GET /internal/context`: what the engine knows about Home Assistant: whether it is connected, and its
 * language, time zone and version (null until the configuration was read).
 */
final readonly class ContextRoute implements InternalRouteInterface
{
    public function __construct(private HomeAssistantContext $context)
    {
    }

    public function method(): string
    {
        return 'GET';
    }

    public function path(): string
    {
        return '/internal/context';
    }

    public function requiresToken(): bool
    {
        return true;
    }

    public function handle(Request $request): Response
    {
        $known = $this->context->isKnown();

        return JsonResponse::create([
            'connected' => $this->context->isConnected(),
            'haLanguage' => $known ? $this->context->language() : null,
            'haTimeZone' => $known ? $this->context->timeZone()->getName() : null,
            'haVersion' => $this->context->version(),
        ]);
    }
}
