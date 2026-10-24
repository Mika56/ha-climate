<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Application\Engine\Lifecycle\ControlServerInterface;

/**
 * Control server for the engine scenario tests: remembers whether it was started, opens no socket.
 */
final class RecordingControlServer implements ControlServerInterface
{
    public int $starts = 0;
    public int $stops = 0;

    public function start(): void
    {
        ++$this->starts;
    }

    public function stop(): void
    {
        ++$this->stops;
    }
}
