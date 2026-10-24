<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine\Http;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;

/**
 * Extension point (service tag `app.internal_route`, applied automatically to implementations): one route of
 * the engine's internal HTTP server. Adding a feature endpoint only takes a new class.
 *
 * The router answers 404/405 for unknown paths/methods and rejects, with 401, requests without the
 * `X-Internal-Token` header when {@see requiresToken()} is true, before {@see handle()} is called.
 */
interface InternalRouteInterface
{
    /**
     * Upper-case HTTP method.
     */
    public function method(): string;

    /**
     * Exact path, starting with a slash.
     */
    public function path(): string;

    /**
     * False only for routes that nginx exposes to the browser (the live stream); every route used by the
     * web side must require the token.
     */
    public function requiresToken(): bool;

    public function handle(Request $request): Response;
}
