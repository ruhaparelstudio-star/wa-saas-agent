<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Services\InvoiceService;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanFeature;
use App\Modules\Plans\Models\TenantSubscription;
use App\Modules\Shared\DTOs\FollowUpCandidateDTO;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\FeatureKey;
use App\Modules\Shared\Enums\InvoiceStatus;
use App\Modules\Shared\Enums\InvoiceType;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\FollowUp\Services\FollowUpService;
use App\Modules\WhatsApp\Models\WaAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiChannelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant  $tenant;
    private Booking $booking;
    private Invoice $invoice;
    private WaAccount $waAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $superadmin = User::create([
            'name'      => 'Super',
            'email'     => 'super-mc-' . Str::random(6) . '@test.com',
            'password'  => bcrypt('pw'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        $this->tenant = Tenant::create([
            'name'          => 'MC Vendor',
            'slug'          => 'mc-v-' . Str::random(4),
            'status'        => TenantStatus::ACTIVE->value,
            'industry'      => 'wedding',
            'contact_email' => 'mc@vendor.com',
            'created_by_id' => $superadmin->id,
        ]);

        $this->waAccount = WaAccount::create([
            'tenant_id' => $this->tenant->id,
            'status'    => WaAccountStatus::CONNECTED->value,
            'metadata'  => [],
        ]);
        $this->waAccount->markConnected('+6285500000001');

        $package = Package::create([
            'tenant_id'   => $this->tenant->id,
            'name'        => 'Paket',
            'slug'        => 'paket-mc',
            'price'       => 5000000,
            'description' => 'd',
            'is_active'   => true,
        ]);

        $conversation = Conversation::create([
            'tenant_id'      => $this->tenant->id,
            'customer_phone' => '+6281234567890',
            'customer_email' => 'customer@test.com',
            'channel'        => 'whatsapp',
            'stage'          => 'booking',
            'agent_mode'     => 'active',
            'memory_mode'    => 'active',
            'temperature'    => 'warm',
        ]);

        $this->booking = Booking::create([
            'tenant_id'       => $this->tenant->id,
            'conversation_id' => $conversation->id,
            'package_id'      => $package->id,
            'booking_code'    => 'BKG-MC-001',
            'customer_name'   => 'Ani',
            'customer_phone'  => '+6281234567890',
            'event_date'      => '2026-08-10',
            'event_type'      => 'resepsi',
            'location'        => 'Jakarta',
            'status'          => BookingStatus::CONFIRMED->value,
            'total_amount'    => 5000000,
            'dp_amount'       => 1500000,
        ]);

        $this->invoice = Invoice::create([
            'tenant_id'      => $this->tenant->id,
            'booking_id'     => $this->booking->id,
            'invoice_number' => 'INV-MC-001',
            'type'           => InvoiceType::DP->value,
            'status'         => InvoiceStatus::ISSUED->value,
            'amount'         => 1500000,
            'due_date'       => now()->addDays(7),
            'sent_count'     => 0,
            'pdf_url'        => 'https://storage.null/null/invoices/mc-001.pdf',
        ]);
    }

    private function enableMultiChannel(): void
    {
        $suffix = Str::random(4);
        $plan = Plan::create([
            'code'  => 'MULTI-' . $suffix,
            'name'  => 'Multi-' . $suffix,
            'slug'  => 'multi-' . $suffix,
            'price' => 0,
        ]);

        PlanFeature::create([
            'plan_id'       => $plan->id,
            'feature_key'   => FeatureKey::MULTI_CHANNEL->value,
            'feature_value' => '1',
        ]);

        TenantSubscription::create([
            'tenant_id'            => $this->tenant->id,
            'plan_id'              => $plan->id,
            'status'               => 'active',
            'starts_at'            => now()->subDay(),
            'ends_at'              => now()->addMonth(),
            'current_period_start' => now()->subDay(),
            'current_period_end'   => now()->addMonth(),
        ]);
    }

    public function test_invoice_send_whatsapp_channel_calls_wa_gateway(): void
    {
        Http::fake([
            '*dispatch*' => Http::response(['success' => true, 'provider_message_id' => 'wa-msg-1'], 200),
        ]);

        $service = app(InvoiceService::class);
        $result  = $service->send($this->invoice->fresh());

        $this->assertTrue($result);
        Http::assertSent(fn($req) => str_contains($req->url(), 'dispatch'));
    }

    public function test_invoice_send_email_channel_calls_resend_when_feature_enabled(): void
    {
        $this->enableMultiChannel();

        $this->booking->conversation()->update(['channel' => 'email']);

        Http::fake([
            'api.resend.com/*' => Http::response(['id' => 'resend-msg-1'], 200),
        ]);

        $service = app(InvoiceService::class);
        $result  = $service->send($this->invoice->fresh());

        $this->assertTrue($result);
        Http::assertSent(fn($req) => str_contains($req->url(), 'resend.com'));
    }

    public function test_invoice_send_email_channel_falls_back_to_wa_when_feature_disabled(): void
    {
        $this->booking->conversation()->update(['channel' => 'email']);

        Http::fake([
            '*dispatch*' => Http::response(['success' => true, 'provider_message_id' => 'wa-msg-fallback'], 200),
        ]);

        $service = app(InvoiceService::class);
        $result  = $service->send($this->invoice->fresh());

        $this->assertTrue($result);
        Http::assertSent(fn($req) => str_contains($req->url(), 'dispatch'));
    }

    public function test_follow_up_email_channel_calls_resend_when_feature_enabled(): void
    {
        $this->enableMultiChannel();

        Http::fake([
            'api.resend.com/*' => Http::response(['id' => 'fu-resend-1'], 200),
        ]);

        $candidate = new FollowUpCandidateDTO(
            reason:          'stale_lead',
            tenant_id:       $this->tenant->id,
            conversation_id: $this->booking->conversation_id,
            booking_id:      null,
            invoice_id:      null,
            to_phone:        '+6281234567890',
            wa_account_id:   $this->waAccount->id,
            context_data:    [],
            channel:         'email',
            to_email:        'customer@test.com',
        );

        $service = app(FollowUpService::class);
        $result  = $service->sendFollowUp($candidate);

        $this->assertTrue($result);
        Http::assertSent(fn($req) => str_contains($req->url(), 'resend.com'));
    }
}
