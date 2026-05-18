<?php

namespace App\Filament\Tenant\Widgets;

use App\Modules\Analytics\Services\AnalyticsService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class QualityScoreWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected function getStats(): array
    {
        $tenantId = auth()->user()->tenant_id;
        $service  = app(AnalyticsService::class);
        $period   = $service->makePeriod('last_30_days');
        $m        = $service->getQualityMetrics($tenantId, $period);

        $score = $m['avg_quality_score'] !== null
            ? number_format($m['avg_quality_score'] * 100, 0) . '/100'
            : '—';

        $critColor = $m['critical'] > 0 ? 'danger' : 'success';

        return [
            Stat::make('Avg Quality Score (30d)', $score)
                ->icon('heroicon-o-shield-check')
                ->color($m['avg_quality_score'] !== null && $m['avg_quality_score'] >= 0.8 ? 'success' : 'warning')
                ->description($m['total_turns'] . ' turn dianalisa'),

            Stat::make('Critical Issues', (string) $m['critical'])
                ->icon('heroicon-o-exclamation-triangle')
                ->color($critColor)
                ->description($m['blocked_replies'] . ' reply diblokir'),

            Stat::make('All Violations', (string) $m['total_violations'])
                ->icon('heroicon-o-document-magnifying-glass')
                ->color('gray')
                ->description("HIGH: {$m['high']} • LOW: {$m['low']}"),
        ];
    }
}
