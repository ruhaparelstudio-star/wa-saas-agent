<?php

namespace App\Modules\Invoice\Services;

use App\Modules\Invoice\Models\Invoice;
use App\Modules\Shared\Contracts\StorageProviderInterface;
use App\Modules\TenantConfig\Models\TenantSetting;
use App\Modules\Tenancy\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

class InvoicePdfService
{
    public function __construct(
        private readonly StorageProviderInterface $storage,
    ) {}

    public function generate(Invoice $invoice): string
    {
        $invoice->loadMissing(['booking.package', 'booking.conversation']);

        $booking    = $invoice->booking;
        $tenantId   = $invoice->tenant_id;
        $tenant     = Tenant::find($tenantId);
        $setting    = TenantSetting::where('tenant_id', $tenantId)->first();

        $data = [
            'invoice'         => $invoice,
            'tenantName'      => $tenant?->name ?? 'Wedding Vendor',
            'invoiceType'     => $invoice->type ?? 'dp',
            'customerName'    => $booking?->customer_name ?? '-',
            'customerPhone'   => $this->maskPhone($booking?->customer_phone ?? ''),
            'eventDate'       => $booking?->event_date
                ? \Carbon\Carbon::parse($booking->event_date)->format('d M Y')
                : '-',
            'eventType'       => $booking?->event_type ?? '-',
            'location'        => $booking?->location ?? null,
            'packageName'     => $booking?->package?->name ?? null,
            'bankName'        => $setting?->bank_name ?? null,
            'bankAccount'     => $setting?->bank_account_number ?? null,
            'bankAccountName' => $setting?->bank_account_name ?? null,
        ];

        $pdf  = Pdf::loadView('pdf.invoice', $data)->setPaper('a4');
        $path = 'invoices/' . $tenantId . '/' . $invoice->invoice_number . '.pdf';

        $this->storage->upload($pdf->output(), $path);
        $pdfUrl = $this->storage->getUrl($path);

        $invoice->update(['pdf_url' => $pdfUrl]);

        return $pdfUrl;
    }

    public function regenerate(Invoice $invoice): string
    {
        if ($invoice->pdf_url) {
            $parsed = parse_url($invoice->pdf_url, PHP_URL_PATH);
            if ($parsed) {
                try {
                    $this->storage->delete(ltrim($parsed, '/'));
                } catch (\Throwable $e) {
                    Log::warning('InvoicePdfService: could not delete old PDF', ['error' => $e->getMessage()]);
                }
            }
        }

        return $this->generate($invoice);
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 7) {
            return $phone;
        }

        return substr($phone, 0, 3) . str_repeat('*', strlen($phone) - 6) . substr($phone, -3);
    }
}
