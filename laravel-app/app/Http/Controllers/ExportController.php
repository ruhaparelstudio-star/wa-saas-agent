<?php

namespace App\Http\Controllers;

use App\Modules\Shared\Services\ExportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    public function __construct(
        private readonly ExportService $exportService,
    ) {}

    public function bookings(Request $request): StreamedResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $filters  = $request->input('filters', []);
        $csv      = $this->exportService->exportBookings($tenantId, $filters);
        $filename = 'bookings-' . Carbon::now()->format('Y-m-d') . '.csv';

        return $this->csvResponse($csv, $filename);
    }

    public function invoices(Request $request): StreamedResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $filters  = $request->input('filters', []);
        $csv      = $this->exportService->exportInvoices($tenantId, $filters);
        $filename = 'invoices-' . Carbon::now()->format('Y-m-d') . '.csv';

        return $this->csvResponse($csv, $filename);
    }

    public function leads(Request $request): StreamedResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $csv      = $this->exportService->exportLeads($tenantId);
        $filename = 'leads-' . Carbon::now()->format('Y-m-d') . '.csv';

        return $this->csvResponse($csv, $filename);
    }

    private function resolveTenantId(Request $request): string
    {
        $user = $request->user();

        return $user?->tenant_id ?? '';
    }

    private function csvResponse(string $csv, string $filename): StreamedResponse
    {
        return response()->streamDownload(
            fn() => print($csv),
            $filename,
            [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }
}
