<?php

namespace App\Filament\Tenant\Pages;

use App\Modules\Analytics\Services\AnalyticsService;
use App\Modules\Shared\DTOs\TenantAnalyticsSummaryDTO;
use Filament\Pages\Page;

class AnalyticsPage extends Page
{
    protected static ?string $slug = 'analytics';

    protected static ?string $navigationLabel = 'Analitik';

    protected static ?int $navigationSort = 3;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public function getView(): string
    {
        return 'filament.tenant.pages.analytics';
    }

    public ?string $activePeriod = 'this_month';

    public function getSummary(): TenantAnalyticsSummaryDTO
    {
        $tenantId = auth()->user()->tenant_id;
        $service  = app(AnalyticsService::class);
        $period   = $service->makePeriod($this->activePeriod);

        return $service->getSummary($tenantId, $period);
    }

    public function setPeriod(string $period): void
    {
        $this->activePeriod = $period;
    }
}
