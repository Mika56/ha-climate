<?php

declare(strict_types=1);

namespace App\Infrastructure\HomeAssistant;

use App\Application\Port\HomeAssistant\HomeAssistantException;

/**
 * Builds {@see ConnectionSettings} from the environment.
 *
 * Inside the Supervisor (SUPERVISOR_TOKEN set) the add-on talks to the core proxy. Otherwise (standalone
 * development) HA_URL and HA_TOKEN (a long-lived access token) are used.
 */
final class ConnectionSettingsFactory
{
    public const string SUPERVISOR_URL = 'ws://supervisor/core/websocket';

    public static function fromEnvironment(
        #[\SensitiveParameter]
        string $supervisorToken,
        string $haUrl,
        #[\SensitiveParameter]
        string $haToken,
    ): ConnectionSettings {
        if ('' !== $supervisorToken) {
            return new ConnectionSettings(self::SUPERVISOR_URL, $supervisorToken);
        }

        if ('' === $haUrl || '' === $haToken) {
            throw new HomeAssistantException('Home Assistant is not configured: set SUPERVISOR_TOKEN (add-on) or both HA_URL and HA_TOKEN (standalone).');
        }

        return new ConnectionSettings(self::toWebsocketUrl($haUrl), $haToken);
    }

    /**
     * http(s)://host:port[/prefix] becomes ws(s)://host:port[/prefix]/api/websocket.
     */
    public static function toWebsocketUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        $scheme = \is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = \is_array($parts) ? ($parts['host'] ?? '') : '';
        if (!\in_array($scheme, ['http', 'https', 'ws', 'wss'], true) || '' === $host) {
            throw new HomeAssistantException(\sprintf('HA_URL "%s" is not a valid http(s) URL.', $url));
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');
        if (str_ends_with($path, '/api/websocket')) {
            $path = substr($path, 0, -\strlen('/api/websocket'));
        }

        return \sprintf('%s://%s%s%s/api/websocket', \in_array($scheme, ['https', 'wss'], true) ? 'wss' : 'ws', $host, $port, $path);
    }
}
