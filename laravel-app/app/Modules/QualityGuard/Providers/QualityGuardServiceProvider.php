<?php

namespace App\Modules\QualityGuard\Providers;

use App\Modules\QualityGuard\Console\Commands\PatrolConversationQualityCommand;
use App\Modules\QualityGuard\Rules\AvailabilityHallucinationRule;
use App\Modules\QualityGuard\Rules\CustomerNameNotSyncedRule;
use App\Modules\QualityGuard\Rules\HandoffPromiseWithoutRecordRule;
use App\Modules\QualityGuard\Rules\MalformedCustomerPhoneRule;
use App\Modules\QualityGuard\Rules\PriceHallucinationRule;
use App\Modules\QualityGuard\Rules\RedundantPricelistRule;
use App\Modules\QualityGuard\Rules\StageClosedWithoutPaymentRule;
use App\Modules\QualityGuard\Services\ConversationQualityGuard;
use Illuminate\Support\ServiceProvider;

class QualityGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConversationQualityGuard::class, function ($app) {
            return new ConversationQualityGuard([
                $app->make(AvailabilityHallucinationRule::class),
                $app->make(PriceHallucinationRule::class),
                $app->make(StageClosedWithoutPaymentRule::class),
                $app->make(HandoffPromiseWithoutRecordRule::class),
                $app->make(MalformedCustomerPhoneRule::class),
                $app->make(CustomerNameNotSyncedRule::class),
                $app->make(RedundantPricelistRule::class),
            ]);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([PatrolConversationQualityCommand::class]);
        }
    }
}
