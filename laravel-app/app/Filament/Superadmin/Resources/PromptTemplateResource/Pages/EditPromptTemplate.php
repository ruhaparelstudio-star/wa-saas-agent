<?php

namespace App\Filament\Superadmin\Resources\PromptTemplateResource\Pages;

use App\Filament\Superadmin\Resources\PromptTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPromptTemplate extends EditRecord
{
    protected static string $resource = PromptTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
