<?php

namespace App\Modules\Shared\Models;

use App\Modules\Shared\Scopes\TenantScope;

abstract class TenantBaseModel extends BaseModel
{
    protected string $tenantColumn = 'tenant_id';

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }
}
