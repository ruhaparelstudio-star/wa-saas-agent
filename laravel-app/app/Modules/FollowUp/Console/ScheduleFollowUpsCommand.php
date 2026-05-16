<?php

namespace App\Modules\FollowUp\Console;

use App\Modules\FollowUp\Jobs\FollowUpJob;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

class ScheduleFollowUpsCommand extends Command
{
    protected $signature   = 'followups:schedule';
    protected $description = 'Dispatch FollowUpJob for every active tenant';

    public function handle(): int
    {
        $tenants = Tenant::where('status', TenantStatus::ACTIVE->value)->get();

        foreach ($tenants as $tenant) {
            FollowUpJob::dispatch($tenant->id);
        }

        $this->info("FollowUpJob dispatched for {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }
}
