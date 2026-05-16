<?php

namespace Tests\Feature\Pipeline;

use App\Modules\AgentCore\Classification\Services\IntentClassifierService;
use App\Modules\AgentCore\Composer\Services\ResponseComposerService;
use App\Modules\AgentCore\Decision\Services\DecisionEngineService;
use App\Modules\AgentCore\Extraction\Services\EntityExtractionService;
use App\Modules\AgentCore\LLM\Adapters\MockLlmAdapter;
use App\Modules\AgentCore\LLM\Services\PromptVersioningService;
use App\Modules\AgentCore\LLM\Services\TokenUsageLogger;
use App\Modules\AgentCore\Pipeline\Services\ActionDispatcher;
use App\Modules\AgentCore\Pipeline\Services\DecisionTraceLogger;
use App\Modules\AgentCore\Pipeline\Services\TurnPipelineService;
use App\Modules\AgentCore\Security\Services\InputSanitizerService;
use App\Modules\AgentCore\Validators\ValidatorChainService;
use App\Modules\Auth\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Repositories\BookingRepository;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Knowledge\Services\PackageResolver;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Shared\Contracts\KnowledgeRetrieverInterface;
use App\Modules\Shared\Contracts\LlmClientInterface;
use App\Modules\Shared\DTOs\InboundMessageDTO;
use App\Modules\Shared\DTOs\TurnResultDTO;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\TenantConfig\Services\BusinessHoursService;
use App\Modules\TenantConfig\Services\TenantConfigResolver;
use App\Modules\WhatsApp\Models\WaAccount;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingFlowTest extends TestCase
{
    use RefreshDatabase;

    private MockLlmAdapter $mock;
    private TurnPipelineService $pipeline;
    private ConversationRepository $convRepo;
    private BookingRepository $bookingRepo;
    private Tenant $tenant;
    private string $tenantId;
    private WaAccount $waAccount;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-15 03:00:00', 'UTC'));
        config(['cache.default' => 'array']);
        Cache::flush();

        $this->mock = new MockLlmAdapter();
        app()->instance(LlmClientInterface::class, $this->mock);

        $this->convRepo    = new ConversationRepository();
        $this->bookingRepo = app(BookingRepository::class);

        $superadmin      = $this->makeSuperadmin();
        $this->tenant    = $this->makeTenant($superadmin);
        $this->tenantId  = $this->tenant->id;
        $this->waAccount = $this->makeWaAccount($this->tenantId);

        $this->pipeline = $this->makePipeline();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ────────────────── tests ──────────────────

    public function test_booking_create_draft_via_pipeline(): void
    {
        $result = $this->runScenario(
            message:  'saya mau booking tanggal 1 september 2026 kak',
            intent:   'request_booking',
            entities: ['event_date' => '2026-09-01', 'event_type' => 'resepsi'],
        );

        $this->assertNotEmpty($result->conversation_id);

        // Booking must be created as DRAFT
        $booking = Booking::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->first();

        $this->assertNotNull($booking, 'Booking should have been created');
        $this->assertSame(BookingStatus::DRAFT, $booking->status);
        $this->assertSame('2026-09-01', $booking->event_date->toDateString());

        // Conversation stage must be WAITING_BOOKING
        $conversation = Conversation::withoutGlobalScopes()
            ->find($result->conversation_id);

        $this->assertSame(ConversationStage::WAITING_BOOKING, $conversation->stage);
    }

    public function test_booking_without_event_date_does_not_create_booking(): void
    {
        $result = $this->runScenario(
            message:  'mau booking kak',
            intent:   'request_booking',
            entities: [], // no event_date
        );

        $bookingCount = Booking::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->count();

        $this->assertSame(0, $bookingCount, 'No booking should be created without event_date');
    }

    public function test_confirm_booking_intent_with_event_date_creates_booking(): void
    {
        $result = $this->runScenario(
            message:  'oke kak saya konfirmasi booking tanggal 15 september 2026',
            intent:   'confirm_booking',
            entities: ['event_date' => '2026-09-15', 'event_type' => 'akad'],
        );

        $booking = Booking::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->first();

        $this->assertNotNull($booking);
        $this->assertSame(BookingStatus::DRAFT, $booking->status);
        $this->assertSame('2026-09-15', $booking->event_date->toDateString());
    }

    public function test_duplicate_booking_same_date_does_not_create_second_booking(): void
    {
        // Pre-existing confirmed booking
        Booking::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->tenantId,
            'booking_code' => 'BKG-202605-0001',
            'event_date'   => '2026-09-01',
            'event_type'   => 'resepsi',
            'status'       => BookingStatus::CONFIRMED->value,
        ]);

        $this->runScenario(
            message:  'saya mau booking 1 september 2026',
            intent:   'request_booking',
            entities: ['event_date' => '2026-09-01', 'event_type' => 'resepsi'],
        );

        // Only the original CONFIRMED booking should remain
        $count = Booking::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->count();

        $this->assertSame(1, $count, 'Should still be only 1 booking (no new draft created)');
    }

    // ────────────────── helpers ──────────────────

    private function runScenario(
        string $message,
        string $intent,
        array $entities,
    ): TurnResultDTO {
        $this->mock->setNextResponses([
            json_encode(['intent' => $intent, 'confidence' => 0.92, 'reason' => 'mock']),
            json_encode([
                'entities'            => $entities,
                'corrections'         => [],
                'needs_clarification' => [],
                'detected_language'   => 'id',
                'confidence'          => 0.88,
            ]),
            'Baik Kak, kami sedang proses booking 🙏',
        ]);

        return $this->pipeline->process(
            $this->makeInbound($message),
            $this->tenantId,
        );
    }

    private function makeInbound(string $body): InboundMessageDTO
    {
        return InboundMessageDTO::from([
            'wa_account_id'       => $this->waAccount->id,
            'provider_message_id' => 'msg-' . Str::uuid()->toString(),
            'from_phone'          => '+628120000000',
            'message_type'        => 'text',
            'body'                => $body,
            'media_url'           => null,
            'raw_payload'         => [],
            'received_at'         => now()->toISOString(),
        ]);
    }

    private function makePipeline(): TurnPipelineService
    {
        $tokenUsageLogger = $this->createMock(TokenUsageLogger::class);
        $promptVersioning = new PromptVersioningService();
        $bookingService   = new BookingService(
            $this->bookingRepo,
            app(NotificationService::class),
            $this->convRepo,
            app(\App\Modules\Shared\Contracts\CalendarProviderInterface::class),
        );

        return new TurnPipelineService(
            sanitizer:          app(InputSanitizerService::class),
            classifier:         new IntentClassifierService($this->mock, $tokenUsageLogger, $promptVersioning),
            extractor:          new EntityExtractionService($this->mock, $tokenUsageLogger, app(PackageResolver::class), $promptVersioning),
            knowledgeRetriever: app(KnowledgeRetrieverInterface::class),
            decisionEngine:     new DecisionEngineService(
                app(BusinessHoursService::class),
                null, // no PricelistService needed here
            ),
            validatorChain:     app(ValidatorChainService::class),
            composer:           new ResponseComposerService($this->mock, $tokenUsageLogger, null),
            dispatcher:         new ActionDispatcher(
                null,
                $this->convRepo,
                null,
                null,
                $bookingService,
            ),
            traceLogger:        new DecisionTraceLogger(),
            conversations:      $this->convRepo,
            tokenUsageLogger:   $tokenUsageLogger,
            configResolver:     app(TenantConfigResolver::class),
        );
    }

    private function makeSuperadmin(): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'bft-admin-' . Str::random(6) . '@platform.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function makeTenant(User $createdBy): Tenant
    {
        $slug = 'bft-' . Str::random(6);

        return Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Booking Flow Test Vendor',
            'slug'          => $slug,
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => $slug . '@example.com',
            'created_by_id' => $createdBy->id,
        ]);
    }

    private function makeWaAccount(string $tenantId): WaAccount
    {
        return WaAccount::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'name'      => 'Test WA Account',
            'status'    => 'connected',
        ]);
    }
}
