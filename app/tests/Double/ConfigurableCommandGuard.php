<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Application\Engine\Command\CommandGuardInterface;
use App\Application\Engine\Command\GuardVerdict;
use App\Application\Engine\Command\PlannedCommand;
use App\Application\Engine\EngineSnapshot;
use App\Domain\Decision\Reason;

/**
 * Guard for the scenario tests: lets everything through until a test sets {@see $deferUntil}.
 */
final class ConfigurableCommandGuard implements CommandGuardInterface
{
    public ?\DateTimeImmutable $deferUntil = null;

    /** @var list<PlannedCommand> */
    public array $checked = [];

    public function check(PlannedCommand $command, EngineSnapshot $snapshot): GuardVerdict
    {
        $this->checked[] = $command;

        return null === $this->deferUntil ? GuardVerdict::allow() : GuardVerdict::deferUntil($this->deferUntil, new Reason('reason.test_guard', ['until' => $this->deferUntil->format('H:i')]));
    }
}
