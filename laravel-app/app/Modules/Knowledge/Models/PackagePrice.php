<?php

namespace App\Modules\Knowledge\Models;

use App\Modules\Shared\Models\TenantBaseModel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackagePrice extends TenantBaseModel
{
    protected $fillable = [
        'tenant_id',
        'package_id',
        'label',
        'price_idr',
        'valid_from',
        'valid_until',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'price_idr' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'is_active' => 'boolean',
        ]);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function isValidOnDate(Carbon $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->valid_from->gt($date)) {
            return false;
        }

        if ($this->valid_until !== null && $this->valid_until->lt($date)) {
            return false;
        }

        return true;
    }

    public function getFormattedPriceAttribute(): string
    {
        return 'Rp ' . number_format($this->price_idr, 0, ',', '.');
    }
}