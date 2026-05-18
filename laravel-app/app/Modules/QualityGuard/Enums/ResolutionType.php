<?php

namespace App\Modules\QualityGuard\Enums;

enum ResolutionType: string
{
    case MANUAL = 'manual';
    case AUTO_RULE_PASS = 'auto_rule_pass';
    case AUTO_HANDOFF_RESOLVED = 'auto_handoff_resolved';
    case AUTO_CONVERSATION_CLOSED = 'auto_conversation_closed';
    case AUTO_STALE = 'auto_stale';
}
