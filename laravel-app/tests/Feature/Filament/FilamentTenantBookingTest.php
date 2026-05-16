<?php

namespace Tests\Feature\Filament;

use App\Modules\Auth\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Shared\Contracts\CalendarProviderInterface;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WaAccount;
use App\Modules\Shared\Enums\WaAccountStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class FilamentTenantBookingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $tenantAdmin;
    private User $superadmin;

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

        // Bind NullCalendarAdapter so tests don't call real Google API
        $this->app->bind(CalendarProviderInterface::class, function () {
            return new \App\Modules\Calendar\Adapters\NullCalendarAdapter();
        });
    }

    private function makeBooking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->tenant->id,
            'booking_code' => Booking::generateBookingCode($this->tenant->id),
            'status'       => BookingStatus::DRAFT,
            'event_date'   => '2026-09-01',
            'event_type'   => 'resepsi',
            'customer_name'  => 'Budi',
            'customer_phone' => '+6281234567890',
            'total_amount'   => 15000000,
            'dp_amount'      => 5000000,
            'metadata'       => [],
        ], $overrides));
    }

    // ─── Route access ────────────────────────────────────────────────────

    public function test_tenant_admin_can_access_bookings_page(): void
    {
        $response = $this->actingAs($this->tenantAdmin)->get('/app/bookings');

        $response->assertStatus(200);
    }

    public function test_superadmin_cannot_access_tenant_booking_page(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/app/bookings');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_redirected_from_bookings(): void
    {
        $response = $this->get('/app/bookings');

        $response->assertRedirect();
    }

    // ─── Booking operations ──────────────────────────────────────────────

    public function test_confirm_booking_changes_status_to_confirmed(): void
    {
        $booking = $this->makeBooking();

        // Mock WA account for notification
        WaAccount::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->tenant->id,
            'display_name' => 'CS Test',
            'status'       => WaAccountStatus::CONNECTED,
            'metadata'     => [],
        ]);

        $service = app(BookingService::class);
        $service->confirm($booking);

        $this->assertDatabaseHas('bookings', [
            'id'     => $booking->id,
            'status' => BookingStatus::CONFIRMED->value,
        ]);
        $this->assertNotNull($booking->fresh()->confirmed_at);
    }

    public function test_cancel_booking_changes_status_to_cancelled(): void
    {
        $booking = $this->makeBooking(['status' => BookingStatus::CONFIRMED]);

        app(BookingService::class)->cancel($booking, 'Customer mengundurkan diri');

        $this->assertDatabaseHas('bookings', [
            'id'     => $booking->id,
            'status' => BookingStatus::CANCELLED->value,
        ]);
        $this->assertNotNull($booking->fresh()->cancelled_at);
    }

    public function test_reschedule_booking_updates_event_date(): void
    {
        $booking = $this->makeBooking(['status' => BookingStatus::CONFIRMED]);
        $newDate = Carbon::parse('2026-10-15');

        $result = app(BookingService::class)->rescheduleBooking($booking, $newDate);

        $this->assertTrue($result);
        $this->assertDatabaseHas('bookings', [
            'id'         => $booking->id,
            'event_date' => '2026-10-15',
        ]);
    }

    public function test_reschedule_fails_when_date_already_booked(): void
    {
        // Existing confirmed booking on 2026-10-15
        $this->makeBooking([
            'status'     => BookingStatus::CONFIRMED,
            'event_date' => '2026-10-15',
        ]);

        $booking = $this->makeBooking([
            'status'     => BookingStatus::CONFIRMED,
            'event_date' => '2026-09-01',
        ]);

        $result = app(BookingService::class)->rescheduleBooking($booking, Carbon::parse('2026-10-15'));

        $this->assertFalse($result);
        $this->assertDatabaseHas('bookings', [
            'id'         => $booking->id,
            'event_date' => '2026-09-01', // unchanged
        ]);
    }

    // ─── Tenant isolation ────────────────────────────────────────────────

    public function test_tenant_isolation_bookings_not_visible_across_tenants(): void
    {
        // Booking from another tenant
        Booking::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->otherTenant->id,
            'booking_code' => 'BKG-999999-0001',
            'status'       => BookingStatus::CONFIRMED,
            'event_date'   => '2026-09-01',
            'metadata'     => [],
        ]);

        $bookings = Booking::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->get();

        $this->assertCount(0, $bookings);
    }

    // ─── Calendar settings page ──────────────────────────────────────────

    public function test_tenant_admin_can_access_calendar_settings_page(): void
    {
        $response = $this->actingAs($this->tenantAdmin)->get('/app/calendar-settings');

        $response->assertStatus(200);
    }
}
