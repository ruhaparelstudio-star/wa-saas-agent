<?php

namespace App\Filament\Tenant\Widgets;

use App\Modules\Analytics\Services\AnalyticsService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RevenueStatWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $tenantId = auth()->user()->tenant_id;
        $service  = app(AnalyticsService::class);
        $period   = $service->makePeriod('this_month');
        $revenue  = $service->getRevenue($tenantId, $period);

        return [
            Stat::make('Revenue Bulan Ini', 'Rp ' . number_format($revenue->total_revenue, 0, ',', '.'))
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->description($revenue->paid_count . ' invoice lunas dari ' . $revenue->invoice_count),

            Stat::make('Pendapatan DP', 'Rp ' . number_format($revenue->dp_revenue, 0, ',', '.'))
                ->icon('heroicon-o-arrow-trending-up')
                ->color('info'),

            Stat::make('Pendapatan Pelunasan', 'Rp ' . number_format($revenue->pelunasan_revenue, 0, ',', '.'))
                ->icon('heroicon-o-check-circle')
                ->color('warning'),
        ];
    }
}
