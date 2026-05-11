<?php

namespace App\Filament\Superadmin\Widgets;

use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TenantStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $total = Tenant::withoutGlobalScopes()->count();
        $active = Tenant::withoutGlobalScopes()->where('status', TenantStatus::ACTIVE->value)->count();
        $trial = Tenant::withoutGlobalScopes()->where('status', TenantStatus::TRIAL->value)->count();
        $suspended = Tenant::withoutGlobalScopes()->where('status', TenantStatus::SUSPENDED->value)->count();

        return [
            Stat::make('Total Tenants', $total)
                ->icon('heroicon-o-building-office-2'),
            Stat::make('Active', $active)
                ->icon('heroicon-o-check-circle')
                ->color('success'),
            Stat::make('Trial', $trial)
                ->icon('heroicon-o-clock')
                ->color('warning'),
            Stat::make('Suspended', $suspended)
                ->icon('heroicon-o-no-symbol')
                ->color('danger'),
        ];
    }
}
