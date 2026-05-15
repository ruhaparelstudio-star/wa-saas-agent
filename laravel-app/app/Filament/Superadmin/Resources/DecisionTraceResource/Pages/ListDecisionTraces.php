<?php

namespace App\Filament\Superadmin\Resources\DecisionTraceResource\Pages;

use App\Filament\Superadmin\Resources\DecisionTraceResource;
use Filament\Resources\Pages\ListRecords;

class ListDecisionTraces extends ListRecords
{
    protected static string $resource = DecisionTraceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
