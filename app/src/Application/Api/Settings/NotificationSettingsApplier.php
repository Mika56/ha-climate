<?php

declare(strict_types=1);

namespace App\Application\Api\Settings;

use App\Application\Api\Input\NotificationSettingsRequest;
use App\Infrastructure\Persistence\Entity\NotificationTarget;
use App\Infrastructure\Persistence\Entity\NotificationTypeSetting;
use App\Infrastructure\Persistence\Repository\NotificationTargetRepository;
use App\Infrastructure\Persistence\Repository\NotificationTypeSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Makes the notification destinations and type settings match a validated request: the destinations of the
 * request replace the stored ones, the types it lists are updated and the others are left alone.
 */
final readonly class NotificationSettingsApplier
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationTargetRepository $targets,
        private NotificationTypeSettingRepository $types,
    ) {
    }

    public function apply(NotificationSettingsRequest $request): void
    {
        $wanted = [];
        foreach ($request->targets as $target) {
            $wanted[$target->service] = $target->enabled;
        }

        foreach ($this->targets->findAll() as $stored) {
            if (!isset($wanted[$stored->getService()])) {
                $this->entityManager->remove($stored);

                continue;
            }

            $wanted[$stored->getService()] ? $stored->enable() : $stored->disable();
            unset($wanted[$stored->getService()]);
        }
        foreach ($wanted as $service => $enabled) {
            $this->entityManager->persist(new NotificationTarget($service, $enabled));
        }

        foreach ($request->types as $type) {
            $setting = $this->types->findOneByType($type->type);
            if (null === $setting) {
                $setting = new NotificationTypeSetting($type->type);
                $this->entityManager->persist($setting);
            }
            $setting->configure($type->enabled, $type->delaySeconds, $type->persistentNotification);
        }
    }
}
