<?php

namespace App\Filament\Superadmin\Resources\DecisionTraceResource\Pages;

use App\Filament\Superadmin\Resources\DecisionTraceResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewDecisionTrace extends ViewRecord
{
    protected static string $resource = DecisionTraceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back to List')
                ->url(DecisionTraceResource::getUrl('index'))
                ->color('gray')
                ->icon('heroicon-o-arrow-left'),
        ];
    }
}
