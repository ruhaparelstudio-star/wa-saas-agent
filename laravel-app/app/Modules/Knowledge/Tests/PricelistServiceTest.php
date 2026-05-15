<?php

namespace App\Modules\Knowledge\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Knowledge\Models\Asset;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Knowledge\Models\PackagePrice;
use App\Modules\Knowledge\Services\PricelistService;
use App\Modules\Shared\DTOs\ConversationDTO;
use App\Modules\Shared\DTOs\ConversationStateDTO;
use App\Modules\Shared\DTOs\EntityResultDTO;
use App\Modules\Shared\DTOs\GroundedKnowledgeDTO;
use App\Modules\Shared\DTOs\InboundMessageDTO;
use App\Modules\Shared\DTOs\IntentResultDTO;
use App\Modules\Shared\DTOs\LeadProfileDTO;
use App\Modules\Shared\DTOs\TenantConfigDTO;
use App\Modules\Shared\DTOs\TenantDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\AssetType;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\LeadTemperature;
use App\Modules\Shared\Enums\MemoryMode;
use App\Modules\Shared\Enums\PolicyKey;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\TenantConfig\Services\TenantPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class PricelistServiceTest extends TestCase
{
    use RefreshDatabase;

    private PricelistService $service;
    private TenantPolicyService $policyService;
    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();

        $this->service       = app(PricelistService::class);
        $this->policyService = app(TenantPolicyService::class);

        $superadmin    = $this->makeSuperadmin();
        $this->tenantA = $this->makeTenant('Vendor A', $superadmin);
        $this->tenantB = $this->makeTenant('Vendor B', $superadmin);
    }

    public function test_can_send_pricelist_returns_false_when_mode_disabled(): void
    {
        $this->policyService->setPolicy($this->tenantA->id, PolicyKey::PRICELIST_MODE, 'disabled');

        $context = $this->makeContext($this->tenantA->id, ConversationStage::QUALIFICATION);
        $result  = $this->service->canSendPricelist($context);

        $this->assertFalse($result['allowed']);
        $this->assertSame('manual_quote_request', $result['fallback']);
        $this->assertNotEmpty($result['reason']);
    }

    public function test_can_send_pricelist_blocks_after_qualification_when_stage_is_new_lead(): void
    {
        $this->policyService->setPolicy($this->tenantA->id, PolicyKey::PRICELIST_MODE, 'text');
        $this->policyService->setPolicy(
            $this->tenantA->id,
            PolicyKey::PRICELIST_MIN_REQUIREMENT,
            'after_qualification'
        );

        $context = $this->makeContext($this->tenantA->id, ConversationStage::NEW_LEAD);
        $result  = $this->service->canSendPricelist($context);

        $this->assertFalse($result['allowed']);
        $this->assertSame('ask_qualifying_questions', $result['fallback']);
    }

    public function test_can_send_pricelist_allows_after_qualification_when_stage_qualification(): void
    {
        $this->policyService->setPolicy($this->tenantA->id, PolicyKey::PRICELIST_MODE, 'text');
        $this->policyService->setPolicy(
            $this->tenantA->id,
            PolicyKey::PRICELIST_MIN_REQUIREMENT,
            'after_qualification'
        );

        $context = $this->makeContext($this->tenantA->id, ConversationStage::QUALIFICATION);
        $result  = $this->service->canSendPricelist($context);

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['fallback']);
    }

    public function test_can_send_pricelist_blocks_after_event_date_when_missing(): void
    {
        $this->policyService->setPolicy($this->tenantA->id, PolicyKey::PRICELIST_MODE, 'text');
        $this->policyService->setPolicy(
            $this->tenantA->id,
            PolicyKey::PRICELIST_MIN_REQUIREMENT,
            'after_event_date'
        );

        $context = $this->makeContext($this->tenantA->id, ConversationStage::EXPLORATION);
        $result  = $this->service->canSendPricelist($context);

        $this->assertFalse($result['allowed']);
        $this->assertSame('ask_event_date', $result['fallback']);
    }

    public function test_can_send_pricelist_allows_after_event_date_when_present(): void
    {
        $this->policyService->setPolicy($this->tenantA->id, PolicyKey::PRICELIST_MODE, 'text');
        $this->policyService->setPolicy(
            $this->tenantA->id,
            PolicyKey::PRICELIST_MIN_REQUIREMENT,
            'after_event_date'
        );

        $context = $this->makeContext(
            $this->tenantA->id,
            ConversationStage::EXPLORATION,
            ['event_date' => '2026-09-01'],
        );
        $result = $this->service->canSendPricelist($context);

        $this->assertTrue($result['allowed']);
    }

    public function test_get_pricelist_asset_returns_active_pricelist(): void
    {
        $older = $this->makeAsset($this->tenantA->id, 'Pricelist 2025', AssetType::PRICELIST, true);
        \DB::table('assets')->where('id', $older->id)->update(['created_at' => now()->subDays(10)]);

        $latest = $this->makeAsset($this->tenantA->id, 'Pricelist 2026', AssetType::PRICELIST, true);
        $this->makeAsset($this->tenantA->id, 'Brochure', AssetType::BROCHURE, true);

        $asset = $this->service->getPricelistAsset($this->tenantA->id);

        $this->assertNotNull($asset);
        $this->assertSame(AssetType::PRICELIST, $asset->type);
        $this->assertSame($latest->id, $asset->id);
    }

    public function test_get_pricelist_asset_skips_inactive(): void
    {
        $this->makeAsset($this->tenantA->id, 'Pricelist Old', AssetType::PRICELIST, false);

        $asset = $this->service->getPricelistAsset($this->tenantA->id);

        $this->assertNull($asset);
    }

    public function test_build_text_pricelist_includes_package_name_and_price(): void
    {
        $silver = $this->makePackage($this->tenantA->id, 'Silver', 'silver');
        $this->makePrice($silver->id, $this->tenantA->id, 15_000_000);

        $gold = $this->makePackage($this->tenantA->id, 'Gold', 'gold');
        $this->makePrice($gold->id, $this->tenantA->id, 25_000_000);

        $text = $this->service->buildTextPricelist($this->tenantA->id);

        $this->assertStringContainsString('Silver', $text);
        $this->assertStringContainsString('Gold', $text);
        $this->assertStringContainsString('15.000.000', $text);
        $this->assertStringContainsString('25.000.000', $text);
    }

    public function test_build_text_pricelist_returns_fallback_when_no_packages(): void
    {
        $text = $this->service->buildTextPricelist($this->tenantA->id);

        $this->assertStringContainsString('belum tersedia', $text);
    }

    public function test_get_mode_returns_unknown_as_text(): void
    {
        // Default policy is 'public' (legacy) — should be treated as text mode
        $this->assertSame(PricelistService::MODE_TEXT, $this->service->getMode($this->tenantA->id));
    }

    public function test_get_mode_returns_pdf_when_set(): void
    {
        $this->policyService->setPolicy($this->tenantA->id, PolicyKey::PRICELIST_MODE, 'pdf');

        $this->assertSame(PricelistService::MODE_PDF, $this->service->getMode($this->tenantA->id));
    }

    public function test_tenant_isolation_pricelist_asset(): void
    {
        $this->makeAsset($this->tenantA->id, 'Pricelist Tenant A', AssetType::PRICELIST);
        $this->makeAsset($this->tenantB->id, 'Pricelist Tenant B', AssetType::PRICELIST);

        $assetA = $this->service->getPricelistAsset($this->tenantA->id);
        $assetB = $this->service->getPricelistAsset($this->tenantB->id);

        $this->assertSame('Pricelist Tenant A', $assetA?->name);
        $this->assertSame('Pricelist Tenant B', $assetB?->name);
        $this->assertNotEquals($assetA?->id, $assetB?->id);
    }

    public function test_tenant_isolation_text_pricelist(): void
    {
        $pkgA = $this->makePackage($this->tenantA->id, 'Paket A', 'paket-a');
        $this->makePrice($pkgA->id, $this->tenantA->id, 10_000_000);

        $pkgB = $this->makePackage($this->tenantB->id, 'Paket B', 'paket-b');
        $this->makePrice($pkgB->id, $this->tenantB->id, 20_000_000);

        $textA = $this->service->buildTextPricelist($this->tenantA->id);

        $this->assertStringContainsString('Paket A', $textA);
        $this->assertStringNotContainsString('Paket B', $textA);
    }

    // ────────────────── helpers ──────────────────

    private function makeContext(
        string $tenantId,
        ConversationStage $stage,
        array $entities = [],
    ): TurnContextDTO {
        return new TurnContextDTO(
            tenant: new TenantDTO(
                id: $tenantId,
                name: 'Tenant',
                slug: 'tenant',
                status: TenantStatus::ACTIVE,
                industry: 'wedding',
                contact_email: 'tenant@example.com',
                contact_phone: null,
                created_at: now()->toIso8601String(),
            ),
            conversation: new ConversationDTO(
                id: Str::uuid()->toString(),
                tenant_id: $tenantId,
                wa_account_id: 'wa-1',
                from_phone: '+628111000001',
                stage: $stage,
                agent_mode: AgentMode::ACTIVE,
                memory_mode: MemoryMode::ACTIVE,
                context_summary: null,
                created_at: now()->toIso8601String(),
                updated_at: now()->toIso8601String(),
            ),
            state: new ConversationStateDTO(
                stage: $stage,
                agent_mode: AgentMode::ACTIVE,
                memory_mode: MemoryMode::ACTIVE,
                lead_temperature: LeadTemperature::WARM,
                entities: $entities,
                turn_count: 1,
                last_intent: 'ask_price',
            ),
            lead: LeadProfileDTO::from([]),
            intent: IntentResultDTO::from([
                'intent'     => 'ask_price',
                'confidence' => 0.9,
                'reason'     => '',
            ]),
            entities: new EntityResultDTO(
                entities: $entities,
                corrections: [],
                previous_references: [],
                confidence: 0.9,
                needs_clarification: [],
                detected_language: 'id',
            ),
            knowledge: GroundedKnowledgeDTO::from([]),
            config: TenantConfigDTO::from(['tenant_id' => $tenantId]),
            inbound_message: InboundMessageDTO::from([
                'wa_account_id' => 'wa-1',
                'from_phone'    => '+628111000001',
                'message_type'  => 'text',
                'body'          => 'kak boleh minta pricelist?',
                'received_at'   => now()->toIso8601String(),
            ]),
            is_sanitized: true,
            injection_detected: false,
        );
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

    private function makeAsset(
        string $tenantId,
        string $name,
        AssetType $type,
        bool $isActive = true,
    ): Asset {
        return Asset::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $tenantId,
            'type'         => $type->value,
            'name'         => $name,
            'file_path'    => 'assets/' . Str::slug($name) . '.pdf',
            'file_url'     => 'https://cdn.example.com/' . Str::slug($name) . '.pdf',
            'mime_type'    => 'application/pdf',
            'file_size_kb' => 512,
            'is_active'    => $isActive,
        ]);
    }

    private function makePackage(
        string $tenantId,
        string $name,
        string $slug,
    ): Package {
        return Package::create([
            'id'         => Str::uuid()->toString(),
            'tenant_id'  => $tenantId,
            'name'       => $name,
            'slug'       => $slug,
            'is_active'  => true,
            'sort_order' => 0,
        ]);
    }

    private function makePrice(
        string $packageId,
        string $tenantId,
        int $priceIdr,
    ): PackagePrice {
        return PackagePrice::create([
            'id'          => Str::uuid()->toString(),
            'tenant_id'   => $tenantId,
            'package_id'  => $packageId,
            'label'       => 'Standard',
            'price_idr'   => $priceIdr,
            'valid_from'  => now()->subDay()->toDateString(),
            'valid_until' => null,
            'is_active'   => true,
        ]);
    }
}
