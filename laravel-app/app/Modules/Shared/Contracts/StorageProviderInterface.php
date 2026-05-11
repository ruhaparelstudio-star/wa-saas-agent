<?php

namespace App\Modules\Shared\Contracts;

interface StorageProviderInterface
{
    /** Upload a file and return its storage path. */
    public function upload(mixed $file, string $path): string;

    /** Get the public URL for a stored file. */
    public function getUrl(string $path): string;

    /** Delete a stored file. */
    public function delete(string $path): bool;
}
