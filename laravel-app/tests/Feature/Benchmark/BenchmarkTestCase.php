<?php

namespace Tests\Feature\Benchmark;

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
use App\Modules\Booking\Repositories\BookingRepository;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Knowledge\Services\PackageResolver;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Shared\Contracts\CalendarProviderInterface;
use App\Modules\Shared\Contracts\KnowledgeRetrieverInterface;
use App\Modules\Shared\Contracts\LlmClientInterface;
use App\Modules\Shared\DTOs\InboundMessageDTO;
use App\Modules\Shared\DTOs\TurnResultDTO;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\TenantConfig\Services\BusinessHoursService;
use App\Modules\TenantConfig\Services\TenantConfigResolver;
use App\Modules\WhatsApp\Models\WaAccount;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared base for all 30 benchmark scenarios.
 * Each subclass calls $this->queueTurn() then $this->processTurn() per pipeline turn.
 */
abstract class BenchmarkTestCase extends TestCase
{
    use RefreshDatabase;

    protected MockLlmAdapter $mock;
    protected TurnPipelineService $pipeline;
    protected ConversationRepository $convRepo;
    protected BookingRepository $bookingRepo;
    protected Tenant $tenant;
    protected string $tenantId;
    protected WaAccount $waAccount;
    protected NotificationService $notificationMock;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze to Friday 10:00 WIB = business hours. (May 17 is Sunday — outside hours)
        Carbon::setTestNow(Carbon::parse('2026-05-15 03:00:00', 'UTC'));
        config(['cache.default' => 'array', 'queue.default' => 'sync']);
        Cache::flush();

        $this->mock = new MockLlmAdapter();
        app()->instance(LlmClientInterface::class, $this->mock);

        $this->convRepo    = new ConversationRepository();
        $this->bookingRepo = app(BookingRepository::class);

        $superadmin        = $this->makeSuperadmin();
        $this->tenant      = $this->makeTenant($superadmin);
        $this->tenantId    = $this->tenant->id;
        $this->waAccount   = $this->makeWaAccount($this->tenantId);

        $this->notificationMock = $this->createMock(NotificationService::class);

        $this->pipeline = $this->makePipeline();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Queue mock responses for one pipeline turn (intent + entity + composer).
     */
    protected function queueTurn(string $intent, array $entities = [], string $reply = 'Baik Kak 🙏'): void
    {
        $this->mock->setNextResponses([
            json_encode(['intent' => $intent, 'confidence' => 0.92, 'reason' => 'mock']),
            json_encode([
                'entities'            => $entities,
                'corrections'         => [],
                'needs_clarification' => [],
                'detected_language'   => $entities['detected_language'] ?? 'id',
                'confidence'          => 0.88,
            ]),
            $reply,
        ]);
    }

    /**
     * Process one pipeline turn and return result.
     */
    protected function processTurn(string $body, string $fromPhone = '+628120000000'): TurnResultDTO
    {
        return $this->pipeline->process(
            $this->makeInbound($body, $fromPhone),
            $this->tenantId,
        );
    }

    protected function makeInbound(string $body, string $fromPhone = '+628120000000'): InboundMessageDTO
    {
        return InboundMessageDTO::from([
            'wa_account_id'       => $this->waAccount->id,
            'provider_message_id' => 'bm-' . Str::uuid()->toString(),
            'from_phone'          => $fromPhone,
            'message_type'        => 'text',
            'body'                => $body,
            'media_url'           => null,
            'raw_payload'         => [],
            'received_at'         => now()->toISOString(),
        ]);
    }

    protected function makeInboundWithId(string $body, string $msgId): InboundMessageDTO
    {
        return InboundMessageDTO::from([
            'wa_account_id'       => $this->waAccount->id,
            'provider_message_id' => $msgId,
            'from_phone'          => '+628120000000',
            'message_type'        => 'text',
            'body'                => $body,
            'media_url'           => null,
            'raw_payload'         => [],
            'received_at'         => now()->toISOString(),
        ]);
    }

    protected function makePipeline(): TurnPipelineService
    {
        $tokenUsageLogger = $this->createMock(TokenUsageLogger::class);
        $promptVersioning = new PromptVersioningService();
        $bookingService   = new BookingService(
            $this->bookingRepo,
            app(NotificationService::class),
            $this->convRepo,
            app(CalendarProviderInterface::class),
        );

        return new TurnPipelineService(
            sanitizer:          app(InputSanitizerService::class),
            classifier:         new IntentClassifierService($this->mock, $tokenUsageLogger, $promptVersioning),
            extractor:          new EntityExtractionService($this->mock, $tokenUsageLogger, app(PackageResolver::class), $promptVersioning),
            knowledgeRetriever: app(KnowledgeRetrieverInterface::class),
            decisionEngine:     new DecisionEngineService(app(BusinessHoursService::class)),
            validatorChain:     app(ValidatorChainService::class),
            composer:           new ResponseComposerService($this->mock, $tokenUsageLogger, null),
            dispatcher:         new ActionDispatcher(null, $this->convRepo, null, null, $bookingService),
            traceLogger:          new DecisionTraceLogger(),
            conversations:        $this->convRepo,
            tokenUsageLogger:     $tokenUsageLogger,
            configResolver:       app(TenantConfigResolver::class),
            notificationService:  $this->notificationMock,
            summarizer:           app(\App\Modules\AgentCore\Summarization\Services\ConversationSummarizerService::class),
            qualityGuard:        app(\App\Modules\QualityGuard\Services\ConversationQualityGuard::class),
        );
    }

    protected function makeSuperadmin(): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin BM',
            'email'     => 'bm-sa-' . Str::random(6) . '@platform.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    protected function makeTenant(User $createdBy): Tenant
    {
        return Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'BM Vendor',
            'slug'          => 'bm-' . Str::random(6),
            'status'        => TenantStatus::ACTIVE->value,
            'industry'      => 'wedding',
            'contact_email' => 'bm@vendor.com',
            'created_by_id' => $createdBy->id,
        ]);
    }

    protected function makeWaAccount(string $tenantId): WaAccount
    {
        $wa = WaAccount::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'status'    => WaAccountStatus::CONNECTED->value,
            'metadata'  => [],
        ]);
        $wa->markConnected('+628550001111');
        return $wa;
    }
}
