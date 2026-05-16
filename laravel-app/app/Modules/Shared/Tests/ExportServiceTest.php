<?php

namespace App\Modules\Shared\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\InvoiceStatus;
use App\Modules\Shared\Enums\InvoiceType;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Services\ExportService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExportServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant  $tenantA;
    private Tenant  $tenantB;
    private Booking $booking;
    private Invoice $invoice;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $superadmin = User::create([
            'name'      => 'Super',
            'email'     => 'super-export-' . Str::random(5) . '@test.com',
            'password'  => bcrypt('pw'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        $this->tenantA = Tenant::create([
            'name'          => 'Export A',
            'slug'          => 'exp-a-' . Str::random(4),
            'status'        => TenantStatus::ACTIVE->value,
            'industry'      => 'wedding',
            'contact_email' => 'a@export.com',
            'created_by_id' => $superadmin->id,
        ]);

        $this->tenantB = Tenant::create([
            'name'          => 'Export B',
            'slug'          => 'exp-b-' . Str::random(4),
            'status'        => TenantStatus::ACTIVE->value,
            'industry'      => 'wedding',
            'contact_email' => 'b@export.com',
            'created_by_id' => $superadmin->id,
        ]);

        $package = Package::create([
            'tenant_id'   => $this->tenantA->id,
            'name'        => 'Paket Export',
            'slug'        => 'pkg-exp-' . Str::random(4),
            'price'       => 8000000,
            'description' => 'd',
            'is_active'   => true,
        ]);

        $this->conversation = Conversation::create([
            'tenant_id'      => $this->tenantA->id,
            'customer_phone' => '+6281200000001',
            'customer_name'  => 'Siti Export',
            'stage'          => 'booking',
            'agent_mode'     => 'active',
            'memory_mode'    => 'active',
            'temperature'    => 'warm',
        ]);

        $this->booking = Booking::create([
            'tenant_id'       => $this->tenantA->id,
            'conversation_id' => $this->conversation->id,
            'package_id'      => $package->id,
            'booking_code'    => 'BKG-EXP-001',
            'customer_name'   => 'Siti Export',
            'customer_phone'  => '+6281200000001',
            'event_date'      => '2026-09-15',
            'event_type'      => 'resepsi',
            'location'        => 'Bandung',
            'status'          => BookingStatus::CONFIRMED->value,
            'total_amount'    => 8000000,
            'dp_amount'       => 2000000,
        ]);

        $this->invoice = Invoice::create([
            'tenant_id'      => $this->tenantA->id,
            'booking_id'     => $this->booking->id,
            'invoice_number' => 'INV-EXP-001',
            'type'           => InvoiceType::DP->value,
            'status'         => InvoiceStatus::ISSUED->value,
            'amount'         => 2000000,
            'due_date'       => now()->addDays(7),
            'sent_count'     => 0,
        ]);
    }

    public function test_export_bookings_contains_header(): void
    {
        $service = app(ExportService::class);
        $csv     = $service->exportBookings($this->tenantA->id);

        $this->assertStringContainsString('booking_code', $csv);
        $this->assertStringContainsString('customer_name', $csv);
        $this->assertStringContainsString('event_date', $csv);
    }

    public function test_export_bookings_contains_booking_data(): void
    {
        $service = app(ExportService::class);
        $csv     = $service->exportBookings($this->tenantA->id);

        $this->assertStringContainsString('BKG-EXP-001', $csv);
        $this->assertStringContainsString('Siti Export', $csv);
        $this->assertStringContainsString('Paket Export', $csv);
    }

    public function test_export_bookings_tenant_isolation(): void
    {
        // Create a booking for tenant B — should NOT appear in tenant A export
        Booking::create([
            'tenant_id'      => $this->tenantB->id,
            'booking_code'   => 'BKG-B-001',
            'customer_name'  => 'Tenant B Customer',
            'customer_phone' => '+6281299999999',
            'event_date'     => '2026-09-20',
            'event_type'     => 'akad',
            'location'       => 'Surabaya',
            'status'         => BookingStatus::CONFIRMED->value,
            'total_amount'   => 5000000,
            'dp_amount'      => 1000000,
        ]);

        $service = app(ExportService::class);
        $csv     = $service->exportBookings($this->tenantA->id);

        $this->assertStringNotContainsString('BKG-B-001', $csv);
        $this->assertStringNotContainsString('Tenant B Customer', $csv);
        $this->assertStringContainsString('BKG-EXP-001', $csv);
    }

    public function test_export_invoices_contains_invoice_header(): void
    {
        $service = app(ExportService::class);
        $csv     = $service->exportInvoices($this->tenantA->id);

        $this->assertStringContainsString('invoice_number', $csv);
        $this->assertStringContainsString('booking_code', $csv);
    }

    public function test_export_invoices_contains_invoice_data(): void
    {
        $service = app(ExportService::class);
        $csv     = $service->exportInvoices($this->tenantA->id);

        $this->assertStringContainsString('INV-EXP-001', $csv);
        $this->assertStringContainsString('BKG-EXP-001', $csv);
    }

    public function test_export_leads_phone_is_masked(): void
    {
        $service = app(ExportService::class);
        $csv     = $service->exportLeads($this->tenantA->id);

        // Full phone should NOT appear
        $this->assertStringNotContainsString('+6281200000001', $csv);
        // Masked format should appear
        $this->assertStringContainsString('+62', $csv);
        $this->assertStringContainsString('***', $csv);
    }

    public function test_export_leads_tenant_isolation(): void
    {
        Conversation::create([
            'tenant_id'      => $this->tenantB->id,
            'customer_phone' => '+6281299999999',
            'customer_name'  => 'B Lead',
            'stage'          => 'new_lead',
            'agent_mode'     => 'active',
            'memory_mode'    => 'active',
            'temperature'    => 'cold',
        ]);

        $service = app(ExportService::class);
        $csv     = $service->exportLeads($this->tenantA->id);

        $this->assertStringNotContainsString('B Lead', $csv);
        $this->assertStringContainsString('Siti Export', $csv);
    }
}
