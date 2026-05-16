<?php

namespace App\Modules\Shared\Storage;

use App\Modules\Shared\Contracts\StorageProviderInterface;

class NullStorageAdapter implements StorageProviderInterface
{
    public function upload(mixed $file, string $path): string
    {
        return 'null/' . basename($path);
    }

    public function getUrl(string $path): string
    {
        return 'https://storage.null/null/' . $path;
    }

    public function delete(string $path): bool
    {
        return true;
    }
}
