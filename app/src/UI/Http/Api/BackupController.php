<?php

declare(strict_types=1);

namespace App\UI\Http\Api;

use App\Application\Api\Backup\BackupExporter;
use App\Application\Api\Backup\BackupRestorer;
use App\Application\Api\IngressUser;
use App\Application\Api\Input\BackupRequest;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class BackupController
{
    public function __construct(
        private BackupExporter $exporter,
        private BackupRestorer $restorer,
    ) {
    }

    #[Route('/api/backup', name: 'api_backup_export', methods: ['GET'])]
    public function export(): ApiJsonResponse
    {
        $response = new ApiJsonResponse($this->exporter->export());
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'ha-climate-backup.json'));

        return $response;
    }

    #[Route('/api/backup/restore', name: 'api_backup_restore', methods: ['POST'])]
    public function restore(#[MapRequestPayload] BackupRequest $backup, ?IngressUser $user): ApiJsonResponse
    {
        return new ApiJsonResponse($this->restorer->restore($backup, $user));
    }
}
