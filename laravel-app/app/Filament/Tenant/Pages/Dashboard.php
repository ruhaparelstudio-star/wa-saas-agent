<?php

namespace App\Filament\Tenant\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home';

    public function getTitle(): string
    {
        $user = auth()->user();
        return 'Selamat datang, ' . ($user?->name ?? 'Tenant') . '!';
    }

    public function getWidgets(): array
    {
        return [
            \App\Filament\Tenant\Widgets\TenantOverviewWidget::class,
            \App\Filament\Tenant\Widgets\OverdueInvoicesWidget::class,
            \App\Filament\Tenant\Widgets\UpcomingBookingsWidget::class,
        ];
    }

    public function getHeaderWidgets(): array
    {
        return [];
    }
}
