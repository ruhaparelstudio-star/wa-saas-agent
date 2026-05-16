<?php

namespace App\Modules\Invoice\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Repositories\BookingRepository;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Repositories\InvoiceRepository;
use App\Modules\Invoice\Services\InvoiceService;
use App\Modules\Notification\Models\AdminNotification;
use App\Modules\Shared\Contracts\ChannelGatewayInterface;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\InvoiceStatus;
use App\Modules\Shared\Enums\InvoiceType;
use App\Modules\Shared\Enums\MemoryMode;
use App\Modules\Shared\Enums\PolicyKey;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\TenantConfig\Services\TenantPolicyService;
use App\Modules\WhatsApp\Adapters\WhatsAppGatewayAdapter;
use App\Modules\WhatsApp\Models\WaAccount;
use App\Modules\WhatsApp\Repositories\WaAccountRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceService $service;
    private InvoiceRepository $repo;
    private TenantPolicyService $policyService;
    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $adminA;
    private WaAccount $waAccount;
    private Conversation $conversation;
    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-16 10:00:00', 'UTC'));
        config(['services.wa_gateway.url' => 'http://wa-gateway-test:3001']);

        Http::fake([
            '*/dispatch' => Http::response(['success' => true, 'provider_message_id' => 'msg-test'], 200),
        ]);

        $superadmin        = $this->makeSuperadmin();
        $this->tenantA     = $this->makeTenant('Vendor A', $superadmin);
        $this->tenantB     = $this->makeTenant('Vendor B', $superadmin);
        $this->adminA      = $this->makeTenantAdmin($this->tenantA);
        $this->waAccount   = $this->makeWaAccount($this->tenantA->id);
        $this->conversation = $this->makeConversation($this->tenantA->id, $this->waAccount->id);
        $this->booking     = $this->makeBooking($this->tenantA->id, $this->conversation->id);

        $this->policyService = app(TenantPolicyService::class);
        $this->repo          = app(InvoiceRepository::class);
        $this->service       = new InvoiceService(
            $this->repo,
            $this->policyService,
            new WhatsAppGatewayAdapter(),
            app(WaAccountRepository::class),
            app(ConversationRepository::class),
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // --- issue ---

    public function test_issue_creates_invoice_issued_and_updates_booking_status(): void
    {
        $invoice = $this->service->issue(
            $this->booking,
            InvoiceType::DP,
            5_000_000,
            Carbon::now()->addDays(7)
        );

        $this->assertNotNull($invoice->id);
        $this->assertSame(InvoiceStatus::ISSUED, $invoice->status);
        $this->assertSame($this->booking->id, $invoice->booking_id);
        $this->assertEquals(5_000_000, $invoice->amount);
        $this->assertMatchesRegularExpression('/^INV-\d{6}-\d{4}$/', $invoice->invoice_number);

        $this->booking->refresh();
        $this->assertSame(BookingStatus::AWAITING_DP, $this->booking->status);
    }

    public function test_issue_updates_conversation_stage_to_invoice_phase(): void
    {
        $this->service->issue(
            $this->booking,
            InvoiceType::DP,
            3_000_000,
            Carbon::now()->addDays(5)
        );

        $this->conversation->refresh();
        $this->assertSame(ConversationStage::INVOICE_PHASE, $this->conversation->stage);
    }

    public function test_issue_sends_admin_notification(): void
    {
        $this->service->issue(
            $this->booking,
            InvoiceType::DP,
            5_000_000,
            Carbon::now()->addDays(7)
        );

        $this->assertDatabaseHas('admin_notifications', [
            'tenant_id' => $this->tenantA->id,
            'user_id'   => $this->adminA->id,
            'type'      => 'invoice_action',
        ]);
    }

    // --- send ---

    public function test_send_marks_invoice_sent_and_increments_count(): void
    {
        $invoice = $this->makeIssuedInvoice();

        $result = $this->service->send($invoice);

        $this->assertTrue($result);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::SENT, $invoice->status);
        $this->assertEquals(1, $invoice->sent_count);
        $this->assertNotNull($invoice->sent_at);
    }

    public function test_send_calls_gateway_dispatch(): void
    {
        $invoice = $this->makeIssuedInvoice();

        $this->service->send($invoice);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/dispatch');
        });
    }

    public function test_send_updates_conversation_stage_to_post_invoice_limited(): void
    {
        $invoice = $this->makeIssuedInvoice();

        $this->service->send($invoice);

        $this->conversation->refresh();
        $this->assertSame(ConversationStage::POST_INVOICE_LIMITED, $this->conversation->stage);
    }

    public function test_send_twice_within_max_resend_succeeds(): void
    {
        $this->policyService->setPolicy($this->tenantA->id, PolicyKey::INVOICE_MAX_RESEND, '2');

        $invoice = $this->makeIssuedInvoice();

        $result1 = $this->service->send($invoice);
        $invoice->refresh();
        // Reset status to SENT so canResend still counts correctly
        $result2 = $this->service->send($invoice);

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $invoice->refresh();
        $this->assertEquals(2, $invoice->sent_count);
    }

    public function test_send_blocked_when_resend_limit_exceeded(): void
    {
        $this->policyService->setPolicy($this->tenantA->id, PolicyKey::INVOICE_MAX_RESEND, '2');

        $invoice = $this->makeIssuedInvoice();

        $this->service->send($invoice);
        $invoice->refresh();
        $this->service->send($invoice);
        $invoice->refresh();

        // Third send should be blocked
        $result = $this->service->send($invoice);

        $this->assertFalse($result);
        $invoice->refresh();
        $this->assertEquals(2, $invoice->sent_count);
    }

    // --- markPaid ---

    public function test_mark_paid_sets_invoice_paid_and_booking_paid(): void
    {
        $invoice = $this->makeIssuedInvoice();

        $this->service->markPaid($invoice, 'https://proof.example.com/receipt.jpg');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PAID, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame('https://proof.example.com/receipt.jpg', $invoice->payment_proof_url);

        $this->booking->refresh();
        $this->assertSame(BookingStatus::PAID, $this->booking->status);
    }

    public function test_mark_paid_restores_conversation_stage_to_booking(): void
    {
        $invoice = $this->makeIssuedInvoice();

        $this->service->markPaid($invoice);

        $this->conversation->refresh();
        $this->assertSame(ConversationStage::BOOKING, $this->conversation->stage);
    }

    // --- getOverdueByTenant ---

    public function test_get_overdue_marks_sent_past_due_invoices_as_overdue(): void
    {
        $pastDue = Carbon::now()->subDays(3)->toDateString();

        $invoice = Invoice::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $this->tenantA->id,
            'booking_id'     => $this->booking->id,
            'invoice_number' => 'INV-TEST-0001',
            'type'           => InvoiceType::DP->value,
            'status'         => InvoiceStatus::SENT->value,
            'amount'         => 2_000_000,
            'due_date'       => $pastDue,
            'sent_count'     => 1,
        ]);

        $result = $this->service->getOverdueByTenant($this->tenantA->id);

        $this->assertCount(1, $result);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::OVERDUE, $invoice->status);
    }

    public function test_get_overdue_ignores_future_invoices(): void
    {
        Invoice::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $this->tenantA->id,
            'booking_id'     => $this->booking->id,
            'invoice_number' => 'INV-TEST-0002',
            'type'           => InvoiceType::DP->value,
            'status'         => InvoiceStatus::SENT->value,
            'amount'         => 2_000_000,
            'due_date'       => Carbon::now()->addDays(5)->toDateString(),
            'sent_count'     => 1,
        ]);

        $result = $this->service->getOverdueByTenant($this->tenantA->id);

        $this->assertCount(0, $result);
    }

    // --- tenant isolation ---

    public function test_tenant_isolation_invoice_not_visible_across_tenants(): void
    {
        $bookingB = $this->makeBookingForTenant($this->tenantB->id);

        Invoice::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $this->tenantB->id,
            'booking_id'     => $bookingB->id,
            'invoice_number' => 'INV-TENANTB-0001',
            'type'           => InvoiceType::DP->value,
            'status'         => InvoiceStatus::SENT->value,
            'amount'         => 1_000_000,
            'due_date'       => Carbon::now()->subDay()->toDateString(),
            'sent_count'     => 1,
        ]);

        // Overdue scan for tenant A should not include tenant B invoices
        $overdue = $this->service->getOverdueByTenant($this->tenantA->id);
        $this->assertCount(0, $overdue);

        // Direct query via repo for tenant A should be empty
        $countA = $this->repo->countByStatus($this->tenantA->id, InvoiceStatus::SENT);
        $this->assertEquals(0, $countA);
    }

    // --- helpers ---

    private function makeIssuedInvoice(): Invoice
    {
        return $this->repo->create([
            'tenant_id'  => $this->tenantA->id,
            'booking_id' => $this->booking->id,
            'type'       => InvoiceType::DP->value,
            'status'     => InvoiceStatus::ISSUED->value,
            'amount'     => 5_000_000,
            'due_date'   => Carbon::now()->addDays(7)->toDateString(),
        ]);
    }

    private function makeBooking(string $tenantId, string $conversationId): Booking
    {
        $repo = app(BookingRepository::class);
        return $repo->create([
            'tenant_id'       => $tenantId,
            'conversation_id' => $conversationId,
            'event_date'      => '2026-09-15',
            'event_type'      => 'resepsi',
            'customer_phone'  => '+628111222333',
            'status'          => BookingStatus::CONFIRMED->value,
        ]);
    }

    private function makeBookingForTenant(string $tenantId): Booking
    {
        $code = 'BKG-' . Carbon::now()->format('Ym') . '-' . str_pad(random_int(5000, 9999), 4, '0', STR_PAD_LEFT);
        return Booking::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $tenantId,
            'booking_code'   => $code,
            'event_date'     => '2026-10-01',
            'event_type'     => 'akad',
            'customer_phone' => '+628999888777',
            'status'         => BookingStatus::CONFIRMED->value,
        ]);
    }

    private function makeSuperadmin(): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'admin-' . Str::random(6) . '@platform.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function makeTenant(string $name, User $createdBy): Tenant
    {
        $slug = Str::slug($name) . '-' . Str::random(4);
        return Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => $name,
            'slug'          => $slug,
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => $slug . '@example.com',
            'created_by_id' => $createdBy->id,
        ]);
    }

    private function makeTenantAdmin(Tenant $tenant): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Admin ' . $tenant->name,
            'email'     => 'admin-' . Str::random(6) . '@' . $tenant->slug . '.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::TENANT_ADMIN,
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);
    }

    private function makeWaAccount(string $tenantId): WaAccount
    {
        return WaAccount::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'status'    => 'connected',
            'metadata'  => [],
        ]);
    }

    private function makeConversation(string $tenantId, string $waAccountId): Conversation
    {
        return Conversation::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $tenantId,
            'wa_account_id'  => $waAccountId,
            'customer_phone' => '+628111222333',
            'stage'          => ConversationStage::BOOKING->value,
            'agent_mode'     => AgentMode::ACTIVE->value,
            'memory_mode'    => MemoryMode::ACTIVE->value,
        ]);
    }
}
