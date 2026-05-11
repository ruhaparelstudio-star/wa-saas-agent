<?php

namespace App\Modules\Shared\Enums;

enum FeatureKey: string
{
    case MAX_WA_AGENTS = 'max_wa_agents';
    case MONTHLY_LEAD_LIMIT = 'monthly_lead_limit';
    case GOOGLE_CALENDAR_ENABLED = 'google_calendar_enabled';
    case FOLLOW_UP_AUTOMATION = 'follow_up_automation';
    case ANALYTICS_ADVANCED = 'analytics_advanced';
    case MULTI_CHANNEL = 'multi_channel';

    public function label(): string
    {
        return match($this) {
            self::MAX_WA_AGENTS => 'Max WA Agents',
            self::MONTHLY_LEAD_LIMIT => 'Monthly Lead Limit',
            self::GOOGLE_CALENDAR_ENABLED => 'Google Calendar',
            self::FOLLOW_UP_AUTOMATION => 'Follow-up Automation',
            self::ANALYTICS_ADVANCED => 'Advanced Analytics',
            self::MULTI_CHANNEL => 'Multi Channel',
        };
    }
}
