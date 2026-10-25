<?php

declare(strict_types=1);

namespace App\UI\Http\Api;

use App\Application\Api\Overview\OverviewService;
use App\Application\Api\Status\StatusService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The overview page: its own data, the status of the rooms (the same payload as the `room` events of
 * `GET /api/live`, which nginx serves straight from the engine) and the active alerts.
 */
#[AsController]
final readonly class OverviewController
{
    public function __construct(
        private OverviewService $overview,
        private StatusService $status,
    ) {
    }

    #[Route('/api/overview', name: 'api_overview', methods: ['GET'])]
    public function overview(Request $request): ApiJsonResponse
    {
        return new ApiJsonResponse($this->overview->get($request->getLocale()));
    }

    #[Route('/api/status', name: 'api_status', methods: ['GET'])]
    public function status(): ApiJsonResponse
    {
        return new ApiJsonResponse($this->status->list());
    }

    #[Route('/api/alerts', name: 'api_alerts', methods: ['GET'])]
    public function alerts(): ApiJsonResponse
    {
        return new ApiJsonResponse($this->overview->activeAlerts());
    }
}
