<?php

namespace App\Modules\Storage\Providers;

use App\Modules\Shared\Contracts\StorageProviderInterface;
use App\Modules\Shared\Storage\NullStorageAdapter;
use App\Modules\Storage\Adapters\R2StorageAdapter;
use Illuminate\Support\ServiceProvider;

class StorageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StorageProviderInterface::class, function () {
            if (! empty(env('R2_ACCESS_KEY_ID'))) {
                return new R2StorageAdapter();
            }

            return new NullStorageAdapter();
        });
    }
}
