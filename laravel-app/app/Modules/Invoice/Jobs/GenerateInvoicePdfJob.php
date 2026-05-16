<?php

namespace App\Modules\Invoice\Jobs;

use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Services\InvoicePdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateInvoicePdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
    ) {}

    public function handle(InvoicePdfService $pdfService): void
    {
        try {
            $pdfService->generate($this->invoice->fresh());
        } catch (\Throwable $e) {
            Log::error('GenerateInvoicePdfJob failed', [
                'invoice_id' => $this->invoice->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
