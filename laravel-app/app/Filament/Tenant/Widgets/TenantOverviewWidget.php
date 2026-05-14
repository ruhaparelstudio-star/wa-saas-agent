<?php

namespace App\Filament\Tenant\Widgets;

use App\Modules\Knowledge\Models\Faq;
use App\Modules\Knowledge\Models\KnowledgeItem;
use App\Modules\Knowledge\Models\Package;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TenantOverviewWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $tenantId = auth()->user()->tenant_id;

        return [
            Stat::make('Paket', Package::where('tenant_id', $tenantId)->count())
                ->icon('heroicon-o-gift')
                ->color('primary'),
            Stat::make('FAQ', Faq::where('tenant_id', $tenantId)->count())
                ->icon('heroicon-o-question-mark-circle')
                ->color('info'),
            Stat::make('Pengetahuan', KnowledgeItem::where('tenant_id', $tenantId)->count())
                ->icon('heroicon-o-document-text')
                ->color('success'),
        ];
    }
}
