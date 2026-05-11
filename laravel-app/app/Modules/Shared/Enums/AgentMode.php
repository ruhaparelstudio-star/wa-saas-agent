<?php

namespace App\Modules\Shared\Enums;

enum AgentMode: string
{
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case HANDOFF = 'handoff';
    case LIMITED = 'limited';

    public function label(): string
    {
        return match($this) {
            self::ACTIVE => 'Active',
            self::PAUSED => 'Paused',
            self::HANDOFF => 'Handoff',
            self::LIMITED => 'Limited',
        };
    }
}
