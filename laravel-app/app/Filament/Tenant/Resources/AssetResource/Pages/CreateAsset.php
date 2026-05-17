<?php

namespace App\Filament\Tenant\Resources\AssetResource\Pages;

use App\Filament\Tenant\Resources\AssetResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateAsset extends CreateRecord
{
    protected static string $resource = AssetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = auth()->user()->tenant_id;

        if (!empty($data['file_path'])) {
            $disk = Storage::disk('public');
            $path = $data['file_path'];

            $data['file_url'] = $disk->url($path);
            $data['mime_type'] = $disk->mimeType($path) ?: 'application/octet-stream';
            $data['file_size_kb'] = (int) round($disk->size($path) / 1024);

            if (empty(trim($data['name'] ?? ''))) {
                $filename = pathinfo($path, PATHINFO_FILENAME);
                $data['name'] = ucwords(str_replace(['-', '_', '.'], ' ', $filename));
            }
        }

        return $data;
    }
}
