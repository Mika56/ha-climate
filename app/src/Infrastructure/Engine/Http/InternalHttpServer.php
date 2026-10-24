<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine\Http;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use App\Application\Engine\Lifecycle\ControlServerInterface;
use App\Infrastructure\Engine\EngineTokenStore;
use Psr\Log\LoggerInterface;

/**
 * The engine's HTTP server, bound to the loopback interface only. Port 0 picks a free one (tests).
 */
final class InternalHttpServer implements ControlServerInterface
{
    private ?SocketHttpServer $server = null;
    private int $boundPort = 0;

    public function __construct(
        private readonly InternalRouter $router,
        private readonly EngineTokenStore $tokens,
        private readonly LoggerInterface $logger,
        private readonly int $port,
        private readonly string $host = '127.0.0.1',
    ) {
    }

    public function start(): void
    {
        if (null !== $this->server) {
            return;
        }

        $this->tokens->ensure();

        // No compression: it would buffer the live event stream.
        $server = SocketHttpServer::createForDirectAccess($this->logger, enableCompression: false);
        $server->expose(new InternetAddress($this->host, max(0, min(65535, $this->port))));
        $server->start($this->router, new DefaultErrorHandler());

        $this->server = $server;
        $address = $server->getServers()[0]->getAddress();
        $this->boundPort = $address instanceof InternetAddress ? $address->getPort() : $this->port;
        $this->logger->info('Internal HTTP server listening on {host}:{port}.', ['host' => $this->host, 'port' => $this->boundPort]);
    }

    public function stop(): void
    {
        $this->router->shutdown();
        $this->server?->stop();
        $this->server = null;
    }

    /**
     * The port actually bound (differs from the configured one when that was 0).
     */
    public function port(): int
    {
        return $this->boundPort;
    }
}
