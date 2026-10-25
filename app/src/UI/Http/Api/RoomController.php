<?php

declare(strict_types=1);

namespace App\UI\Http\Api;

use App\Application\Api\IngressUser;
use App\Application\Api\Input\BoostRequest;
use App\Application\Api\Input\ReorderRoomsRequest;
use App\Application\Api\Input\RoomRequest;
use App\Application\Api\Room\RoomService;
use App\Application\Api\Status\StatusService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class RoomController
{
    public function __construct(
        private RoomService $rooms,
        private StatusService $status,
    ) {
    }

    #[Route('/api/rooms', name: 'api_rooms_list', methods: ['GET'])]
    public function list(): ApiJsonResponse
    {
        return new ApiJsonResponse($this->rooms->list());
    }

    #[Route('/api/rooms', name: 'api_rooms_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] RoomRequest $request, ?IngressUser $user): ApiJsonResponse
    {
        return new ApiJsonResponse($this->rooms->create($request, $user), Response::HTTP_CREATED);
    }

    // Declared before the routes with a {roomId}, which would otherwise swallow "order".
    #[Route('/api/rooms/order', name: 'api_rooms_reorder', methods: ['PUT'])]
    public function reorder(#[MapRequestPayload] ReorderRoomsRequest $request, ?IngressUser $user): Response
    {
        $this->rooms->reorder($request, $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/rooms/{roomId}', name: 'api_rooms_get', methods: ['GET'])]
    public function get(string $roomId): ApiJsonResponse
    {
        return new ApiJsonResponse($this->rooms->get($roomId));
    }

    #[Route('/api/rooms/{roomId}', name: 'api_rooms_update', methods: ['PUT'])]
    public function update(string $roomId, #[MapRequestPayload] RoomRequest $request, ?IngressUser $user): ApiJsonResponse
    {
        return new ApiJsonResponse($this->rooms->update($roomId, $request, $user));
    }

    #[Route('/api/rooms/{roomId}', name: 'api_rooms_delete', methods: ['DELETE'])]
    public function delete(string $roomId, ?IngressUser $user): Response
    {
        $this->rooms->delete($roomId, $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/rooms/{roomId}/boost', name: 'api_rooms_boost', methods: ['POST'])]
    public function boost(string $roomId, #[MapRequestPayload] BoostRequest $request, ?IngressUser $user): ApiJsonResponse
    {
        return new ApiJsonResponse($this->status->boost($roomId, $request, $user));
    }

    #[Route('/api/rooms/{roomId}/override', name: 'api_rooms_cancel_override', methods: ['DELETE'])]
    public function cancelOverride(string $roomId, ?IngressUser $user): ApiJsonResponse
    {
        return new ApiJsonResponse($this->status->cancelOverride($roomId, $user));
    }
}
