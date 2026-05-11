<?php

namespace Database\Seeders;

use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanFeature;
use App\Modules\Shared\Enums\FeatureKey;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'starter',
                'name' => 'Starter',
                'description' => 'Paket dasar untuk vendor wedding yang baru memulai.',
                'is_active' => true,
                'sort_order' => 1,
                'features' => [
                    FeatureKey::MAX_WA_AGENTS->value         => '1',
                    FeatureKey::MONTHLY_LEAD_LIMIT->value     => '100',
                    FeatureKey::GOOGLE_CALENDAR_ENABLED->value => 'false',
                    FeatureKey::FOLLOW_UP_AUTOMATION->value   => 'false',
                    FeatureKey::ANALYTICS_ADVANCED->value     => 'false',
                    FeatureKey::MULTI_CHANNEL->value          => 'false',
                ],
            ],
            [
                'code' => 'growth',
                'name' => 'Growth',
                'description' => 'Paket untuk vendor yang ingin scale dengan otomasi lebih.',
                'is_active' => true,
                'sort_order' => 2,
                'features' => [
                    FeatureKey::MAX_WA_AGENTS->value         => '2',
                    FeatureKey::MONTHLY_LEAD_LIMIT->value     => '500',
                    FeatureKey::GOOGLE_CALENDAR_ENABLED->value => 'true',
                    FeatureKey::FOLLOW_UP_AUTOMATION->value   => 'true',
                    FeatureKey::ANALYTICS_ADVANCED->value     => 'false',
                    FeatureKey::MULTI_CHANNEL->value          => 'false',
                ],
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'description' => 'Paket lengkap dengan semua fitur premium.',
                'is_active' => true,
                'sort_order' => 3,
                'features' => [
                    FeatureKey::MAX_WA_AGENTS->value         => '5',
                    FeatureKey::MONTHLY_LEAD_LIMIT->value     => '-1',
                    FeatureKey::GOOGLE_CALENDAR_ENABLED->value => 'true',
                    FeatureKey::FOLLOW_UP_AUTOMATION->value   => 'true',
                    FeatureKey::ANALYTICS_ADVANCED->value     => 'true',
                    FeatureKey::MULTI_CHANNEL->value          => 'false',
                ],
            ],
        ];

        foreach ($plans as $planData) {
            $features = $planData['features'];
            unset($planData['features']);

            $plan = Plan::updateOrCreate(
                ['code' => $planData['code']],
                array_merge(['id' => Str::uuid()->toString()], $planData)
            );

            foreach ($features as $featureKey => $featureValue) {
                PlanFeature::updateOrCreate(
                    ['plan_id' => $plan->id, 'feature_key' => $featureKey],
                    ['id' => Str::uuid()->toString(), 'feature_value' => $featureValue]
                );
            }
        }
    }
}
