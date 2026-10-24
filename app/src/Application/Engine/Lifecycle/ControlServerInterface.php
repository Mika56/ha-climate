<?php

declare(strict_types=1);

namespace App\Application\Engine\Lifecycle;

/**
 * The internal HTTP server the engine exposes to the web side (reload, status) and to the browser (live
 * stream), started last and stopped first.
 */
interface ControlServerInterface
{
    public function start(): void;

    public function stop(): void;
}
