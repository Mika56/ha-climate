<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine\Http;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use App\Application\Engine\Evaluation\LiveStatusPublisher;

/**
 * `GET /internal/status`: the latest status of every room.
 */
final readonly class StatusRoute implements InternalRouteInterface
{
    public function __construct(private LiveStatusPublisher $publisher)
    {
    }

    public function method(): string
    {
        return 'GET';
    }

    public function path(): string
    {
        return '/internal/status';
    }

    public function requiresToken(): bool
    {
        return true;
    }

    public function handle(Request $request): Response
    {
        return JsonResponse::create(['rooms' => $this->publisher->snapshot()]);
    }
}
