<?php

declare(strict_types=1);

namespace App\Application\Api\Settings;

use App\Application\Api\ConfigurationAudit;
use App\Application\Api\EngineNotifier;
use App\Application\Api\IngressUser;
use App\Application\Api\Input\NotificationSettingsRequest;
use App\Infrastructure\Engine\EngineClient;
use App\Infrastructure\Engine\EngineUnavailableException;
use App\Infrastructure\Persistence\Entity\NotificationTarget;
use App\Infrastructure\Persistence\Entity\NotificationTypeSetting;
use App\Infrastructure\Persistence\Enum\NotificationType;
use App\Infrastructure\Persistence\Repository\NotificationTargetRepository;
use App\Infrastructure\Persistence\Repository\NotificationTypeSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Where notifications go (the `notify.*` services of Home Assistant) and which kinds are sent.
 */
final readonly class NotificationSettingsService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationTargetRepository $targets,
        private NotificationTypeSettingRepository $types,
        private NotificationSettingsApplier $applier,
        private EngineClient $engineClient,
        private ConfigurationAudit $audit,
        private EngineNotifier $engine,
    ) {
    }

    /**
     * The destinations are every `notify.*` service Home Assistant offers (enabled or not) plus the stored ones
     * it no longer offers (`available: false`). While the engine cannot be asked, only the stored ones are
     * listed, all considered available.
     *
     * @return array{targets: list<array<string, mixed>>, types: list<array<string, mixed>>}
     */
    public function get(): array
    {
        return ['targets' => $this->targets(), 'types' => $this->types()];
    }

    /**
     * @return array{targets: list<array<string, mixed>>, types: list<array<string, mixed>>}
     */
    public function update(NotificationSettingsRequest $request, ?IngressUser $user): array
    {
        $before = $this->stored();
        $this->applier->apply($request);
        $this->entityManager->flush();

        $this->audit->record($user, 'notifications_updated', [], $before, $this->stored());
        $this->entityManager->flush();
        $this->engine->configurationChanged();

        return $this->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function targets(): array
    {
        $stored = [];
        foreach ($this->targets->findAll() as $target) {
            $stored[$target->getService()] = $target->isEnabled();
        }

        $offered = [];
        $discovered = true;
        try {
            $services = $this->engineClient->discovery()['notifyServices'] ?? [];
            foreach (\is_array($services) ? $services : [] as $service) {
                if (\is_array($service) && \is_string($service['service'] ?? null)) {
                    $offered[$service['service']] = \is_string($service['name'] ?? null) ? $service['name'] : $service['service'];
                }
            }
        } catch (EngineUnavailableException) {
            $discovered = false;
        }

        $targets = [];
        foreach ([...array_keys($stored), ...array_keys($offered)] as $service) {
            $targets[$service] = [
                'service' => $service,
                'enabled' => $stored[$service] ?? false,
                'name' => $offered[$service] ?? null,
                'available' => !$discovered || isset($offered[$service]),
            ];
        }
        ksort($targets);

        return array_values($targets);
    }

    /**
     * @return list<array{type: string, enabled: bool, delaySeconds: int|null, persistentNotification: bool}>
     */
    private function types(): array
    {
        $types = [];
        foreach (NotificationType::cases() as $type) {
            $setting = $this->types->findOneByType($type) ?? new NotificationTypeSetting($type);
            $types[] = [
                'type' => $type->value,
                'enabled' => $setting->isEnabled(),
                'delaySeconds' => $setting->getDelaySeconds(),
                'persistentNotification' => $setting->isPersistentNotification(),
            ];
        }

        return $types;
    }

    /**
     * What is stored, without asking the engine: what an audit entry or a backup keeps.
     *
     * @return array{targets: list<array{service: string, enabled: bool}>, types: list<array<string, mixed>>}
     */
    public function stored(): array
    {
        $targets = array_map(
            static fn (NotificationTarget $target): array => ['service' => $target->getService(), 'enabled' => $target->isEnabled()],
            $this->targets->findBy([], ['service' => 'ASC']),
        );

        return ['targets' => $targets, 'types' => $this->types()];
    }
}
