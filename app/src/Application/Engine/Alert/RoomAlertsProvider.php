<?php

declare(strict_types=1);

namespace App\Application\Engine\Alert;

use App\Domain\Room\RoomId;
use App\Infrastructure\Persistence\Repository\AlertRepository;

/**
 * The active alerts of one room, as shown in its status.
 */
final readonly class RoomAlertsProvider
{
    public function __construct(private AlertRepository $alerts)
    {
    }

    /**
     * @return list<AlertView> oldest first
     */
    public function forRoom(RoomId $room, \DateTimeZone $timeZone): array
    {
        $views = [];
        foreach ($this->alerts->findActive() as $alert) {
            if ($alert->getRoom()?->getId()->toRfc4122() === (string) $room) {
                $views[] = AlertView::fromEntity($alert, $timeZone);
            }
        }

        return $views;
    }
}
