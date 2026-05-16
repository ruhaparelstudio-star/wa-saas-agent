<?php

namespace App\Filament\Tenant\Widgets;

use App\Modules\Invoice\Models\Invoice;
use App\Modules\Shared\Enums\InvoiceStatus;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OverdueInvoicesWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $tenantId = auth()->user()->tenant_id;

        $overdue = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [InvoiceStatus::SENT->value, InvoiceStatus::OVERDUE->value])
            ->where('due_date', '<', Carbon::today()->toDateString())
            ->whereNull('paid_at')
            ->count();

        $pending = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [InvoiceStatus::ISSUED->value, InvoiceStatus::SENT->value])
            ->whereNull('paid_at')
            ->count();

        return [
            Stat::make('Invoice Overdue', $overdue)
                ->icon('heroicon-o-exclamation-triangle')
                ->color($overdue > 0 ? 'danger' : 'success')
                ->description($overdue > 0 ? 'Perlu tindak lanjut' : 'Semua lancar'),
            Stat::make('Invoice Belum Lunas', $pending)
                ->icon('heroicon-o-document-currency-dollar')
                ->color('warning'),
        ];
    }
}
