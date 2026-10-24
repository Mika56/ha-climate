<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Infrastructure\Persistence\Repository\NotificationTargetRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: NotificationTargetRepository::class)]
#[ORM\Table(name: 'notification_target')]
#[ORM\UniqueConstraint(name: 'uniq_notification_target_service', columns: ['service'])]
final class NotificationTarget
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /**
     * Home Assistant notify service, e.g. "notify.mobile_app_phone".
     */
    #[ORM\Column(type: 'string', length: 255)]
    private string $service;

    #[ORM\Column(type: 'boolean')]
    private bool $enabled;

    public function __construct(string $service, bool $enabled = true)
    {
        if (1 !== preg_match('/^notify\.[a-z0-9_]+$/', $service)) {
            throw new \InvalidArgumentException('A notification target must be a "notify.*" service.');
        }

        $this->id = Uuid::v7();
        $this->service = $service;
        $this->enabled = $enabled;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getService(): string
    {
        return $this->service;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }
}
