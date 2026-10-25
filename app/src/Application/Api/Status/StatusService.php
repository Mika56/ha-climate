<?php

declare(strict_types=1);

namespace App\Application\Api\Status;

use App\Application\Api\Exception\NotFoundException;
use App\Application\Api\IngressUser;
use App\Application\Api\Input\BoostRequest;
use App\Infrastructure\Engine\EngineClient;
use App\Infrastructure\Engine\EngineRejectedException;
use App\Infrastructure\Engine\EngineUnavailableException;
use App\Infrastructure\Persistence\Repository\RoomRepository;
use Symfony\Component\Uid\Uuid;

/**
 * What the engine decides and does right now: the status of the rooms, boosts and their cancellation. The
 * engine is the only decider; the web side checks the room exists and passes the Home Assistant user as actor.
 */
final readonly class StatusService
{
    public const string ANONYMOUS_ACTOR = 'api';

    public function __construct(
        private EngineClient $engine,
        private RoomRepository $rooms,
    ) {
    }

    /**
     * The status of every room, in the order of the rooms.
     *
     * @return list<array<string, mixed>>
     *
     * @throws EngineUnavailableException
     */
    public function list(): array
    {
        $positions = [];
        foreach ($this->rooms->findAllOrdered() as $position => $room) {
            $positions[$room->getId()->toRfc4122()] = $position;
        }

        $statuses = array_map(self::normalize(...), $this->engine->status());
        $position = static fn (array $status): int => \is_string($status['roomId'] ?? null) ? ($positions[$status['roomId']] ?? \PHP_INT_MAX) : \PHP_INT_MAX;
        usort($statuses, static fn (array $a, array $b): int => $position($a) <=> $position($b));

        return $statuses;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws NotFoundException
     * @throws EngineUnavailableException
     * @throws EngineRejectedException
     */
    public function boost(string $roomId, BoostRequest $request, ?IngressUser $user): array
    {
        $this->assertRoomExists($roomId);

        return self::normalize($this->engine->boost($roomId, $request->delta, $request->durationMinutes, $request->untilNextSlot, self::actor($user)));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws NotFoundException
     * @throws EngineUnavailableException
     * @throws EngineRejectedException
     */
    public function cancelOverride(string $roomId, ?IngressUser $user): array
    {
        $this->assertRoomExists($roomId);

        return self::normalize($this->engine->cancelOverride($roomId, self::actor($user)));
    }

    /**
     * The status as the engine sends it, with the decision parameters as a JSON object even when empty (a
     * decoded `{}` is an empty list in PHP, which would be encoded as `[]`).
     *
     * @param array<string, mixed> $status
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $status): array
    {
        $decision = $status['decision'] ?? null;
        if (\is_array($decision) && \is_array($decision['reason'] ?? null) && [] === ($decision['reason']['params'] ?? null)) {
            $decision['reason']['params'] = new \stdClass();
            $status['decision'] = $decision;
        }

        return $status;
    }

    private function assertRoomExists(string $roomId): void
    {
        if (!Uuid::isValid($roomId) || null === $this->rooms->find(Uuid::fromString($roomId))) {
            throw new NotFoundException('Room', $roomId);
        }
    }

    private static function actor(?IngressUser $user): string
    {
        return $user->displayName ?? self::ANONYMOUS_ACTOR;
    }
}
