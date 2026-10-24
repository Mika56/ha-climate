<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine;

/**
 * The shared secret between the web side and the engine's internal HTTP routes, kept in a file only the
 * add-on's user can read (mode 0600). The engine creates it at start when missing; the web side reads it.
 */
final readonly class EngineTokenStore
{
    public function __construct(private string $path)
    {
    }

    /**
     * Returns the token, creating the file with a random one when it does not exist (or is empty).
     */
    public function ensure(): string
    {
        $existing = $this->read();
        if (null !== $existing) {
            return $existing;
        }

        $directory = \dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create the directory "%s".', $directory));
        }

        $token = bin2hex(random_bytes(32));
        $temporary = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';
        // Created with a restrictive mode before any content is written, then moved atomically in place.
        if (false === touch($temporary) || !chmod($temporary, 0o600) || false === file_put_contents($temporary, $token) || !@rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new \RuntimeException(\sprintf('Cannot write the engine token file "%s".', $this->path));
        }

        return $token;
    }

    public function read(): ?string
    {
        if (!is_file($this->path)) {
            return null;
        }

        $token = trim((string) file_get_contents($this->path));

        return '' === $token ? null : $token;
    }

    public function matches(string $candidate): bool
    {
        $token = $this->read();

        return null !== $token && hash_equals($token, $candidate);
    }
}
