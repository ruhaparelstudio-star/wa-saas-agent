<?php

namespace App\Modules\Knowledge\Models;

use App\Modules\Shared\Enums\AssetType;
use App\Modules\Shared\Models\TenantBaseModel;
use Illuminate\Database\Eloquent\Builder;

class Asset extends TenantBaseModel
{
    protected $fillable = [
        'tenant_id',
        'type',
        'name',
        'file_path',
        'file_url',
        'mime_type',
        'file_size_kb',
        'is_active',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => AssetType::class,
            'is_active' => 'boolean',
            'file_size_kb' => 'integer',
        ]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByType(Builder $query, AssetType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    public function getHumanFileSizeAttribute(): string
    {
        $kb = $this->file_size_kb;
        if ($kb < 1024) {
            return $kb . ' KB';
        }
        return number_format($kb / 1024, 1) . ' MB';
    }
}