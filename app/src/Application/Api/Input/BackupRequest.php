<?php

declare(strict_types=1);

namespace App\Application\Api\Input;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A configuration backup (`Backup` of the contract).
 */
final readonly class BackupRequest
{
    public const string FORMAT = 'ha-climate-backup';
    public const int VERSION = 2;

    /** Version 1 had `off` among the seasons and no `paused`; it is still read. */
    public const array READABLE_VERSIONS = [1, 2];

    /**
     * @param list<PresetRequest>   $presets
     * @param list<ScheduleRequest> $schedules
     * @param list<RoomRequest>     $rooms
     */
    public function __construct(
        #[Assert\IdenticalTo(self::FORMAT)]
        public string $format,
        #[Assert\Choice(choices: self::READABLE_VERSIONS)]
        public int $version,
        #[Assert\Valid]
        public BackupSettingsRequest $settings,
        #[Assert\Valid]
        public array $presets,
        #[Assert\Valid]
        public array $schedules,
        #[Assert\Valid]
        public array $rooms,
        #[Assert\Valid]
        public NotificationSettingsRequest $notifications,
        public ?string $exportedAt = null,
    ) {
    }
}
