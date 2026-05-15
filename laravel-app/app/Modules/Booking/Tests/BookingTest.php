<?php

namespace App\Modules\Booking\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Repositories\BookingRepository;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private BookingRepository $repo;
    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo    = app(BookingRepository::class);
        $superadmin    = $this->makeSuperadmin();
        $this->tenantA = $this->makeTenant('Vendor A', $superadmin);
        $this->tenantB = $this->makeTenant('Vendor B', $superadmin);
    }

    public function test_create_booking_status_draft_and_unique_code(): void
    {
        $booking = $this->repo->create([
            'tenant_id'  => $this->tenantA->id,
            'event_date' => '2026-09-01',
            'event_type' => 'resepsi',
        ]);

        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertSame(BookingStatus::DRAFT, $booking->status);
        $this->assertMatchesRegularExpression('/^BKG-\d{6}-\d{4}$/', $booking->booking_code);
    }

    public function test_mark_confirmed_sets_status_and_timestamp(): void
    {
        $booking = $this->makeBooking($this->tenantA->id);

        $booking->markConfirmed();

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::CONFIRMED, $fresh->status);
        $this->assertNotNull($fresh->confirmed_at);
    }

    public function test_mark_cancelled_sets_status_and_timestamp(): void
    {
        $booking = $this->makeBooking($this->tenantA->id);

        $booking->markCancelled('Customer request');

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::CANCELLED, $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
    }

    public function test_find_active_by_tenant_and_date_excludes_cancelled_and_expired(): void
    {
        $date = Carbon::parse('2026-10-01');

        $active    = $this->makeBooking($this->tenantA->id, $date->toDateString(), 'resepsi');
        $cancelled = $this->makeBooking($this->tenantA->id, $date->toDateString(), 'akad', BookingStatus::CANCELLED);
        $expired   = $this->makeBooking($this->tenantA->id, $date->toDateString(), 'keduanya', BookingStatus::EXPIRED);

        $results = $this->repo->findActiveByTenantAndDate($this->tenantA->id, $date);

        $ids = $results->pluck('id')->toArray();
        $this->assertContains($active->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);
        $this->assertNotContains($expired->id, $ids);
    }

    public function test_unique_constraint_prevents_duplicate_active_booking_same_date_type(): void
    {
        $this->makeBookingWithStatus($this->tenantA->id, '2026-11-01', 'resepsi', BookingStatus::CONFIRMED);

        $this->expectException(QueryException::class);

        $this->makeBookingWithStatus($this->tenantA->id, '2026-11-01', 'resepsi', BookingStatus::CONFIRMED);
    }

    public function test_tenant_isolation_repo_does_not_return_other_tenant_bookings(): void
    {
        $bookingA = $this->makeBooking($this->tenantA->id);
        $bookingB = $this->makeBooking($this->tenantB->id);

        $resultsA = $this->repo->findActiveByTenantAndDate(
            $this->tenantA->id,
            Carbon::parse($bookingA->event_date)
        );

        $idsA = $resultsA->pluck('id')->toArray();
        $this->assertContains($bookingA->id, $idsA);
        $this->assertNotContains($bookingB->id, $idsA);
    }

    public function test_booking_code_sequence_different_per_month_per_tenant(): void
    {
        $b1 = $this->repo->create([
            'tenant_id'  => $this->tenantA->id,
            'event_date' => '2026-12-01',
            'event_type' => 'resepsi',
        ]);

        $b2 = $this->repo->create([
            'tenant_id'  => $this->tenantA->id,
            'event_date' => '2026-12-15',
            'event_type' => 'akad',
        ]);

        $this->assertNotSame($b1->booking_code, $b2->booking_code);

        $seq1 = (int) substr($b1->booking_code, -4);
        $seq2 = (int) substr($b2->booking_code, -4);
        $this->assertGreaterThan($seq1, $seq2);
    }

    public function test_find_upcoming_by_tenant_returns_within_range(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');

        $upcoming = $this->makeBooking($this->tenantA->id, '2026-09-10', 'resepsi');
        $far      = $this->makeBooking($this->tenantA->id, '2026-12-31', 'akad');

        $results = $this->repo->findUpcomingByTenant($this->tenantA->id, 30);

        $ids = $results->pluck('id')->toArray();
        $this->assertContains($upcoming->id, $ids);
        $this->assertNotContains($far->id, $ids);

        Carbon::setTestNow();
    }

    public function test_count_by_status(): void
    {
        $this->makeBooking($this->tenantA->id, '2026-09-05', 'resepsi', BookingStatus::CONFIRMED);
        $this->makeBooking($this->tenantA->id, '2026-09-06', 'akad', BookingStatus::CONFIRMED);
        $this->makeBooking($this->tenantA->id, '2026-09-07', 'keduanya');

        $count = $this->repo->countByStatus($this->tenantA->id, BookingStatus::CONFIRMED);

        $this->assertSame(2, $count);
    }

    // --- Helpers ---

    private function makeBooking(
        string $tenantId,
        string $date = '2026-09-01',
        string $eventType = 'resepsi',
        BookingStatus $status = BookingStatus::DRAFT,
    ): Booking {
        return $this->repo->create([
            'tenant_id'  => $tenantId,
            'event_date' => $date,
            'event_type' => $eventType,
            'status'     => $status->value,
        ]);
    }

    private function makeBookingWithStatus(
        string $tenantId,
        string $date,
        string $eventType,
        BookingStatus $status,
    ): Booking {
        // Direct create bypassing generateBookingCode lock to allow explicit status
        $code = 'BKG-' . Carbon::now()->format('Ym') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);

        return Booking::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $tenantId,
            'booking_code' => $code,
            'event_date'   => $date,
            'event_type'   => $eventType,
            'status'       => $status->value,
        ]);
    }

    private function makeSuperadmin(): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'admin-' . Str::random(4) . '@platform.com',
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
}
