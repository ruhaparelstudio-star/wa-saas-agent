<?php

namespace App\Modules\Knowledge\Models;

use App\Modules\Shared\Models\TenantBaseModel;
use Illuminate\Database\Eloquent\Builder;

class Faq extends TenantBaseModel
{
    protected $fillable = [
        'tenant_id',
        'question',
        'answer',
        'category',
        'is_active',
        'sort_order',
        'search_vector',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
}