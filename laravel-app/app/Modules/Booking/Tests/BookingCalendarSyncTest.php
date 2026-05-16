<?php

namespace App\Modules\Booking\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Repositories\BookingRepository;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Calendar\Adapters\NullCalendarAdapter;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Shared\Contracts\CalendarProviderInterface;
use App\Modules\Shared\DTOs\CalendarEventDTO;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\MemoryMode;
use App\Modules\Shared\Enums\NotificationType;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WaAccount;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingCalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    private BookingRepository $repo;
    private Tenant $tenant;
    private User $admin;
    private WaAccount $waAccount;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $superadmin          = $this->makeSuperadmin();
        $this->tenant        = $this->makeTenant('Sync Vendor', $superadmin);
        $this->admin         = $this->makeTenantAdmin($this->tenant);
        $this->waAccount     = $this->makeWaAccount($this->tenant->id);
        $this->conversation  = $this->makeConversation($this->tenant->id, $this->waAccount->id);
        $this->repo          = app(BookingRepository::class);
    }

    // --- confirm: calendar event created and id saved ---

    public function test_confirm_saves_calendar_event_id_when_adapter_returns_id(): void
    {
        $calendar = $this->mockCalendar();
        $calendar->expects($this->once())
            ->method('createEvent')
            ->willReturn('evt-abc-123');

        $service = $this->makeService($calendar);
        $booking = $this->makeDraftBooking('2026-09-01', 'resepsi');

        $service->confirm($booking);

        $this->assertSame('evt-abc-123', $booking->fresh()->calendar_event_id);
        $this->assertSame(BookingStatus::CONFIRMED, $booking->fresh()->status);
    }

    public function test_confirm_calendar_event_id_stays_null_when_adapter_returns_null(): void
    {
        $service = $this->makeService(new NullCalendarAdapter());
        $booking = $this->makeDraftBooking('2026-09-02', 'akad');

        $service->confirm($booking);

        $this->assertNull($booking->fresh()->calendar_event_id);
        $this->assertSame(BookingStatus::CONFIRMED, $booking->fresh()->status);
    }

    // --- confirm: adapter throws → booking still CONFIRMED + CALENDAR_ERROR ---

    public function test_confirm_booking_still_confirmed_when_adapter_throws(): void
    {
        $calendar = $this->mockCalendar();
        $calendar->expects($this->once())
            ->method('createEvent')
            ->willThrowException(new \RuntimeException('Google API unreachable'));

        $service = $this->makeService($calendar);
        $booking = $this->makeDraftBooking('2026-09-03', 'resepsi');

        $service->confirm($booking);

        // Booking must be CONFIRMED despite calendar failure
        $this->assertSame(BookingStatus::CONFIRMED, $booking->fresh()->status);
        $this->assertNull($booking->fresh()->calendar_event_id);

        // CALENDAR_ERROR notification must be emitted
        $this->assertDatabaseHas('admin_notifications', [
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->admin->id,
            'type'      => NotificationType::CALENDAR_ERROR->value,
        ]);
    }

    // --- cancel: deleteEvent called when calendar_event_id set ---

    public function test_cancel_calls_delete_event_when_calendar_event_id_present(): void
    {
        $calendar = $this->mockCalendar();
        $calendar->expects($this->once())
            ->method('deleteEvent')
            ->with($this->tenant->id, 'evt-to-delete')
            ->willReturn(true);

        $service = $this->makeService($calendar);
        $booking = $this->makeConfirmedBookingWithCalendarId('2026-09-04', 'resepsi', 'evt-to-delete');

        $service->cancel($booking, 'Customer cancelled');

        $this->assertSame(BookingStatus::CANCELLED, $booking->fresh()->status);
    }

    public function test_cancel_does_not_call_delete_event_when_no_calendar_event_id(): void
    {
        $calendar = $this->mockCalendar();
        $calendar->expects($this->never())->method('deleteEvent');

        $service = $this->makeService($calendar);
        $booking = $this->makeDraftBooking('2026-09-05', 'akad');

        $service->cancel($booking, 'Cancelled');

        $this->assertSame(BookingStatus::CANCELLED, $booking->fresh()->status);
    }

    // --- reschedule: updateEvent called when calendar_event_id present ---

    public function test_reschedule_calls_update_event_when_calendar_event_id_present(): void
    {
        $calendar = $this->mockCalendar();
        $calendar->expects($this->once())
            ->method('updateEvent')
            ->with($this->tenant->id, 'evt-reschedule', $this->isInstanceOf(CalendarEventDTO::class))
            ->willReturn(true);

        $service = $this->makeService($calendar);
        $booking = $this->makeConfirmedBookingWithCalendarId('2026-10-01', 'resepsi', 'evt-reschedule');

        $result = $service->rescheduleBooking($booking, Carbon::parse('2026-10-15'));

        $this->assertTrue($result);
        $this->assertSame('2026-10-15', $booking->fresh()->event_date->toDateString());
    }

    public function test_reschedule_does_not_call_update_event_when_no_calendar_event_id(): void
    {
        $calendar = $this->mockCalendar();
        $calendar->expects($this->never())->method('updateEvent');

        $service = $this->makeService($calendar);
        $booking = $this->makeDraftBooking('2026-10-02', 'akad');

        $result = $service->rescheduleBooking($booking, Carbon::parse('2026-10-20'));

        $this->assertTrue($result);
        $this->assertSame('2026-10-20', $booking->fresh()->event_date->toDateString());
    }

    public function test_reschedule_returns_false_when_new_date_unavailable(): void
    {
        // Block 2026-11-01 resepsi
        $this->makeConfirmedBookingWithCalendarId('2026-11-01', 'resepsi', null);

        $service = $this->makeService(new NullCalendarAdapter());
        $booking = $this->makeDraftBooking('2026-10-01', 'resepsi');

        $result = $service->rescheduleBooking($booking, Carbon::parse('2026-11-01'));

        $this->assertFalse($result);
        // Original date should not change
        $this->assertSame('2026-10-01', $booking->fresh()->event_date->toDateString());
    }

    public function test_reschedule_updates_event_time_start_when_provided(): void
    {
        $service = $this->makeService(new NullCalendarAdapter());
        $booking = $this->makeDraftBooking('2026-12-01', 'resepsi');

        $service->rescheduleBooking($booking, Carbon::parse('2026-12-15'), '10:00');

        $fresh = $booking->fresh();
        $this->assertSame('2026-12-15', $fresh->event_date->toDateString());
        $this->assertStringStartsWith('10:00', $fresh->event_time_start);
    }

    // --- buildCalendarEvent: event title format ---

    public function test_confirm_calendar_event_title_follows_expected_format(): void
    {
        $capturedEvent = null;

        $calendar = $this->mockCalendar();
        $calendar->method('createEvent')
            ->willReturnCallback(function (string $tenantId, CalendarEventDTO $event) use (&$capturedEvent) {
                $capturedEvent = $event;
                return 'evt-title-test';
            });

        $service = $this->makeService($calendar);
        $booking = $this->makeDraftBookingWithName('2026-08-20', 'resepsi', 'Budi Santoso');

        $service->confirm($booking);

        $this->assertNotNull($capturedEvent);
        $this->assertStringContainsString('Budi Santoso', $capturedEvent->title);
        $this->assertStringContainsString('resepsi', $capturedEvent->title);
        $this->assertStringContainsString($booking->booking_code, $capturedEvent->title);
    }

    // --- Helpers ---

    private function makeService(CalendarProviderInterface $calendar): BookingService
    {
        return new BookingService(
            $this->repo,
            app(NotificationService::class),
            app(ConversationRepository::class),
            $calendar,
        );
    }

    private function mockCalendar(): CalendarProviderInterface
    {
        return $this->createMock(CalendarProviderInterface::class);
    }

    private function makeDraftBooking(string $date, string $eventType): Booking
    {
        return $this->repo->create([
            'tenant_id'       => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'event_date'      => $date,
            'event_type'      => $eventType,
        ]);
    }

    private function makeDraftBookingWithName(string $date, string $eventType, string $customerName): Booking
    {
        return $this->repo->create([
            'tenant_id'       => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'event_date'      => $date,
            'event_type'      => $eventType,
            'customer_name'   => $customerName,
        ]);
    }

    private function makeConfirmedBookingWithCalendarId(string $date, string $eventType, ?string $calendarEventId): Booking
    {
        $code = 'BKG-' . Carbon::now()->format('Ym') . '-' . str_pad(random_int(100, 999), 4, '0', STR_PAD_LEFT);

        return Booking::create([
            'id'                => Str::uuid()->toString(),
            'tenant_id'         => $this->tenant->id,
            'conversation_id'   => $this->conversation->id,
            'booking_code'      => $code,
            'event_date'        => $date,
            'event_type'        => $eventType,
            'status'            => BookingStatus::CONFIRMED->value,
            'calendar_event_id' => $calendarEventId,
        ]);
    }

    private function makeSuperadmin(): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'sa-' . Str::random(6) . '@platform.com',
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
            'email'     => 'adm-' . Str::random(6) . '@' . $tenant->slug . '.com',
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
            'name'      => 'Sync WA',
            'status'    => 'connected',
        ]);
    }

    private function makeConversation(string $tenantId, string $waAccountId): Conversation
    {
        return Conversation::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $tenantId,
            'wa_account_id'  => $waAccountId,
            'customer_phone' => '+628222333444',
            'stage'          => 'booking',
            'agent_mode'     => AgentMode::ACTIVE->value,
            'memory_mode'    => MemoryMode::ACTIVE->value,
        ]);
    }
}
