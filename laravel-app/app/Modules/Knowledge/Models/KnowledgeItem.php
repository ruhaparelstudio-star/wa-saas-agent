<?php

namespace App\Modules\Knowledge\Models;

use App\Modules\Shared\Models\TenantBaseModel;
use Illuminate\Database\Eloquent\Builder;

class KnowledgeItem extends TenantBaseModel
{
    protected $fillable = [
        'tenant_id',
        'title',
        'content',
        'category',
        'tags',
        'is_active',
        'search_vector',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'tags' => 'array',
            'is_active' => 'boolean',
        ]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
}