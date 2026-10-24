<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use App\Domain\Climate\HvacMode;
use App\Infrastructure\Persistence\Repository\CommandLogRepository;
use App\Infrastructure\Persistence\Support\Guard;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A service call sent by the engine; its context id lets the engine tell
 * its own commands from manual changes made on the device.
 */
#[ORM\Entity(repositoryClass: CommandLogRepository::class)]
#[ORM\Table(name: 'command_log')]
#[ORM\Index(name: 'idx_command_log_context', columns: ['ha_context_id'])]
#[ORM\Index(name: 'idx_command_log_device_sent', columns: ['device_entity_id', 'sent_at'])]
final class CommandLog
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $deviceEntityId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $sentAt;

    #[ORM\Column(type: 'string', length: 128)]
    private string $service;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $data;

    #[ORM\Column(type: 'string', length: 64)]
    private string $haContextId;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $targetTemperature;

    #[ORM\Column(type: 'string', length: 8, nullable: true, enumType: HvacMode::class)]
    private ?HvacMode $hvacMode;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        string $deviceEntityId,
        \DateTimeImmutable $sentAt,
        string $service,
        string $haContextId,
        array $data = [],
        ?float $targetTemperature = null,
        ?HvacMode $hvacMode = null,
    ) {
        $this->id = Uuid::v7();
        $this->deviceEntityId = Guard::notBlank($deviceEntityId, 'deviceEntityId');
        $this->sentAt = $sentAt;
        $this->service = Guard::notBlank($service, 'service');
        $this->haContextId = Guard::notBlank($haContextId, 'haContextId');
        $this->data = $data;
        $this->targetTemperature = $targetTemperature;
        $this->hvacMode = $hvacMode;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDeviceEntityId(): string
    {
        return $this->deviceEntityId;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getService(): string
    {
        return $this->service;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getHaContextId(): string
    {
        return $this->haContextId;
    }

    public function getTargetTemperature(): ?float
    {
        return $this->targetTemperature;
    }

    public function getHvacMode(): ?HvacMode
    {
        return $this->hvacMode;
    }
}
