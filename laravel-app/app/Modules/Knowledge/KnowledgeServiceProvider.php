<?php

namespace App\Modules\Knowledge;

use App\Modules\Knowledge\Services\KnowledgeRetrieverService;
use App\Modules\Shared\Contracts\KnowledgeRetrieverInterface;
use Illuminate\Support\ServiceProvider;

class KnowledgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(KnowledgeRetrieverInterface::class, KnowledgeRetrieverService::class);
    }

    public function boot(): void
    {
        //
    }
}
