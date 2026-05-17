<?php

namespace App\Filament\Superadmin\Pages;

use App\Modules\Analytics\Services\AnalyticsService;
use App\Modules\Shared\DTOs\AnalyticsPeriodDTO;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Filament\Pages\Page;

class CrossTenantAnalyticsPage extends Page
{
    protected static ?string $slug = 'analytics';

    protected static ?string $navigationLabel = 'Cross-Tenant Analytics';

    protected static ?int $navigationSort = 10;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    public static function getNavigationGroup(): ?string
    {
        return 'Reports';
    }

    public function getView(): string
    {
        return 'filament.superadmin.pages.cross-tenant-analytics';
    }

    public ?string $activePeriod = 'this_month';

    public function getRows(): array
    {
        $service = app(AnalyticsService::class);
        $period  = $service->makePeriod($this->activePeriod);

        return Tenant::where('status', TenantStatus::ACTIVE->value)
            ->get()
            ->map(function (Tenant $tenant) use ($service, $period) {
                $summary = $service->getSummary($tenant->id, $period);

                return [
                    'tenant_name'     => $tenant->name,
                    'active_leads'    => $summary->conversion->total_leads,
                    'booking_count'   => $summary->conversion->leads_to_booking,
                    'revenue'         => $summary->revenue->total_revenue,
                    'conversion_rate' => $summary->conversion->conversion_rate,
                ];
            })
            ->sortByDesc('revenue')
            ->values()
            ->toArray();
    }

    public function setPeriod(string $period): void
    {
        $this->activePeriod = $period;
    }
}
