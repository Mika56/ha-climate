<?php

declare(strict_types=1);

namespace App\Application\Engine\Command;

use App\Application\Engine\Audit\AuditLogger;
use App\Infrastructure\Persistence\Enum\ActorType;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Records every manual change in the audit trail. It runs before (default priority 0) the subscriber that
 * turns the change into a manual override (see ManualOverrideSubscriber, priority -10).
 */
#[AsEventListener]
final readonly class ManualChangeAuditSubscriber
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function __invoke(ManualDeviceChangeDetected $event): void
    {
        $this->audit->log(
            null === $event->userId ? ActorType::Device : ActorType::User,
            'manual_change',
            'audit.manual_change.detected',
            [
                'device' => (string) $event->device,
                'oldMode' => $event->oldMode ?? 'unknown',
                'newMode' => $event->newMode,
                'oldTemperature' => self::temperature($event->oldTemperature),
                'newTemperature' => self::temperature($event->newTemperature),
            ],
            $event->roomId,
            [],
            $event->userId ?? (string) $event->device,
        );
    }

    private static function temperature(?float $celsius): string
    {
        return null === $celsius ? '–' : (string) $celsius.' °C';
    }
}
