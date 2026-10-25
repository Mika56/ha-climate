<?php

declare(strict_types=1);

namespace App\UI\Http\Api;

use App\Application\Api\IngressUser;
use App\Application\Api\Input\DuplicateScheduleRequest;
use App\Application\Api\Input\ScheduleRequest;
use App\Application\Api\Schedule\ScheduleService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class ScheduleController
{
    public function __construct(private ScheduleService $schedules)
    {
    }

    #[Route('/api/schedules', name: 'api_schedules_list', methods: ['GET'])]
    public function list(): ApiJsonResponse
    {
        return new ApiJsonResponse($this->schedules->list());
    }

    #[Route('/api/schedules', name: 'api_schedules_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] ScheduleRequest $request, ?IngressUser $user): ApiJsonResponse
    {
        return new ApiJsonResponse($this->schedules->create($request, $user), Response::HTTP_CREATED);
    }

    #[Route('/api/schedules/{scheduleId}', name: 'api_schedules_get', methods: ['GET'])]
    public function get(string $scheduleId): ApiJsonResponse
    {
        return new ApiJsonResponse($this->schedules->get($scheduleId));
    }

    #[Route('/api/schedules/{scheduleId}', name: 'api_schedules_update', methods: ['PUT'])]
    public function update(string $scheduleId, #[MapRequestPayload] ScheduleRequest $request, ?IngressUser $user): ApiJsonResponse
    {
        return new ApiJsonResponse($this->schedules->update($scheduleId, $request, $user));
    }

    #[Route('/api/schedules/{scheduleId}', name: 'api_schedules_delete', methods: ['DELETE'])]
    public function delete(string $scheduleId, ?IngressUser $user): Response
    {
        $this->schedules->delete($scheduleId, $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/schedules/{scheduleId}/duplicate', name: 'api_schedules_duplicate', methods: ['POST'])]
    public function duplicate(string $scheduleId, #[MapRequestPayload] DuplicateScheduleRequest $request, ?IngressUser $user): ApiJsonResponse
    {
        return new ApiJsonResponse($this->schedules->duplicate($scheduleId, $request, $user), Response::HTTP_CREATED);
    }
}
