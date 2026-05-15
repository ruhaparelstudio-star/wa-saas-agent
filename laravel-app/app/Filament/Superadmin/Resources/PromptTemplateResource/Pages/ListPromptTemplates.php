<?php

namespace App\Filament\Superadmin\Resources\PromptTemplateResource\Pages;

use App\Filament\Superadmin\Resources\PromptTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromptTemplates extends ListRecords
{
    protected static string $resource = PromptTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
