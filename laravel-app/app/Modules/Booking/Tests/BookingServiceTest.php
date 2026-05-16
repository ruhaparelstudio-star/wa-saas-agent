<?php

namespace App\Modules\Booking\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Repositories\BookingRepository;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Notification\Models\AdminNotification;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Shared\DTOs\ConversationDTO;
use App\Modules\Shared\DTOs\ConversationStateDTO;
use App\Modules\Shared\DTOs\EntityResultDTO;
use App\Modules\Shared\DTOs\InboundMessageDTO;
use App\Modules\Shared\DTOs\IntentResultDTO;
use App\Modules\Shared\DTOs\TenantDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\MemoryMode;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WaAccount;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $service;
    private BookingRepository $repo;
    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $adminA;
    private WaAccount $waAccount;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $superadmin     = $this->makeSuperadmin();
        $this->tenantA  = $this->makeTenant('Vendor A', $superadmin);
        $this->tenantB  = $this->makeTenant('Vendor B', $superadmin);
        $this->adminA   = $this->makeTenantAdmin($this->tenantA);
        $this->waAccount = $this->makeWaAccount($this->tenantA->id);
        $this->conversation = $this->makeConversation($this->tenantA->id, $this->waAccount->id);

        $this->repo    = app(BookingRepository::class);
        $this->service = new BookingService(
            $this->repo,
            app(NotificationService::class),
            app(ConversationRepository::class),
        );
    }

    // --- checkAvailability ---

    public function test_check_availability_returns_true_when_date_empty(): void
    {
        $available = $this->service->checkAvailability(
            $this->tenantA->id,
            Carbon::parse('2026-09-01')
        );

        $this->assertTrue($available);
    }

    public function test_check_availability_returns_false_when_confirmed_booking_exists(): void
    {
        $this->makeConfirmedBooking($this->tenantA->id, '2026-09-01', 'resepsi');

        $available = $this->service->checkAvailability(
            $this->tenantA->id,
            Carbon::parse('2026-09-01'),
            'resepsi'
        );

        $this->assertFalse($available);
    }

    public function test_check_availability_draft_does_not_block_date(): void
    {
        // DRAFT status should not block the date
        $this->repo->create([
            'tenant_id'  => $this->tenantA->id,
            'event_date' => '2026-09-01',
            'event_type' => 'resepsi',
            'status'     => BookingStatus::DRAFT->value,
        ]);

        $available = $this->service->checkAvailability(
            $this->tenantA->id,
            Carbon::parse('2026-09-01'),
            'resepsi'
        );

        $this->assertTrue($available);
    }

    // --- createDraft ---

    public function test_create_draft_saves_booking_and_updates_stage(): void
    {
        $context = $this->makeContext([
            'event_date' => '2026-09-01',
            'event_type' => 'resepsi',
        ]);

        $booking = $this->service->createDraft($context);

        $this->assertNotNull($booking);
        $this->assertSame(BookingStatus::DRAFT, $booking->fresh()->status);
        $this->assertSame($this->conversation->id, $booking->conversation_id);

        $updatedConv = Conversation::find($this->conversation->id);
        $this->assertSame(ConversationStage::WAITING_BOOKING, $updatedConv->stage);
    }

    public function test_create_draft_returns_null_when_event_date_missing(): void
    {
        $context = $this->makeContext([]);

        $booking = $this->service->createDraft($context);

        $this->assertNull($booking);
    }

    public function test_create_draft_returns_null_when_date_unavailable(): void
    {
        $this->makeConfirmedBooking($this->tenantA->id, '2026-09-01', 'resepsi');

        $context = $this->makeContext([
            'event_date' => '2026-09-01',
            'event_type' => 'resepsi',
        ]);

        $result = $this->service->createDraft($context);

        $this->assertNull($result);
    }

    public function test_create_draft_sends_booking_notification(): void
    {
        $context = $this->makeContext(['event_date' => '2026-10-01', 'event_type' => 'akad']);

        $this->service->createDraft($context);

        $this->assertDatabaseHas('admin_notifications', [
            'tenant_id' => $this->tenantA->id,
            'user_id'   => $this->adminA->id,
            'type'      => 'booking_action',
        ]);
    }

    // --- confirm ---

    public function test_confirm_sets_status_confirmed_and_updates_stage(): void
    {
        $booking = $this->makeDraftBooking($this->tenantA->id, '2026-10-10', 'resepsi');

        $this->service->confirm($booking);

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::CONFIRMED, $fresh->status);
        $this->assertNotNull($fresh->confirmed_at);

        $updatedConv = Conversation::find($this->conversation->id);
        $this->assertSame(ConversationStage::BOOKING, $updatedConv->stage);
    }

    public function test_confirm_sends_notification(): void
    {
        $booking = $this->makeDraftBooking($this->tenantA->id, '2026-10-11', 'akad');

        $this->service->confirm($booking);

        $this->assertDatabaseHas('admin_notifications', [
            'tenant_id' => $this->tenantA->id,
            'type'      => 'booking_action',
        ]);
    }

    // --- cancel ---

    public function test_cancel_sets_status_cancelled(): void
    {
        $booking = $this->makeDraftBooking($this->tenantA->id, '2026-10-20', 'resepsi');

        $this->service->cancel($booking, 'Customer changed mind');

        $fresh = $booking->fresh();
        $this->assertSame(BookingStatus::CANCELLED, $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
    }

    // --- suggestAlternatives ---

    public function test_suggest_alternatives_returns_available_dates(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');

        // Block 2026-09-10
        $this->makeConfirmedBooking($this->tenantA->id, '2026-09-10', 'resepsi');

        $alts = $this->service->suggestAlternatives(
            $this->tenantA->id,
            Carbon::parse('2026-09-10'),
            7
        );

        // Should return alternative dates
        $this->assertNotEmpty($alts);
        $altDates = array_column($alts, 'date');
        $altDateStrings = array_map(fn ($d) => $d->toDateString(), $altDates);

        // 2026-09-10 should NOT be in alternatives (it's blocked)
        $this->assertNotContains('2026-09-10', $altDateStrings);

        Carbon::setTestNow();
    }

    // --- tenant isolation ---

    public function test_tenant_a_booking_does_not_block_tenant_b(): void
    {
        $this->makeConfirmedBooking($this->tenantA->id, '2026-11-01', 'resepsi');

        $available = $this->service->checkAvailability(
            $this->tenantB->id,
            Carbon::parse('2026-11-01'),
            'resepsi'
        );

        // Tenant B should still be available
        $this->assertTrue($available);
    }

    // --- Helpers ---

    private function makeContext(array $entities): TurnContextDTO
    {
        return TurnContextDTO::from([
            'tenant'     => TenantDTO::from([
                'id'            => $this->tenantA->id,
                'name'          => $this->tenantA->name,
                'slug'          => $this->tenantA->slug,
                'status'        => 'active',
                'industry'      => 'wedding',
                'contact_email' => 'test@example.com',
                'created_at'    => now()->toIsoString(),
            ]),
            'conversation' => ConversationDTO::from([
                'id'           => $this->conversation->id,
                'tenant_id'    => $this->tenantA->id,
                'wa_account_id' => $this->waAccount->id,
                'from_phone'   => '+628111222333',
                'stage'        => 'consideration',
                'agent_mode'   => 'active',
                'memory_mode'  => 'active',
                'created_at'   => now()->toIsoString(),
                'updated_at'   => now()->toIsoString(),
            ]),
            'state'   => ConversationStateDTO::from(['stage' => 'consideration']),
            'intent'   => IntentResultDTO::from(['intent' => 'request_booking', 'confidence' => 0.9]),
            'entities' => EntityResultDTO::from(['entities' => $entities]),
            'lead'     => \App\Modules\Shared\DTOs\LeadProfileDTO::from([]),
            'knowledge' => \App\Modules\Shared\DTOs\GroundedKnowledgeDTO::from([]),
            'config'    => \App\Modules\Shared\DTOs\TenantConfigDTO::from([]),
            'inbound_message' => InboundMessageDTO::from([
                'wa_account_id' => $this->waAccount->id,
                'from_phone'    => '+628111222333',
                'message_type'  => 'text',
                'body'          => 'saya mau booking',
                'received_at'   => now()->toIsoString(),
            ]),
        ]);
    }

    private function makeConfirmedBooking(string $tenantId, string $date, string $eventType): Booking
    {
        $code = 'BKG-' . Carbon::now()->format('Ym') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);

        return Booking::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $tenantId,
            'booking_code' => $code,
            'event_date'   => $date,
            'event_type'   => $eventType,
            'status'       => BookingStatus::CONFIRMED->value,
        ]);
    }

    private function makeDraftBooking(string $tenantId, string $date, string $eventType): Booking
    {
        $booking = $this->repo->create([
            'tenant_id'       => $tenantId,
            'conversation_id' => $this->conversation->id,
            'event_date'      => $date,
            'event_type'      => $eventType,
        ]);

        return $booking;
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
            'name'      => 'Test WA',
            'status'    => 'connected',
        ]);
    }

    private function makeConversation(string $tenantId, string $waAccountId): Conversation
    {
        return Conversation::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $tenantId,
            'wa_account_id'  => $waAccountId,
            'customer_phone' => '+628111222333',
            'stage'          => ConversationStage::CONSIDERATION->value,
            'agent_mode'     => AgentMode::ACTIVE->value,
            'memory_mode'    => MemoryMode::ACTIVE->value,
        ]);
    }
}
