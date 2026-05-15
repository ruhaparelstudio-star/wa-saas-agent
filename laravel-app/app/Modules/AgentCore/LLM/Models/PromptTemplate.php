<?php

namespace App\Modules\AgentCore\LLM\Models;

use App\Modules\Shared\Models\BaseModel;

class PromptTemplate extends BaseModel
{
    protected $fillable = [
        'id',
        'name',
        'version',
        'template',
        'is_active',
        'accuracy_history',
        'notes',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_active'        => 'boolean',
            'accuracy_history' => 'array',
        ]);
    }
}
