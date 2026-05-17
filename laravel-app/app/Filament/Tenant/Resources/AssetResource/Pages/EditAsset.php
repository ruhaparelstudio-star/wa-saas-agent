<?php

namespace App\Filament\Tenant\Resources\AssetResource\Pages;

use App\Filament\Tenant\Resources\AssetResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Storage;

class EditAsset extends EditRecord
{
    protected static string $resource = AssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (!empty($data['file_path'])) {
            $disk = Storage::disk('public');
            $path = $data['file_path'];

            if ($disk->exists($path)) {
                $data['file_url'] = $disk->url($path);
                $data['mime_type'] = $disk->mimeType($path) ?: 'application/octet-stream';
                $data['file_size_kb'] = (int) round($disk->size($path) / 1024);
            }

            if (empty(trim($data['name'] ?? ''))) {
                $filename = pathinfo($path, PATHINFO_FILENAME);
                $data['name'] = ucwords(str_replace(['-', '_', '.'], ' ', $filename));
            }
        }

        return $data;
    }
}
