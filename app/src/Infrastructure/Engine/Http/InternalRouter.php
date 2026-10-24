<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine\Http;

use Amp\Http\HttpStatus;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use App\Infrastructure\Engine\EngineTokenStore;
use Psr\Log\LoggerInterface;

/**
 * Dispatches requests to the tagged {@see InternalRouteInterface} services, enforcing the internal token.
 */
final readonly class InternalRouter implements RequestHandler
{
    public const string TOKEN_HEADER = 'X-Internal-Token';

    /**
     * @param iterable<InternalRouteInterface> $routes
     */
    public function __construct(
        private iterable $routes,
        private EngineTokenStore $tokens,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Asks the routes that keep responses open to end them (see {@see ShutdownAwareRouteInterface}).
     */
    public function shutdown(): void
    {
        foreach ($this->routes as $route) {
            if ($route instanceof ShutdownAwareRouteInterface) {
                $route->shutdown();
            }
        }
    }

    public function handleRequest(Request $request): Response
    {
        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());

        $matching = [];
        foreach ($this->routes as $route) {
            if ($route->path() === $path) {
                $matching[$route->method()] = $route;
            }
        }

        if ([] === $matching) {
            return JsonResponse::error('not_found', HttpStatus::NOT_FOUND);
        }

        $route = $matching[$method] ?? null;
        if (null === $route) {
            $response = JsonResponse::error('method_not_allowed', HttpStatus::METHOD_NOT_ALLOWED);
            $response->setHeader('allow', implode(', ', array_keys($matching)));

            return $response;
        }

        if ($route->requiresToken() && !$this->tokens->matches($request->getHeader(self::TOKEN_HEADER) ?? '')) {
            return JsonResponse::error('unauthorized', HttpStatus::UNAUTHORIZED);
        }

        try {
            return $route->handle($request);
        } catch (\Throwable $e) {
            $this->logger->error('Internal route {method} {path} failed: {message}', ['method' => $method, 'path' => $path, 'message' => $e->getMessage(), 'exception' => $e]);

            return JsonResponse::error('internal_error', HttpStatus::INTERNAL_SERVER_ERROR);
        }
    }
}
