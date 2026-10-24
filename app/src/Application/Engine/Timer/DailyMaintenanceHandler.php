<?php

declare(strict_types=1);

namespace App\Application\Engine\Timer;

use App\Application\Engine\Audit\AuditLogger;
use App\Application\Engine\Configuration\ConfigurationRepository;
use App\Application\Engine\State\HomeAssistantContext;
use App\Infrastructure\Persistence\Entity\Timer;
use App\Infrastructure\Persistence\Enum\ActorType;
use App\Infrastructure\Persistence\Repository\AuditEntryRepository;
use App\Infrastructure\Persistence\Repository\CommandLogRepository;
use App\Infrastructure\Persistence\Repository\RoomOverrideRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Nightly housekeeping at 03:30 (Home Assistant time): purges old audit entries (retention from the
 * settings), command logs older than a week and overrides that ended; then books the next night.
 */
final readonly class DailyMaintenanceHandler implements TimerHandlerInterface
{
    public const string TIMER_KEY = 'daily_maintenance';
    public const string TIMER_TYPE = 'daily_maintenance';
    private const int COMMAND_LOG_RETENTION_DAYS = 7;
    private const int HOUR = 3;
    private const int MINUTE = 30;

    public function __construct(
        private ConfigurationRepository $configuration,
        private AuditEntryRepository $auditEntries,
        private CommandLogRepository $commandLog,
        private RoomOverrideRepository $overrides,
        private TimerScheduler $timers,
        private AuditLogger $audit,
        private HomeAssistantContext $context,
        private ClockInterface $clock,
    ) {
    }

    public function type(): string
    {
        return self::TIMER_TYPE;
    }

    /**
     * Books the first night when none is booked yet (first start); a restart keeps the booked one.
     */
    public function ensureScheduled(): void
    {
        if (!$this->timers->isScheduled(self::TIMER_KEY)) {
            $this->scheduleNext();
        }
    }

    public function handle(Timer $timer): void
    {
        $now = $this->clock->now();
        $retentionDays = $this->configuration->snapshot()->auditRetentionDays;

        $audit = $this->auditEntries->purgeOlderThan($now->modify(\sprintf('-%d days', $retentionDays)));
        $commands = $this->commandLog->purgeOlderThan($now->modify(\sprintf('-%d days', self::COMMAND_LOG_RETENTION_DAYS)));
        $overrides = $this->overrides->purgeEndedBefore($now);

        $this->audit->log(ActorType::System, 'engine', 'audit.maintenance.done', [
            'audit' => $audit,
            'commands' => $commands,
            'overrides' => $overrides,
        ]);
        $this->scheduleNext();
    }

    private function scheduleNext(): void
    {
        $now = $this->clock->now()->setTimezone($this->context->timeZone());
        $next = $now->setTime(self::HOUR, self::MINUTE);
        if ($next <= $now) {
            $next = $next->modify('+1 day')->setTime(self::HOUR, self::MINUTE);
        }

        $this->timers->schedule(self::TIMER_KEY, $next, self::TIMER_TYPE);
    }
}
