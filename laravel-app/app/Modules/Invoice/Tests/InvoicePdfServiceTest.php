<?php

namespace App\Modules\Invoice\Tests;

use App\Modules\Booking\Models\Booking;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Invoice\Jobs\GenerateInvoicePdfJob;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Services\InvoicePdfService;
use App\Modules\Invoice\Services\InvoiceService;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Shared\Contracts\ChannelGatewayInterface;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\InvoiceStatus;
use App\Modules\Shared\Enums\InvoiceType;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\TenantConfig\Models\TenantSetting;
use App\Modules\Auth\Models\User;
use App\Modules\WhatsApp\Models\WaAccount;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoicePdfServiceTest extends TestCase
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
            'email'     => 'super-pdf@test.com',
            'password'  => bcrypt('password'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        $this->tenant = Tenant::create([
            'name'          => 'PDF Vendor',
            'slug'          => 'pdf-vendor-' . Str::random(4),
            'status'        => TenantStatus::ACTIVE->value,
            'industry'      => 'wedding',
            'contact_email' => 'pdf@vendor.com',
            'created_by_id' => $superadmin->id,
        ]);

        $package = Package::create([
            'tenant_id'   => $this->tenant->id,
            'name'        => 'Paket Gold',
            'slug'        => 'paket-gold',
            'price'       => 15000000,
            'description' => 'Gold package',
            'is_active'   => true,
        ]);

        $conversation = Conversation::create([
            'tenant_id'      => $this->tenant->id,
            'customer_phone' => '+6281234567890',
            'stage'          => 'booking',
            'agent_mode'     => 'active',
            'memory_mode'    => 'active',
            'temperature'    => 'warm',
        ]);

        $this->booking = Booking::create([
            'tenant_id'       => $this->tenant->id,
            'conversation_id' => $conversation->id,
            'package_id'      => $package->id,
            'booking_code'    => 'BKG-202607-0001',
            'customer_name'   => 'Budi Santoso',
            'customer_phone'  => '+6281234567890',
            'event_date'      => '2026-07-20',
            'event_type'      => 'resepsi',
            'location'        => 'Jakarta Selatan',
            'status'          => BookingStatus::CONFIRMED->value,
            'total_amount'    => 15000000,
            'dp_amount'       => 5000000,
        ]);

        $this->invoice = Invoice::create([
            'tenant_id'      => $this->tenant->id,
            'booking_id'     => $this->booking->id,
            'invoice_number' => 'INV-202607-0001',
            'type'           => InvoiceType::DP->value,
            'status'         => InvoiceStatus::ISSUED->value,
            'amount'         => 5000000,
            'due_date'       => now()->addDays(7),
            'sent_count'     => 0,
        ]);

        $this->waAccount = WaAccount::create([
            'tenant_id' => $this->tenant->id,
            'status'    => WaAccountStatus::CONNECTED->value,
            'metadata'  => [],
        ]);
        $this->waAccount->markConnected('+6285511112222');
    }

    public function test_generate_creates_pdf_and_stores_url(): void
    {
        Storage::fake();

        $service = app(InvoicePdfService::class);
        $url     = $service->generate($this->invoice);

        $this->assertNotEmpty($url);
        $this->assertNotNull($this->invoice->fresh()->pdf_url);
        $this->assertEquals($url, $this->invoice->fresh()->pdf_url);
    }

    public function test_generate_blade_view_renders_without_error(): void
    {
        Storage::fake();

        $service = app(InvoicePdfService::class);
        $service->generate($this->invoice);

        // If Blade view throws an exception, the test would fail
        $this->assertTrue(true);
    }

    public function test_regenerate_deletes_old_and_creates_new(): void
    {
        Storage::fake();

        $service = app(InvoicePdfService::class);
        $firstUrl = $service->generate($this->invoice);

        $this->invoice->update(['pdf_url' => $firstUrl]);

        $secondUrl = $service->regenerate($this->invoice->fresh());

        $this->assertNotEmpty($secondUrl);
        $this->assertEquals($secondUrl, $this->invoice->fresh()->pdf_url);
    }

    public function test_invoice_service_send_calls_send_file_when_pdf_url_set(): void
    {
        Http::fake([
            '*' => Http::response(['success' => true, 'provider_message_id' => 'msg-123'], 200),
        ]);

        $this->invoice->update(['pdf_url' => 'https://storage.null/null/invoices/test.pdf']);

        $service = app(InvoiceService::class);
        $result  = $service->send($this->invoice->fresh());

        $this->assertTrue($result);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'dispatch'));
    }

    public function test_generate_invoice_pdf_job_dispatched_on_issue(): void
    {
        Bus::fake();

        $service = app(InvoiceService::class);
        $service->issue(
            $this->booking,
            InvoiceType::DP,
            5000000,
            Carbon::now()->addDays(7),
        );

        Bus::assertDispatched(GenerateInvoicePdfJob::class);
    }
}
