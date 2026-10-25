<?php

declare(strict_types=1);

namespace App\UI\Http\Api;

use App\Application\Api\IngressUser;
use App\Application\Api\Input\UserPreferencesRequest;
use App\Application\Api\System\ContextService;
use App\Application\Api\System\PreferencesService;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class SystemController
{
    public function __construct(
        private ContextService $context,
        private PreferencesService $preferences,
    ) {
    }

    #[Route('/api/context', name: 'api_context', methods: ['GET'])]
    public function context(?IngressUser $user): ApiJsonResponse
    {
        return new ApiJsonResponse($this->context->get($user));
    }

    #[Route('/api/me/preferences', name: 'api_update_my_preferences', methods: ['PUT'])]
    public function updatePreferences(#[MapRequestPayload] UserPreferencesRequest $request, ?IngressUser $user): ApiJsonResponse
    {
        return new ApiJsonResponse($this->preferences->update($request, $user));
    }
}
