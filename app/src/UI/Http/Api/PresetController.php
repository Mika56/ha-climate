<?php

declare(strict_types=1);

namespace App\UI\Http\Api;

use App\Application\Api\IngressUser;
use App\Application\Api\Input\PresetRequest;
use App\Application\Api\Preset\PresetService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class PresetController
{
    public function __construct(private PresetService $presets)
    {
    }

    #[Route('/api/presets', name: 'api_presets_list', methods: ['GET'])]
    public function list(): ApiJsonResponse
    {
        return new ApiJsonResponse($this->presets->list());
    }

    #[Route('/api/presets', name: 'api_presets_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] PresetRequest $request, ?IngressUser $user): ApiJsonResponse
    {
        return new ApiJsonResponse($this->presets->create($request, $user), Response::HTTP_CREATED);
    }

    #[Route('/api/presets/{presetKey}', name: 'api_presets_update', methods: ['PUT'])]
    public function update(string $presetKey, #[MapRequestPayload] PresetRequest $request, ?IngressUser $user): ApiJsonResponse
    {
        return new ApiJsonResponse($this->presets->update($presetKey, $request, $user));
    }

    #[Route('/api/presets/{presetKey}', name: 'api_presets_delete', methods: ['DELETE'])]
    public function delete(string $presetKey, ?IngressUser $user): Response
    {
        $this->presets->delete($presetKey, $user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
