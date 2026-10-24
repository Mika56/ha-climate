<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Seed;

use App\Infrastructure\Persistence\Entity\GlobalSettings;
use App\Infrastructure\Persistence\Entity\NotificationTypeSetting;
use App\Infrastructure\Persistence\Entity\Preset;
use App\Infrastructure\Persistence\Enum\NotificationType;
use App\Infrastructure\Persistence\Repository\GlobalSettingsRepository;
use App\Infrastructure\Persistence\Repository\NotificationTypeSettingRepository;
use App\Infrastructure\Persistence\Repository\PresetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Inserts the default rows (settings, presets, notification types) that are
 * missing. Repeatable: existing rows are never modified, so user changes survive.
 */
#[Autoconfigure(public: true)]
final readonly class DefaultDataSeeder
{
    /**
     * @var list<array{key: string, label: string, color: string}>
     */
    private const array PRESETS = [
        ['key' => 'comfort', 'label' => 'Comfort', 'color' => '#ff9800'],
        ['key' => 'eco', 'label' => 'Eco', 'color' => '#4caf50'],
        ['key' => 'night', 'label' => 'Night', 'color' => '#3f51b5'],
        ['key' => 'away', 'label' => 'Away', 'color' => '#9e9e9e'],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private GlobalSettingsRepository $settings,
        private PresetRepository $presets,
        private NotificationTypeSettingRepository $notificationTypes,
    ) {
    }

    /**
     * @return int the number of rows created by this run
     */
    public function seed(): int
    {
        $created = 0;

        if (null === $this->settings->find(GlobalSettings::SINGLETON_ID)) {
            $this->settings->get();
            ++$created;
        }

        foreach (self::PRESETS as $position => $definition) {
            if (null === $this->presets->findOneByKey($definition['key'])) {
                $this->entityManager->persist(new Preset($definition['key'], $definition['label'], $definition['color'], $position));
                ++$created;
            }
        }

        foreach ($this->notificationDefaults() as $default) {
            if (null === $this->notificationTypes->findOneByType($default->getType())) {
                $this->entityManager->persist($default);
                ++$created;
            }
        }

        $this->entityManager->flush();

        return $created;
    }

    /**
     * @return list<NotificationTypeSetting>
     */
    private function notificationDefaults(): array
    {
        return [
            new NotificationTypeSetting(NotificationType::WindowOpen, true, 1800, false),
            new NotificationTypeSetting(NotificationType::SetpointNotApplicable, true, null, false),
            new NotificationTypeSetting(NotificationType::DeviceUnresponsive, true, 300, true),
            new NotificationTypeSetting(NotificationType::SensorUnavailable, true, 600, true),
        ];
    }
}
