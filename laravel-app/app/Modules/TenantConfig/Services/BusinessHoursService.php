<?php

namespace App\Modules\TenantConfig\Services;

use App\Modules\Shared\Enums\PolicyKey;
use App\Modules\TenantConfig\Models\TenantSetting;
use Carbon\Carbon;

class BusinessHoursService
{
    public function __construct(
        private readonly TenantPolicyService $policyService,
    ) {}

    public function isOpen(string $tenantId, ?Carbon $datetime = null): bool
    {
        $datetime ??= Carbon::now('UTC');

        $setting = $this->getSetting($tenantId);

        $timezone = $setting?->timezone ?? 'Asia/Jakarta';
        $hoursStart = $setting?->business_hours_start ?? '08:00';
        $hoursEnd   = $setting?->business_hours_end ?? '21:00';

        // Convert to tenant local time
        $local = $datetime->copy()->setTimezone($timezone);

        // Check business day
        if ($setting !== null) {
            if (!$setting->isBusinessDay($local)) {
                return false;
            }
        } else {
            // Default: Mon-Sat (1-6)
            $dayOfWeek = $local->dayOfWeek === 0 ? 7 : $local->dayOfWeek;
            if (!in_array($dayOfWeek, [1, 2, 3, 4, 5, 6])) {
                return false;
            }
        }

        // Check time window
        $currentTime = $local->format('H:i');

        return $currentTime >= $hoursStart && $currentTime <= $hoursEnd;
    }

    public function getNextOpenTime(string $tenantId, ?Carbon $from = null): Carbon
    {
        $from ??= Carbon::now('UTC');

        $setting = $this->getSetting($tenantId);
        $timezone = $setting?->timezone ?? 'Asia/Jakarta';
        $hoursStart = $setting?->business_hours_start ?? '08:00';
        $businessDays = $setting?->business_days ?? [1, 2, 3, 4, 5, 6];

        $local = $from->copy()->setTimezone($timezone);

        // If currently open, return the current day's open time
        if ($this->isOpen($tenantId, $from)) {
            [$hour, $minute] = explode(':', $hoursStart);
            return $local->copy()->setTime((int) $hour, (int) $minute, 0)->setTimezone('UTC');
        }

        // Find the next open day
        $candidate = $local->copy();
        $maxDays = 14;

        for ($i = 0; $i < $maxDays; $i++) {
            $candidate->addDay()->startOfDay();

            $dayOfWeek = $candidate->dayOfWeek === 0 ? 7 : $candidate->dayOfWeek;

            if (in_array($dayOfWeek, $businessDays)) {
                [$hour, $minute] = explode(':', $hoursStart);
                return $candidate->setTime((int) $hour, (int) $minute, 0)->setTimezone('UTC');
            }
        }

        // Fallback: tomorrow at open time
        [$hour, $minute] = explode(':', $hoursStart);
        return $local->copy()->addDay()->setTime((int) $hour, (int) $minute, 0)->setTimezone('UTC');
    }

    public function getAfterHoursBehavior(string $tenantId): string
    {
        return $this->policyService->getPolicy($tenantId, PolicyKey::AFTER_HOURS_BEHAVIOR);
    }

    private function getSetting(string $tenantId): ?TenantSetting
    {
        return TenantSetting::where('tenant_id', $tenantId)->first();
    }
}
