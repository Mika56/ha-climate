<?php

declare(strict_types=1);

namespace App\Application\Api\Preset;

use App\Application\Api\ConfigurationAudit;
use App\Application\Api\EngineNotifier;
use App\Application\Api\Exception\ConflictException;
use App\Application\Api\Exception\NotFoundException;
use App\Application\Api\IngressUser;
use App\Application\Api\Input\PresetRequest;
use App\Infrastructure\Persistence\Entity\Preset;
use App\Infrastructure\Persistence\Repository\PresetRepository;
use App\Infrastructure\Persistence\Repository\ScheduleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Presets: label, colour and position are editable, the key is not. A preset the schedules use, or one of the
 * two the engine's policies rely on (`eco` for a vacant room, `away` for an empty house), cannot be deleted.
 */
final readonly class PresetService
{
    /** @var list<string> */
    public const array SYSTEM_KEYS = ['eco', 'away'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PresetRepository $presets,
        private ScheduleRepository $schedules,
        private ConfigurationAudit $audit,
        private EngineNotifier $engine,
    ) {
    }

    /**
     * @return list<array{key: string, label: string, color: string, position: int}>
     */
    public function list(): array
    {
        return array_map(self::normalize(...), $this->presets->findAllOrdered());
    }

    /**
     * @return array{key: string, label: string, color: string, position: int}
     *
     * @throws ConflictException
     */
    public function create(PresetRequest $request, ?IngressUser $user): array
    {
        if (null !== $this->presets->findOneByKey($request->key)) {
            throw new ConflictException('preset_exists', \sprintf('The preset "%s" already exists.', $request->key));
        }

        $preset = new Preset($request->key, trim($request->label), $request->color, $request->position);
        $this->entityManager->persist($preset);
        $this->audit->record($user, 'preset_created', ['preset' => $preset->getKey()], null, self::normalize($preset));
        $this->entityManager->flush();
        $this->engine->configurationChanged();

        return self::normalize($preset);
    }

    /**
     * @return array{key: string, label: string, color: string, position: int}
     *
     * @throws NotFoundException
     */
    public function update(string $key, PresetRequest $request, ?IngressUser $user): array
    {
        $preset = $this->find($key);
        $before = self::normalize($preset);

        $preset->describe(trim($request->label), $request->color);
        $preset->moveTo($request->position);
        $this->audit->record($user, 'preset_updated', ['preset' => $key], $before, self::normalize($preset));
        $this->entityManager->flush();
        $this->engine->configurationChanged();

        return self::normalize($preset);
    }

    /**
     * @throws NotFoundException
     * @throws ConflictException
     */
    public function delete(string $key, ?IngressUser $user): void
    {
        $preset = $this->find($key);
        if (\in_array($key, self::SYSTEM_KEYS, true)) {
            throw new ConflictException('preset_in_use', \sprintf('The preset "%s" is used by the vacancy and absence policies.', $key));
        }
        $slots = $this->schedules->countSlotsUsing($preset);
        if ($slots > 0) {
            throw new ConflictException('preset_in_use', \sprintf('The preset "%s" is used by %d schedule slot(s).', $key, $slots));
        }

        $this->audit->record($user, 'preset_deleted', ['preset' => $key], self::normalize($preset), null);
        $this->entityManager->remove($preset);
        $this->entityManager->flush();
        $this->engine->configurationChanged();
    }

    private function find(string $key): Preset
    {
        return $this->presets->findOneByKey($key) ?? throw new NotFoundException('Preset', $key);
    }

    /**
     * @return array{key: string, label: string, color: string, position: int}
     */
    private static function normalize(Preset $preset): array
    {
        return ['key' => $preset->getKey(), 'label' => $preset->getLabel(), 'color' => $preset->getColor(), 'position' => $preset->getPosition()];
    }
}
