<?php

namespace Tests\Feature;

use App\Modules\Analytics\Services\AnalyticsService;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Shared\DTOs\AnalyticsPeriodDTO;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\InvoiceStatus;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\Carbon;
use Database\Seeders\PlanSeeder;
use Database\Seeders\WeddingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->seed(WeddingDemoSeeder::class);
    }

    public function test_seeder_runs_without_exception(): void
    {
        // If we reach here, setUp() completed without exception
        $this->assertTrue(true);
    }

    public function test_two_demo_tenants_exist(): void
    {
        $this->assertGreaterThanOrEqual(2, Tenant::count());

        $this->assertDatabaseHas('tenants', ['slug' => 'capture-moment-photography']);
        $this->assertDatabaseHas('tenants', ['slug' => 'sari-katering-nusantara']);
    }

    public function test_conversations_have_multiple_stages(): void
    {
        $stages = Conversation::pluck('stage')->map(fn ($s) => $s->value ?? $s)->unique()->values()->toArray();

        $this->assertContains(ConversationStage::NEW_LEAD->value, $stages);
        $this->assertContains(ConversationStage::QUALIFICATION->value, $stages);
        $this->assertContains(ConversationStage::INVOICE_PHASE->value, $stages);
    }

    public function test_at_least_10_conversations_exist(): void
    {
        // 10 per tenant × 2 tenants
        $this->assertGreaterThanOrEqual(10, Conversation::count());
    }

    public function test_paid_invoice_exists(): void
    {
        $paid = Invoice::where('status', InvoiceStatus::PAID->value)->count();
        $this->assertGreaterThanOrEqual(1, $paid);
    }

    public function test_analytics_summary_returns_non_empty_revenue(): void
    {
        $tenant = Tenant::where('slug', 'capture-moment-photography')->firstOrFail();

        $period = new AnalyticsPeriodDTO(
            start_date: Carbon::now()->subYear(),
            end_date:   Carbon::now()->addDay(),
            label:      'all_time',
        );

        $summary = app(AnalyticsService::class)->getSummary($tenant->id, $period);

        // Pro plan has ANALYTICS_ADVANCED=true so lead_funnel will be populated
        $this->assertNotEmpty($summary->lead_funnel);
        $this->assertGreaterThan(0, $summary->revenue->total_revenue);
    }

    public function test_analytics_lead_funnel_not_empty(): void
    {
        $tenant = Tenant::where('slug', 'sari-katering-nusantara')->firstOrFail();

        $period = new AnalyticsPeriodDTO(
            start_date: Carbon::now()->subYear(),
            end_date:   Carbon::now()->addDay(),
            label:      'all_time',
        );

        $summary = app(AnalyticsService::class)->getSummary($tenant->id, $period);

        $this->assertNotEmpty($summary->lead_funnel);
    }
}
