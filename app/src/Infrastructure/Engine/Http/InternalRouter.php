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
 *
 * A route path may contain `{name}` placeholders (for instance `/internal/rooms/{roomId}/boost`); the values
 * they captured are handed to the route in the {@see self::PARAMETERS_ATTRIBUTE} request attribute.
 */
final readonly class InternalRouter implements RequestHandler
{
    public const string TOKEN_HEADER = 'X-Internal-Token';

    /** Request attribute holding the captured `{placeholders}` of the matched route path, by name. */
    public const string PARAMETERS_ATTRIBUTE = 'route_parameters';

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
        $parameters = [];
        foreach ($this->routes as $route) {
            $captured = self::capture($route->path(), $path);
            if (null !== $captured) {
                $matching[$route->method()] = $route;
                $parameters[$route->method()] = $captured;
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

        $request->setAttribute(self::PARAMETERS_ATTRIBUTE, $parameters[$method] ?? []);

        try {
            return $route->handle($request);
        } catch (\Throwable $e) {
            $this->logger->error('Internal route {method} {path} failed: {message}', ['method' => $method, 'path' => $path, 'message' => $e->getMessage(), 'exception' => $e]);

            return JsonResponse::error('internal_error', HttpStatus::INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Matches a path against a route path whose `{name}` segments each capture one non-empty segment.
     *
     * @return array<string, string>|null the captured values (empty for a fixed path), null when it does not match
     */
    private static function capture(string $pattern, string $path): ?array
    {
        if (!str_contains($pattern, '{')) {
            return $pattern === $path ? [] : null;
        }

        $regex = preg_replace_callback(
            '/\{(\w+)\}|[^{]+/',
            static fn (array $part): string => isset($part[1]) ? '(?P<'.$part[1].'>[^/]+)' : preg_quote($part[0], '#'),
            $pattern,
        );
        if (null === $regex || 1 !== preg_match('#^'.$regex.'$#', $path, $found)) {
            return null;
        }

        $values = [];
        foreach ($found as $name => $value) {
            if (\is_string($name)) {
                $values[$name] = $value;
            }
        }

        return $values;
    }
}
