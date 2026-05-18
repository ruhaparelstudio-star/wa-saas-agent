<?php

namespace App\Modules\TenantConfig\Models;

use App\Modules\Shared\Models\TenantBaseModel;
use Illuminate\Database\Eloquent\Builder;

class TenantBankAccount extends TenantBaseModel
{
    protected $table = 'tenant_bank_accounts';

    protected $fillable = [
        'tenant_id',
        'bank_name',
        'account_number',
        'account_holder',
        'is_default',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_default' => 'boolean',
            'is_active'  => 'boolean',
            'sort_order' => 'integer',
        ]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_default')->orderBy('sort_order');
    }
}
