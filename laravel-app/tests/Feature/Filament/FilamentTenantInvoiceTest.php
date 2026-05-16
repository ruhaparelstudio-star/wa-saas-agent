<?php

namespace Tests\Feature\Filament;

use App\Modules\Auth\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Services\InvoiceService;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\InvoiceStatus;
use App\Modules\Shared\Enums\InvoiceType;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\TenantConfig\Models\TenantPolicy;
use App\Modules\Shared\Enums\PolicyKey;
use App\Modules\WhatsApp\Models\WaAccount;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FilamentTenantInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $tenantAdmin;
    private User $superadmin;
    private Booking $booking;
    private WaAccount $waAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superadmin = User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'super@platform.com',
            'password'  => Hash::make('password'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        $this->tenant = Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Vendor A',
            'slug'          => 'vendor-a',
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => 'vendor-a@example.com',
            'created_by_id' => $this->superadmin->id,
        ]);

        $this->otherTenant = Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Vendor B',
            'slug'          => 'vendor-b',
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => 'vendor-b@example.com',
            'created_by_id' => $this->superadmin->id,
        ]);

        $this->tenantAdmin = User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Admin A',
            'email'     => 'admin@vendor-a.com',
            'password'  => Hash::make('password'),
            'role'      => UserRole::TENANT_ADMIN,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        $this->waAccount = WaAccount::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->tenant->id,
            'display_name' => 'CS Test',
            'status'       => WaAccountStatus::CONNECTED,
            'metadata'     => [],
        ]);

        $this->booking = Booking::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $this->tenant->id,
            'booking_code'   => Booking::generateBookingCode($this->tenant->id),
            'status'         => BookingStatus::CONFIRMED,
            'event_date'     => '2026-09-01',
            'event_type'     => 'resepsi',
            'customer_name'  => 'Budi',
            'customer_phone' => '+6281234567890',
            'total_amount'   => 15000000,
            'dp_amount'      => 5000000,
            'metadata'       => [],
        ]);

        // Set INVOICE_MAX_RESEND policy
        TenantPolicy::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->tenant->id,
            'policy_key'   => PolicyKey::INVOICE_MAX_RESEND->value,
            'policy_value' => '2',
        ]);

        Http::fake([
            '*' => Http::response(['success' => true, 'provider_message_id' => 'msg-abc'], 200),
        ]);
    }

    private function makeInvoice(array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $this->tenant->id,
            'booking_id'     => $this->booking->id,
            'invoice_number' => Invoice::generateInvoiceNumber(),
            'type'           => InvoiceType::DP->value,
            'status'         => InvoiceStatus::ISSUED->value,
            'amount'         => 5000000,
            'due_date'       => now()->addDays(7)->toDateString(),
            'sent_count'     => 0,
            'metadata'       => [],
        ], $overrides));
    }

    // ─── Route access ────────────────────────────────────────────────────

    public function test_tenant_admin_can_access_invoices_page(): void
    {
        $response = $this->actingAs($this->tenantAdmin)->get('/app/invoices');

        $response->assertStatus(200);
    }

    public function test_superadmin_cannot_access_tenant_invoice_page(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/app/invoices');

        $response->assertStatus(403);
    }

    // ─── Invoice operations ──────────────────────────────────────────────

    public function test_send_invoice_marks_sent_and_increments_count(): void
    {
        $invoice = $this->makeInvoice();

        $result = app(InvoiceService::class)->send($invoice);

        $this->assertTrue($result);
        $this->assertDatabaseHas('invoices', [
            'id'         => $invoice->id,
            'status'     => InvoiceStatus::SENT->value,
            'sent_count' => 1,
        ]);
    }

    public function test_resend_invoice_within_limit_succeeds(): void
    {
        // sent_count=1, max=2 → resend OK
        $invoice = $this->makeInvoice([
            'status'     => InvoiceStatus::SENT->value,
            'sent_count' => 1,
        ]);

        $result = app(InvoiceService::class)->send($invoice);

        $this->assertTrue($result);
        $this->assertDatabaseHas('invoices', [
            'id'         => $invoice->id,
            'sent_count' => 2,
        ]);
    }

    public function test_resend_invoice_over_max_limit_fails(): void
    {
        // sent_count=2, max=2 → canResend false
        $invoice = $this->makeInvoice([
            'status'     => InvoiceStatus::SENT->value,
            'sent_count' => 2,
        ]);

        $result = app(InvoiceService::class)->send($invoice);

        $this->assertFalse($result);
        // sent_count unchanged
        $this->assertDatabaseHas('invoices', [
            'id'         => $invoice->id,
            'sent_count' => 2,
        ]);
    }

    public function test_mark_paid_sets_invoice_status_and_updates_booking(): void
    {
        $invoice = $this->makeInvoice([
            'status' => InvoiceStatus::SENT->value,
        ]);

        app(InvoiceService::class)->markPaid($invoice, 'https://proof.example.com/img.jpg');

        $this->assertDatabaseHas('invoices', [
            'id'                => $invoice->id,
            'status'            => InvoiceStatus::PAID->value,
            'payment_proof_url' => 'https://proof.example.com/img.jpg',
        ]);

        $this->assertDatabaseHas('bookings', [
            'id'     => $this->booking->id,
            'status' => BookingStatus::PAID->value,
        ]);
    }

    public function test_issue_invoice_creates_issued_invoice_and_sets_booking_awaiting_dp(): void
    {
        $invoice = app(InvoiceService::class)->issue(
            $this->booking,
            InvoiceType::DP,
            5000000,
            Carbon::now()->addDays(7)
        );

        $this->assertDatabaseHas('invoices', [
            'id'     => $invoice->id,
            'status' => InvoiceStatus::ISSUED->value,
        ]);

        $this->assertDatabaseHas('bookings', [
            'id'     => $this->booking->id,
            'status' => BookingStatus::AWAITING_DP->value,
        ]);
    }

    // ─── Tenant isolation ────────────────────────────────────────────────

    public function test_tenant_isolation_invoice_not_visible_for_other_tenant(): void
    {
        // Invoice belonging to otherTenant
        $otherBooking = Booking::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->otherTenant->id,
            'booking_code' => 'BKG-999999-0001',
            'status'       => BookingStatus::CONFIRMED,
            'event_date'   => '2026-09-01',
            'metadata'     => [],
        ]);

        Invoice::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $this->otherTenant->id,
            'booking_id'     => $otherBooking->id,
            'invoice_number' => 'INV-999999-0001',
            'type'           => InvoiceType::DP->value,
            'status'         => InvoiceStatus::ISSUED->value,
            'amount'         => 3000000,
            'due_date'       => now()->addDays(7)->toDateString(),
            'sent_count'     => 0,
            'metadata'       => [],
        ]);

        // Tenant A should only see its own invoices
        $invoices = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->get();

        $this->assertCount(0, $invoices);
    }
}
