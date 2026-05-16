<?php

namespace App\Modules\Storage\Adapters;

use App\Modules\Shared\Contracts\StorageProviderInterface;
use Illuminate\Support\Facades\Storage;

class R2StorageAdapter implements StorageProviderInterface
{
    public function upload(mixed $file, string $path): string
    {
        Storage::disk('r2')->put($path, $file, 'public');

        return $path;
    }

    public function getUrl(string $path): string
    {
        $publicUrl = config('filesystems.disks.r2.url');

        if ($publicUrl) {
            return rtrim($publicUrl, '/') . '/' . ltrim($path, '/');
        }

        return Storage::disk('r2')->url($path);
    }

    public function delete(string $path): bool
    {
        return Storage::disk('r2')->delete($path);
    }
}
