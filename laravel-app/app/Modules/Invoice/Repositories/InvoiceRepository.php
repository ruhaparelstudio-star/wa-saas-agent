<?php

namespace App\Modules\Invoice\Repositories;

use App\Modules\Invoice\Models\Invoice;
use App\Modules\Shared\Enums\InvoiceStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class InvoiceRepository
{
    public function findById(string $id): ?Invoice
    {
        return Invoice::find($id);
    }

    public function findByNumber(string $invoiceNumber): ?Invoice
    {
        return Invoice::withoutGlobalScopes()
            ->where('invoice_number', $invoiceNumber)
            ->first();
    }

    public function findByBooking(string $bookingId): Collection
    {
        return Invoice::withoutGlobalScopes()
            ->where('booking_id', $bookingId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function findOverdueByTenant(string $tenantId): Collection
    {
        return Invoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [InvoiceStatus::SENT->value, InvoiceStatus::OVERDUE->value])
            ->where('due_date', '<', Carbon::now()->toDateString())
            ->whereNull('paid_at')
            ->get();
    }

    public function countByStatus(string $tenantId, InvoiceStatus $status): int
    {
        return Invoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', $status->value)
            ->count();
    }

    public function create(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $data['invoice_number'] = Invoice::generateInvoiceNumber();
            return Invoice::create($data);
        });
    }
}
