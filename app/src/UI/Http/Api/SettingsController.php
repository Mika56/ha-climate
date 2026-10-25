<?php

declare(strict_types=1);

namespace App\UI\Http\Api;

use App\Application\Api\IngressUser;
use App\Application\Api\Input\NotificationSettingsRequest;
use App\Application\Api\Input\PauseRequest;
use App\Application\Api\Input\SeasonRequest;
use App\Application\Api\Input\SettingsRequest;
use App\Application\Api\Settings\NotificationSettingsService;
use App\Application\Api\Settings\SettingsService;
use App\Infrastructure\Engine\EngineClient;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Settings, season, pause, notifications and discovery: the configuration screens that are not about one room.
 */
#[AsController]
final readonly class SettingsController
{
    public function __construct(
        private SettingsService $settings,
        private NotificationSettingsService $notifications,
        private EngineClient $engine,
    ) {
    }

    #[Route('/api/settings', name: 'api_settings_get', methods: ['GET'])]
    public function get(): ApiJsonResponse
    {
        return new ApiJsonResponse($this->settings->get());
    }

    #[Route('/api/settings', name: 'api_settings_update', methods: ['PUT'])]
    public function update(#[MapRequestPayload] SettingsRequest $request, ?IngressUser $user): ApiJsonResponse
    {
        return new ApiJsonResponse($this->settings->update($request, $user));
    }

    #[Route('/api/season', name: 'api_season_update', methods: ['PUT'])]
    public function updateSeason(#[MapRequestPayload] SeasonRequest $request, ?IngressUser $user): ApiJsonResponse
    {
        return new ApiJsonResponse($this->settings->changeSeason($request, $user));
    }

    #[Route('/api/pause', name: 'api_pause_update', methods: ['PUT'])]
    public function updatePause(#[MapRequestPayload] PauseRequest $request, ?IngressUser $user): ApiJsonResponse
    {
        return new ApiJsonResponse($this->settings->changePause($request, $user));
    }

    #[Route('/api/notifications', name: 'api_notifications_get', methods: ['GET'])]
    public function notifications(): ApiJsonResponse
    {
        return new ApiJsonResponse($this->notifications->get());
    }

    #[Route('/api/notifications', name: 'api_notifications_update', methods: ['PUT'])]
    public function updateNotifications(#[MapRequestPayload] NotificationSettingsRequest $request, ?IngressUser $user): ApiJsonResponse
    {
        return new ApiJsonResponse($this->notifications->update($request, $user));
    }

    #[Route('/api/notifications/test', name: 'api_notifications_test', methods: ['POST'])]
    public function sendTestNotification(): Response
    {
        $this->engine->sendTestNotification();

        return new Response(null, Response::HTTP_ACCEPTED);
    }

    #[Route('/api/discovery', name: 'api_discovery', methods: ['GET'])]
    public function discovery(): ApiJsonResponse
    {
        return new ApiJsonResponse($this->engine->discovery());
    }
}
