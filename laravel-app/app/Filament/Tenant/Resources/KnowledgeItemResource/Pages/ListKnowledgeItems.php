<?php

namespace App\Filament\Tenant\Resources\KnowledgeItemResource\Pages;

use App\Filament\Tenant\Resources\KnowledgeItemResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListKnowledgeItems extends ListRecords
{
    protected static string $resource = KnowledgeItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
