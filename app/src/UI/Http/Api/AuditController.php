<?php

declare(strict_types=1);

namespace App\UI\Http\Api;

use App\Application\Api\Audit\AuditService;
use App\Application\Api\Input\AuditQuery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class AuditController
{
    public function __construct(private AuditService $audit)
    {
    }

    #[Route('/api/audit', name: 'api_audit_search', methods: ['GET'])]
    public function search(Request $request, #[MapQueryString] AuditQuery $query = new AuditQuery()): ApiJsonResponse
    {
        return new ApiJsonResponse($this->audit->search($query, $request->getLocale()));
    }

    #[Route('/api/audit/export', name: 'api_audit_export', methods: ['GET'])]
    public function export(Request $request, #[MapQueryString] AuditQuery $query = new AuditQuery()): StreamedResponse
    {
        $locale = $request->getLocale();
        $response = new StreamedResponse(function () use ($query, $locale): void {
            $output = fopen('php://output', 'wb');
            if (false === $output) {
                throw new \RuntimeException('Cannot write the export.');
            }
            $this->audit->exportCsv($query, $locale, $output);
            fclose($output);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'ha-climate-audit.csv'));

        return $response;
    }
}
