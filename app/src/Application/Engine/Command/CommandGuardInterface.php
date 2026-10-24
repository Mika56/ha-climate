<?php

declare(strict_types=1);

namespace App\Application\Engine\Command;

use App\Application\Engine\EngineSnapshot;

/**
 * Extension point (service tag `app.command_guard`, applied automatically to implementations): vets every
 * command just before it is sent. Used for the anti-cycling protection.
 *
 * When several guards defer, the latest instant wins. A deferred command is not lost: the engine schedules
 * a re-evaluation of the room at that instant, which plans the command again.
 */
interface CommandGuardInterface
{
    public function check(PlannedCommand $command, EngineSnapshot $snapshot): GuardVerdict;
}
