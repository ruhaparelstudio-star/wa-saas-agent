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
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Knowledge\Models\PackagePrice;
use App\Modules\Knowledge\Services\PackageResolver;
use App\Modules\Knowledge\Services\PricelistService;
use App\Modules\Shared\Contracts\KnowledgeRetrieverInterface;
use App\Modules\Shared\Contracts\LlmClientInterface;
use App\Modules\Shared\DTOs\InboundMessageDTO;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\TenantConfig\Services\TenantConfigResolver;
use App\Modules\TenantConfig\Services\TenantPolicyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reproduces the conversation flow gaps observed in the user-reported POC log:
 *  1. Pricelist must not be sent before the customer name is collected.
 *  2. Pricelist must not be re-listed if it was already sent in the conversation.
 *  3. "Mau booking gimana caranya?" must enter the booking flow with missing-data
 *     guidance — not redirect the customer to phone/email/website.
 *  4. Customer profile (name) must be persisted on the Lead the moment it is given.
 *  5. The composer must see the full transcript on every turn.
 */
class ConversationFlowAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private MockLlmAdapter $mock;
    private TurnPipelineService $pipeline;
    private ConversationRepository $repo;
    private Tenant $tenant;
    private string $tenantId;
    private string $phone;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-18 03:00:00', 'UTC'));
        config(['cache.default' => 'array']);
        Cache::flush();

        $this->mock = new MockLlmAdapter();
        app()->instance(LlmClientInterface::class, $this->mock);

        $this->repo  = new ConversationRepository();
        $this->phone = '+628120000999';

        $superadmin     = $this->makeSuperadmin();
        $this->tenant   = $this->makeTenant($superadmin);
        $this->tenantId = $this->tenant->id;

        $this->seedPackages();

        $this->pipeline = $this->makePipeline();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── 1. Pricelist gated by name ─────────────────────────────────────────

    public function test_pricelist_request_without_name_routes_to_ask_customer_name(): void
    {
        $this->runTurn(
            message: 'boleh minta pricelistnya',
            intent:  'ask_package_list',
            entities: [],
            composerText: 'Sebelumnya boleh tau nama Kakak dulu Kak? Biar saya bisa bantu lebih personal 😊',
        );

        $prompt = $this->mock->getLastPrompt();

        $this->assertStringContainsString('REPLY STRATEGY: ask_customer_name', $prompt);
        $this->assertStringNotContainsString('Silver', $prompt, 'Package names must NOT be in grounding before name is collected');
        $this->assertStringNotContainsString('Gold', $prompt, 'Package names must NOT be in grounding before name is collected');
        $this->assertStringNotContainsString('15.000.000', $prompt, 'Prices must NOT leak into the prompt before name is collected');
        $this->assertStringNotContainsString('PRICELIST (use verbatim', $prompt, 'PRICELIST grounding block must be suppressed until name is known');
        $this->assertStringNotContainsString('PACKAGES:', $prompt, 'Structured packages section must be suppressed until name is known');
    }

    // ── 2. After name + pricelist sent, do not re-list ─────────────────────

    public function test_pricelist_not_repeated_when_already_sent_in_transcript(): void
    {
        // Seed an existing conversation with prior agent message that contained pricelist.
        $conversation = $this->repo->findOrCreateByPhone($this->tenantId, $this->phone, 'acc-test-001');
        $conversation->lead->update(['customer_name' => 'Aris']);

        $conversation->addMessage([
            'direction'    => 'inbound',
            'message_type' => 'text',
            'body'         => 'boleh minta pricelistnya',
        ]);

        $conversation->addMessage([
            'direction'    => 'outbound',
            'message_type' => 'text',
            'body'         => 'Tentu Kak! Paket Silver Rp 15.000.000, Paket Gold Rp 25.000.000.',
        ]);

        // Customer asks again later — LLM is bypassed with a deterministic preset
        // because the live POC showed GPT-4o would re-list packages even when the
        // prompt told it not to. Reliability > naturalness for this case.
        $callsBefore = $this->mock->getCallCount();
        $this->runTurn(
            message: 'boleh minta pricelistnya lagi',
            intent:  'ask_package_list',
            entities: ['customer_name' => 'Aris'],
            composerText: '__SHOULD_NOT_BE_USED__',
        );

        // Only intent + entity extractor LLMs ran — composer was bypassed.
        $this->assertSame($callsBefore + 2, $this->mock->getCallCount(), 'Composer must NOT call LLM when pricelist already sent');

        $latestOutbound = $conversation->fresh()->messages()
            ->where('direction', 'outbound')
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($latestOutbound);
        $this->assertStringContainsString('Tadi sudah saya kirim', $latestOutbound->body);
        $this->assertStringContainsString('Aris', $latestOutbound->body, 'Refer-back reply should use the customer name when known');
        $this->assertDoesNotMatchRegularExpression('/Rp[\s.]?\d/i', $latestOutbound->body, 'Refer-back reply must NOT include any prices');
    }

    // ── 3. Booking flow asks for missing data instead of redirecting ───────

    public function test_ask_booking_routes_to_booking_flow_with_missing_data(): void
    {
        // Customer with name + selected package, no date yet.
        $conversation = $this->repo->findOrCreateByPhone($this->tenantId, $this->phone, 'acc-test-001');
        $conversation->lead->update([
            'customer_name'    => 'Aris',
            'package_interest' => 'Silver',
            'package_slug'     => 'silver',
        ]);
        $conversation->updateEntityCache([
            'customer_name' => 'Aris',
            'package_slug'  => 'silver',
        ]);

        $this->runTurn(
            message: 'aku mau booking gimana caranya?',
            intent:  'ask_booking',
            entities: ['customer_name' => 'Aris', 'package_slug' => 'silver'],
            composerText: 'Siap Kak Aris, untuk booking Paket Silver, tanggal acaranya kapan ya?',
        );

        $prompt = $this->mock->getLastPrompt();

        $this->assertStringContainsString('send_booking_flow', $prompt);
        $this->assertStringContainsString('event_date (tanggal pernikahan)', $prompt);
        $this->assertStringContainsString('Ask for: event_date', $prompt);
        $this->assertStringContainsString('Do NOT redirect them to phone/email/website', $prompt);
    }

    // ── 4. Customer name persisted on Lead the moment it is given ──────────

    public function test_customer_name_is_persisted_on_lead_when_provided(): void
    {
        $this->runTurn(
            message: 'nama saya Aris',
            intent:  'greeting',
            entities: ['customer_name' => 'Aris'],
            composerText: 'Halo Kak Aris! Ada yang bisa kami bantu?',
        );

        $conversation = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('customer_phone', $this->phone)
            ->first();

        $this->assertSame('Aris', $conversation->lead->customer_name);
        $this->assertSame('Aris', $conversation->entity_cache['customer_name'] ?? null);
    }

    // ── 5. Full transcript reaches composer ─────────────────────────────────

    public function test_composer_sees_full_transcript_not_just_last_message(): void
    {
        $conversation = $this->repo->findOrCreateByPhone($this->tenantId, $this->phone, 'acc-test-001');
        // Name already collected — so this turn reaches the LLM-driven composer.
        $conversation->lead->update(['customer_name' => 'Aris']);

        // Inject several past messages
        $conversation->addMessage(['direction' => 'inbound',  'message_type' => 'text', 'body' => 'pagi ka']);
        $conversation->addMessage(['direction' => 'outbound', 'message_type' => 'text', 'body' => 'Pagi Kak Aris! Ada yang bisa kami bantu?']);
        $conversation->addMessage(['direction' => 'inbound',  'message_type' => 'text', 'body' => 'aku mau paket standard']);
        $conversation->addMessage(['direction' => 'outbound', 'message_type' => 'text', 'body' => 'Siap Kak, paket Standard ya.']);

        $this->runTurn(
            message: 'aku mau booking gimana caranya?',
            intent:  'ask_booking',
            entities: ['customer_name' => 'Aris'],
            composerText: 'Siap Kak Aris, tanggal acaranya kapan ya?',
        );

        $prompt = $this->mock->getLastPrompt();

        $this->assertStringContainsString('pagi ka', $prompt);
        $this->assertStringContainsString('aku mau paket standard', $prompt);
        $this->assertStringContainsString('FULL TRANSCRIPT', $prompt);
    }

    // ── 6. Live POC replay: booking intent without name uses preset gate ──

    public function test_booking_intent_without_name_returns_collect_name_preset(): void
    {
        $conversation = $this->repo->findOrCreateByPhone($this->tenantId, $this->phone, 'acc-test-001');
        // Customer has package interest but never gave a name — mirrors the live log.
        $conversation->updateEntityCache(['package_slug' => 'silver', 'package_interest' => 'paket standard']);

        $callsBefore = $this->mock->getCallCount();
        $this->runTurn(
            message: 'aku mau booking gimana caranya?',
            intent:  'ask_booking',
            entities: ['package_slug' => 'silver'],
            composerText: '__SHOULD_NOT_BE_USED__',
        );

        // Only intent + entity extractor LLMs ran — composer was bypassed.
        $this->assertSame($callsBefore + 2, $this->mock->getCallCount(), 'Composer must NOT call LLM when name still missing for booking');

        $latestOutbound = $conversation->fresh()->messages()
            ->where('direction', 'outbound')
            ->orderByDesc('created_at')
            ->first();

        $this->assertNotNull($latestOutbound);
        $this->assertStringContainsString('boleh tau nama', mb_strtolower($latestOutbound->body));
        // The live failure mode: bot redirected to phone/email/website. Must never happen now.
        $this->assertStringNotContainsString('telepon', mb_strtolower($latestOutbound->body));
        $this->assertStringNotContainsString('website', mb_strtolower($latestOutbound->body));
        $this->assertStringNotContainsString('email', mb_strtolower($latestOutbound->body));
    }

    // ── 7. Invalid/legacy pricelist_min_requirement value fails safe ──

    public function test_invalid_pricelist_requirement_policy_value_falls_back_to_require_name(): void
    {
        // Reproduce the live tenant misconfiguration: '0' instead of a valid enum.
        app(TenantPolicyService::class)->setPolicy(
            $this->tenantId,
            \App\Modules\Shared\Enums\PolicyKey::PRICELIST_MIN_REQUIREMENT,
            '0',
        );

        $this->runTurn(
            message: 'boleh minta pricelistnya',
            intent:  'ask_package_list',
            entities: [],
            composerText: 'Sebelumnya boleh tau nama Kakak dulu? 😊',
        );

        $prompt = $this->mock->getLastPrompt();

        // Code must treat the unknown value as require_customer_name, NOT allow=true.
        $this->assertStringContainsString('REPLY STRATEGY: ask_customer_name', $prompt);
        $this->assertStringNotContainsString('PRICELIST (use verbatim', $prompt);
        $this->assertStringNotContainsString('PACKAGES:', $prompt);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function runTurn(string $message, string $intent, array $entities, string $composerText): void
    {
        $this->mock->setNextResponses([
            json_encode(['intent' => $intent, 'confidence' => 0.92, 'reason' => 'mock']),
            json_encode([
                'entities'            => $entities,
                'corrections'         => [],
                'needs_clarification' => [],
                'detected_language'   => 'id',
                'confidence'          => 0.9,
            ]),
            $composerText,
        ]);

        $this->pipeline->process(
            $this->makeInbound($message),
            $this->tenantId,
        );
    }

    private function makeInbound(string $body): InboundMessageDTO
    {
        return InboundMessageDTO::from([
            'wa_account_id'       => 'acc-test-001',
            'provider_message_id' => 'msg-' . Str::uuid()->toString(),
            'from_phone'          => $this->phone,
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
        $pricelistService = app(PricelistService::class);

        return new TurnPipelineService(
            sanitizer:          app(InputSanitizerService::class),
            classifier:         new IntentClassifierService($this->mock, $tokenUsageLogger, $promptVersioning),
            extractor:          new EntityExtractionService($this->mock, $tokenUsageLogger, app(PackageResolver::class), $promptVersioning),
            knowledgeRetriever: app(KnowledgeRetrieverInterface::class),
            decisionEngine:     new DecisionEngineService(
                app(\App\Modules\TenantConfig\Services\BusinessHoursService::class),
                $pricelistService,
            ),
            validatorChain:     app(ValidatorChainService::class),
            composer:           new ResponseComposerService($this->mock, $tokenUsageLogger, $pricelistService),
            dispatcher:         new ActionDispatcher(null, $this->repo, null, $pricelistService),
            traceLogger:        new DecisionTraceLogger(),
            conversations:      $this->repo,
            tokenUsageLogger:   $tokenUsageLogger,
            configResolver:     app(TenantConfigResolver::class),
            notificationService:$this->createMock(\App\Modules\Notification\Services\NotificationService::class),
            summarizer:         app(\App\Modules\AgentCore\Summarization\Services\ConversationSummarizerService::class),
            qualityGuard:        app(\App\Modules\QualityGuard\Services\ConversationQualityGuard::class),
        );
    }

    private function seedPackages(): void
    {
        $silver = Package::create([
            'id'         => Str::uuid()->toString(),
            'tenant_id'  => $this->tenantId,
            'name'       => 'Paket Silver',
            'slug'       => 'silver',
            'description'=> 'Paket intimate wedding',
            'is_active'  => true,
            'sort_order' => 1,
        ]);

        PackagePrice::create([
            'id'         => Str::uuid()->toString(),
            'tenant_id'  => $this->tenantId,
            'package_id' => $silver->id,
            'label'      => 'Standard',
            'price_idr'  => 15_000_000,
            'valid_from' => now()->subDay()->toDateString(),
            'is_active'  => true,
        ]);

        $gold = Package::create([
            'id'         => Str::uuid()->toString(),
            'tenant_id'  => $this->tenantId,
            'name'       => 'Paket Gold',
            'slug'       => 'gold',
            'description'=> 'Coverage premium',
            'is_active'  => true,
            'sort_order' => 2,
        ]);

        PackagePrice::create([
            'id'         => Str::uuid()->toString(),
            'tenant_id'  => $this->tenantId,
            'package_id' => $gold->id,
            'label'      => 'Standard',
            'price_idr'  => 25_000_000,
            'valid_from' => now()->subDay()->toDateString(),
            'is_active'  => true,
        ]);
    }

    private function makeSuperadmin(): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'admin-cf-' . Str::random(6) . '@platform.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function makeTenant(User $createdBy): Tenant
    {
        $slug = 'cf-' . Str::random(6);

        return Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Conversation Flow Vendor',
            'slug'          => $slug,
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => $slug . '@example.com',
            'created_by_id' => $createdBy->id,
        ]);
    }
}
