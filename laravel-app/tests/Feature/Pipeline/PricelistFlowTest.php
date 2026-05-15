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
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Knowledge\Models\Asset;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Knowledge\Models\PackagePrice;
use App\Modules\Knowledge\Services\PackageResolver;
use App\Modules\Knowledge\Services\PricelistService;
use App\Modules\Shared\Contracts\KnowledgeRetrieverInterface;
use App\Modules\Shared\Contracts\LlmClientInterface;
use App\Modules\Shared\DTOs\InboundMessageDTO;
use App\Modules\Shared\DTOs\TurnResultDTO;
use App\Modules\Shared\Enums\AssetType;
use App\Modules\Shared\Enums\PolicyKey;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\TenantConfig\Services\TenantConfigResolver;
use App\Modules\TenantConfig\Services\TenantPolicyService;
use App\Modules\WhatsApp\Adapters\WhatsAppGatewayAdapter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PricelistFlowTest extends TestCase
{
    use RefreshDatabase;

    private MockLlmAdapter $mock;
    private TurnPipelineService $pipeline;
    private ConversationRepository $repo;
    private TenantPolicyService $policyService;
    private Tenant $tenant;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-15 03:00:00', 'UTC'));
        config(['cache.default' => 'array']);
        Cache::flush();

        $this->mock = new MockLlmAdapter();
        app()->instance(LlmClientInterface::class, $this->mock);

        $this->repo          = new ConversationRepository();
        $this->policyService = app(TenantPolicyService::class);

        $superadmin     = $this->makeSuperadmin();
        $this->tenant   = $this->makeTenant($superadmin);
        $this->tenantId = $this->tenant->id;

        $this->seedPackages();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pdf_mode_dispatches_document_to_gateway(): void
    {
        $this->policyService->setPolicy($this->tenantId, PolicyKey::PRICELIST_MODE, 'pdf');

        $asset = $this->makePricelistAsset();

        Http::fake([
            '*/dispatch' => Http::response(['success' => true, 'provider_message_id' => 'msg-1'], 200),
        ]);

        $this->pipeline = $this->makePipelineWithRealAdapter();

        $result = $this->runScenario(
            message:      'kak boleh minta pricelist?',
            intent:       'ask_package_list',
            entities:     [],
            composerText: 'Berikut pricelist kami Kak 🙏',
        );

        $this->assertNotEmpty($result->conversation_id);

        Http::assertSent(function ($request) use ($asset) {
            $body = $request->data();
            return ($body['message_type'] ?? null) === 'document'
                && ($body['media_url'] ?? null) === $asset->file_url;
        });
    }

    public function test_disabled_mode_blocks_send_pricelist_action(): void
    {
        $this->policyService->setPolicy($this->tenantId, PolicyKey::PRICELIST_MODE, 'disabled');

        $this->pipeline = $this->makePipelineWithNullDispatcher();

        $result = $this->runScenario(
            message:      'berapa harga paket?',
            intent:       'ask_price',
            entities:     [],
            composerText: 'Maaf Kak, harga akan kami siapkan custom 🙏',
        );

        $trace = \App\Modules\AgentCore\Pipeline\Models\DecisionTrace::withoutGlobalScopes()
            ->find($result->decision_trace_id);

        $blocked = collect($trace->blocked_actions ?? [])
            ->pluck('action')
            ->toArray();

        $this->assertContains('send_pricelist', $blocked, 'send_pricelist should be in blocked_actions');
        $this->assertContains('send_pricelist', $trace->desired_actions ?? []);
        $this->assertNotContains('send_pricelist', $trace->allowed_actions ?? []);
    }

    public function test_text_mode_injects_pricelist_into_composer_grounding(): void
    {
        $this->policyService->setPolicy($this->tenantId, PolicyKey::PRICELIST_MODE, 'text');

        $this->pipeline = $this->makePipelineWithNullDispatcher();

        $this->runScenario(
            message:      'paket apa aja kak?',
            intent:       'ask_package_list',
            entities:     [],
            composerText: 'Paket Silver 15jt, Paket Gold 25jt Kak 😊',
        );

        $lastPrompt = $this->mock->getLastPrompt();

        $this->assertStringContainsString('PRICELIST', $lastPrompt);
        $this->assertStringContainsString('Silver', $lastPrompt);
        $this->assertStringContainsString('Gold', $lastPrompt);
    }

    // ────────────────── helpers ──────────────────

    private function runScenario(
        string $message,
        string $intent,
        array $entities,
        string $composerText,
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
            $composerText,
        ]);

        return $this->pipeline->process(
            $this->makeInbound($message),
            $this->tenantId,
        );
    }

    private function makeInbound(string $body): InboundMessageDTO
    {
        return InboundMessageDTO::from([
            'wa_account_id'       => 'acc-acc-001',
            'provider_message_id' => 'msg-' . Str::uuid()->toString(),
            'from_phone'          => '+628120000000',
            'message_type'        => 'text',
            'body'                => $body,
            'media_url'           => null,
            'raw_payload'         => [],
            'received_at'         => now()->toISOString(),
        ]);
    }

    private function makePipelineWithRealAdapter(): TurnPipelineService
    {
        $tokenUsageLogger = $this->createMock(TokenUsageLogger::class);
        $promptVersioning = new PromptVersioningService();
        $pricelistService = app(PricelistService::class);

        return new TurnPipelineService(
            sanitizer:          app(InputSanitizerService::class),
            classifier:         new IntentClassifierService($this->mock, $tokenUsageLogger, $promptVersioning),
            extractor:          new EntityExtractionService($this->mock, $tokenUsageLogger, app(PackageResolver::class), $promptVersioning),
            knowledgeRetriever: app(KnowledgeRetrieverInterface::class),
            decisionEngine:     new DecisionEngineService(
                app(\App\Modules\TenantConfig\Services\BusinessHoursService::class),
                app(PricelistService::class),
            ),
            validatorChain:     app(ValidatorChainService::class),
            composer:           new ResponseComposerService($this->mock, $tokenUsageLogger, $pricelistService),
            dispatcher:         new ActionDispatcher(
                new WhatsAppGatewayAdapter(),
                $this->repo,
                null,
                $pricelistService,
            ),
            traceLogger:        new DecisionTraceLogger(),
            conversations:      $this->repo,
            tokenUsageLogger:   $tokenUsageLogger,
            configResolver:     app(TenantConfigResolver::class),
        );
    }

    private function makePipelineWithNullDispatcher(): TurnPipelineService
    {
        $tokenUsageLogger = $this->createMock(TokenUsageLogger::class);
        $promptVersioning = new PromptVersioningService();
        $pricelistService = app(PricelistService::class);

        return new TurnPipelineService(
            sanitizer:          app(InputSanitizerService::class),
            classifier:         new IntentClassifierService($this->mock, $tokenUsageLogger, $promptVersioning),
            extractor:          new EntityExtractionService($this->mock, $tokenUsageLogger, app(PackageResolver::class), $promptVersioning),
            knowledgeRetriever: app(KnowledgeRetrieverInterface::class),
            decisionEngine:     new DecisionEngineService(
                app(\App\Modules\TenantConfig\Services\BusinessHoursService::class),
                app(PricelistService::class),
            ),
            validatorChain:     app(ValidatorChainService::class),
            composer:           new ResponseComposerService($this->mock, $tokenUsageLogger, $pricelistService),
            dispatcher:         new ActionDispatcher(null, $this->repo, null, $pricelistService),
            traceLogger:        new DecisionTraceLogger(),
            conversations:      $this->repo,
            tokenUsageLogger:   $tokenUsageLogger,
            configResolver:     app(TenantConfigResolver::class),
        );
    }

    private function seedPackages(): void
    {
        $silver = Package::create([
            'id'          => Str::uuid()->toString(),
            'tenant_id'   => $this->tenantId,
            'name'        => 'Silver',
            'slug'        => 'silver',
            'description' => 'Paket dasar untuk intimate wedding',
            'is_active'   => true,
            'sort_order'  => 1,
        ]);

        PackagePrice::create([
            'id'          => Str::uuid()->toString(),
            'tenant_id'   => $this->tenantId,
            'package_id'  => $silver->id,
            'label'       => 'Standard',
            'price_idr'   => 15_000_000,
            'valid_from'  => now()->subDay()->toDateString(),
            'valid_until' => null,
            'is_active'   => true,
        ]);

        $gold = Package::create([
            'id'          => Str::uuid()->toString(),
            'tenant_id'   => $this->tenantId,
            'name'        => 'Gold',
            'slug'        => 'gold',
            'description' => 'Paket lengkap dengan dokumentasi premium',
            'is_active'   => true,
            'sort_order'  => 2,
        ]);

        PackagePrice::create([
            'id'          => Str::uuid()->toString(),
            'tenant_id'   => $this->tenantId,
            'package_id'  => $gold->id,
            'label'       => 'Standard',
            'price_idr'   => 25_000_000,
            'valid_from'  => now()->subDay()->toDateString(),
            'valid_until' => null,
            'is_active'   => true,
        ]);
    }

    private function makePricelistAsset(): Asset
    {
        return Asset::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->tenantId,
            'type'         => AssetType::PRICELIST->value,
            'name'         => 'Pricelist 2026',
            'file_path'    => 'assets/pricelist-2026.pdf',
            'file_url'     => 'https://cdn.example.com/pricelist-2026.pdf',
            'mime_type'    => 'application/pdf',
            'file_size_kb' => 1024,
            'is_active'    => true,
        ]);
    }

    private function makeSuperadmin(): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'admin-pl-' . Str::random(6) . '@platform.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function makeTenant(User $createdBy): Tenant
    {
        $slug = 'pl-' . Str::random(6);
        return Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Pricelist Test Vendor',
            'slug'          => $slug,
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => $slug . '@example.com',
            'created_by_id' => $createdBy->id,
        ]);
    }
}
