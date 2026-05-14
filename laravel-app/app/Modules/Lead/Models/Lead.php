<?php

namespace App\Modules\Lead\Models;

use App\Modules\Conversation\Models\Conversation;
use App\Modules\Shared\DTOs\LeadProfileDTO;
use App\Modules\Shared\Enums\LeadTemperature;
use App\Modules\Shared\Models\TenantBaseModel;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends TenantBaseModel
{
    protected $table = 'leads';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'customer_name',
        'customer_phone',
        'event_date',
        'event_type',
        'location',
        'guest_count',
        'budget_min',
        'budget_max',
        'package_interest',
        'package_slug',
        'lead_score',
        'temperature',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'event_date'  => 'date',
            'budget_min'  => 'integer',
            'budget_max'  => 'integer',
            'guest_count' => 'integer',
            'lead_score'  => 'integer',
            'temperature' => LeadTemperature::class,
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function updateFromEntities(array $entities): void
    {
        $fieldMap = [
            'customer_name'    => 'customer_name',
            'event_date'       => 'event_date',
            'event_type'       => 'event_type',
            'location'         => 'location',
            'guest_count'      => 'guest_count',
            'budget_min'       => 'budget_min',
            'budget_max'       => 'budget_max',
            'package_interest' => 'package_interest',
            'package_slug'     => 'package_slug',
        ];

        $updates = [];
        foreach ($fieldMap as $entityKey => $modelField) {
            if (isset($entities[$entityKey]) && $entities[$entityKey] !== null) {
                $updates[$modelField] = $entities[$entityKey];
            }
        }

        if (!empty($updates)) {
            $this->fill($updates);
            $this->lead_score = $this->calculateLeadScore();
            $this->save();
        }
    }

    private function calculateLeadScore(): int
    {
        $score = 0;

        if (!empty($this->customer_name)) $score += 20;
        if (!empty($this->event_date))    $score += 20;
        if (!empty($this->budget_min) || !empty($this->budget_max)) $score += 20;
        if (!empty($this->package_slug))  $score += 20;
        if (!empty($this->guest_count))   $score += 20;

        return $score;
    }

    public function toLeadProfileDTO(): LeadProfileDTO
    {
        return LeadProfileDTO::from([
            'id'                 => $this->id,
            'tenant_id'          => $this->tenant_id,
            'phone'              => $this->customer_phone,
            'name'               => $this->customer_name,
            'temperature'        => $this->temperature->value,
            'entities'           => [
                'customer_name'    => $this->customer_name,
                'event_date'       => $this->event_date?->toDateString(),
                'event_type'       => $this->event_type,
                'location'         => $this->location,
                'guest_count'      => $this->guest_count,
                'budget_min'       => $this->budget_min,
                'budget_max'       => $this->budget_max,
                'package_interest' => $this->package_interest,
                'package_slug'     => $this->package_slug,
            ],
            'conversation_count' => 1,
            'last_seen_at'       => $this->updated_at?->toIso8601String(),
        ]);
    }
}
