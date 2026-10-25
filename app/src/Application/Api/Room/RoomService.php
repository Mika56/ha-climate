<?php

declare(strict_types=1);

namespace App\Application\Api\Room;

use App\Application\Api\ConfigurationAudit;
use App\Application\Api\EngineNotifier;
use App\Application\Api\Exception\NotFoundException;
use App\Application\Api\Exception\Violations;
use App\Application\Api\IngressUser;
use App\Application\Api\Input\ReorderRoomsRequest;
use App\Application\Api\Input\RoomRequest;
use App\Infrastructure\Persistence\Entity\Room;
use App\Infrastructure\Persistence\Repository\RoomRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Lists, reads and edits rooms. Every edit is audited and followed by a reload of the engine.
 */
final readonly class RoomService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RoomRepository $rooms,
        private RoomNormalizer $normalizer,
        private RoomChecker $checker,
        private RoomApplier $applier,
        private ConfigurationAudit $audit,
        private EngineNotifier $engine,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return array_map($this->normalizer->normalize(...), $this->rooms->findAllOrdered());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws NotFoundException
     */
    public function get(string $id): array
    {
        return $this->normalizer->normalize($this->find($id));
    }

    /**
     * @return array<string, mixed>
     */
    public function create(RoomRequest $request, ?IngressUser $user): array
    {
        $checked = $this->checker->check($request, null);
        $room = new Room(trim($request->name), $this->nextPosition());
        $this->applier->apply($room, $request, $checked['heating'], $checked['cooling'], $checked['presets']);

        $this->entityManager->persist($room);
        $this->audit->record($user, 'room_created', ['room' => $room->getName()], null, $this->normalizer->configuration($room), $room);
        $this->entityManager->flush();
        $this->engine->configurationChanged();

        return $this->normalizer->normalize($room);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws NotFoundException
     */
    public function update(string $id, RoomRequest $request, ?IngressUser $user): array
    {
        $room = $this->find($id);
        $checked = $this->checker->check($request, $room);
        $before = $this->normalizer->configuration($room);

        $this->applier->apply($room, $request, $checked['heating'], $checked['cooling'], $checked['presets']);
        $this->audit->record($user, 'room_updated', ['room' => $room->getName()], $before, $this->normalizer->configuration($room), $room);
        $this->entityManager->flush();
        $this->engine->configurationChanged();

        return $this->normalizer->normalize($room);
    }

    /**
     * @throws NotFoundException
     */
    public function delete(string $id, ?IngressUser $user): void
    {
        $room = $this->find($id);

        $this->entityManager->wrapInTransaction(function () use ($room, $user): void {
            // Recorded first: the entry keeps the room name once the room is gone.
            $this->audit->record($user, 'room_deleted', ['room' => $room->getName()], $this->normalizer->configuration($room), null, $room);
            $this->entityManager->flush();
            $this->entityManager->remove($room);
            $this->entityManager->flush();
        });
        $this->engine->configurationChanged();
    }

    /**
     * Rooms named in the request come first, in that order; the others keep their relative order after them.
     */
    public function reorder(ReorderRoomsRequest $request, ?IngressUser $user): void
    {
        $existing = [];
        foreach ($this->rooms->findAllOrdered() as $room) {
            $existing[$room->getId()->toRfc4122()] = $room;
        }

        $violations = new Violations($this->translator);
        $ordered = [];
        foreach ($request->roomIds as $index => $roomId) {
            $key = Uuid::fromString($roomId)->toRfc4122();
            if (!isset($existing[$key])) {
                $violations->add(\sprintf('roomIds[%d]', $index), 'api.unknown_room');
            } elseif (isset($ordered[$key])) {
                $violations->add(\sprintf('roomIds[%d]', $index), 'api.duplicate_value');
            } else {
                $ordered[$key] = $existing[$key];
            }
        }
        $violations->throwIfAny();

        $before = array_keys($existing);
        $position = 0;
        foreach ([...$ordered, ...array_diff_key($existing, $ordered)] as $room) {
            $room->moveTo($position++);
        }

        $after = array_keys([...$ordered, ...array_diff_key($existing, $ordered)]);
        $this->audit->record($user, 'rooms_reordered', [], ['roomIds' => $before], ['roomIds' => $after]);
        $this->entityManager->flush();
        $this->engine->configurationChanged();
    }

    private function find(string $id): Room
    {
        $room = Uuid::isValid($id) ? $this->rooms->find(Uuid::fromString($id)) : null;

        return $room ?? throw new NotFoundException('Room', $id);
    }

    private function nextPosition(): int
    {
        $last = $this->rooms->findBy([], ['position' => 'DESC'], 1)[0] ?? null;

        return null === $last ? 0 : $last->getPosition() + 1;
    }
}
