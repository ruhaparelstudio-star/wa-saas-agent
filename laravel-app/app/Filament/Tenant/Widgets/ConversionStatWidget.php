<?php

namespace App\Filament\Tenant\Widgets;

use App\Modules\Analytics\Services\AnalyticsService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ConversionStatWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $tenantId   = auth()->user()->tenant_id;
        $service    = app(AnalyticsService::class);
        $period     = $service->makePeriod('this_month');
        $conversion = $service->getConversion($tenantId, $period);

        $avgDays = $conversion->avg_days_to_booking > 0
            ? number_format($conversion->avg_days_to_booking, 1) . ' hari'
            : '-';

        return [
            Stat::make('Conversion Rate', number_format($conversion->conversion_rate, 1) . '%')
                ->icon('heroicon-o-arrow-trending-up')
                ->color('success')
                ->description($conversion->leads_to_booking . ' dari ' . $conversion->total_leads . ' lead'),

            Stat::make('Rata-rata ke Booking', $avgDays)
                ->icon('heroicon-o-clock')
                ->color('info'),
        ];
    }
}
