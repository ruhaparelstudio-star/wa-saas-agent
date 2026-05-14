<?php

namespace App\Filament\Tenant\Resources\KnowledgeItemResource\Pages;

use App\Filament\Tenant\Resources\KnowledgeItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKnowledgeItem extends CreateRecord
{
    protected static string $resource = KnowledgeItemResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = auth()->user()->tenant_id;
        return $data;
    }
}
