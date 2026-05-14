<?php

namespace App\Modules\Knowledge\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Knowledge\Models\Asset;
use App\Modules\Knowledge\Models\Faq;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Knowledge\Models\PackagePrice;
use App\Modules\Knowledge\Services\AssetResolver;
use App\Modules\Knowledge\Services\KnowledgeRetrieverService;
use App\Modules\Knowledge\Services\KnowledgeService;
use App\Modules\Shared\Contracts\KnowledgeRetrieverInterface;
use App\Modules\Shared\DTOs\GroundedKnowledgeDTO;
use App\Modules\Shared\Enums\AssetType;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class KnowledgeServiceTest extends TestCase
{
    use RefreshDatabase;

    private KnowledgeService $knowledgeService;
    private AssetResolver $assetResolver;
    private KnowledgeRetrieverService $retriever;
    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();

        $this->knowledgeService = app(KnowledgeService::class);
        $this->assetResolver    = app(AssetResolver::class);
        $this->retriever        = app(KnowledgeRetrieverService::class);

        $superadmin    = $this->makeSuperadmin();
        $this->tenantA = $this->makeTenant('Vendor A', $superadmin);
        $this->tenantB = $this->makeTenant('Vendor B', $superadmin);
    }

    public function test_search_faqs_returns_relevant_results(): void
    {
        $this->makeFaq($this->tenantA->id, 'Berapa harga paket wedding?', 'Mulai dari 8 juta.', 'harga');
        $this->makeFaq($this->tenantA->id, 'Bagaimana cara booking?', 'Transfer DP 30%.', 'booking');

        $results = $this->knowledgeService->searchFaqs($this->tenantA->id, 'harga');

        $this->assertNotEmpty($results);
        $this->assertTrue($results->contains('question', 'Berapa harga paket wedding?'));
    }

    public function test_search_faqs_with_empty_query_returns_top_faqs(): void
    {
        $this->makeFaq($this->tenantA->id, 'FAQ 1', 'Jawaban 1', 'umum');
        $this->makeFaq($this->tenantA->id, 'FAQ 2', 'Jawaban 2', 'umum');

        $results = $this->knowledgeService->searchFaqs($this->tenantA->id, '');

        $this->assertCount(2, $results);
    }

    public function test_search_faqs_tenant_isolation(): void
    {
        $this->makeFaq($this->tenantA->id, 'FAQ Tenant A', 'Jawaban A', 'umum');
        $this->makeFaq($this->tenantB->id, 'FAQ Tenant B', 'Jawaban B', 'umum');

        $resultsA = $this->knowledgeService->searchFaqs($this->tenantA->id, 'FAQ');
        $resultsB = $this->knowledgeService->searchFaqs($this->tenantB->id, 'FAQ');

        $this->assertCount(1, $resultsA);
        $this->assertCount(1, $resultsB);
        $this->assertTrue($resultsA->contains('question', 'FAQ Tenant A'));
        $this->assertTrue($resultsB->contains('question', 'FAQ Tenant B'));
    }

    public function test_get_faqs_by_category(): void
    {
        $this->makeFaq($this->tenantA->id, 'FAQ Harga', 'Jawaban harga', 'harga');
        $this->makeFaq($this->tenantA->id, 'FAQ Booking', 'Jawaban booking', 'booking');

        $results = $this->knowledgeService->getFaqsByCategory($this->tenantA->id, 'harga');

        $this->assertCount(1, $results);
        $this->assertEquals('FAQ Harga', $results->first()->question);
    }

    public function test_get_faqs_by_category_null_returns_all(): void
    {
        $this->makeFaq($this->tenantA->id, 'FAQ Harga', 'Jawaban harga', 'harga');
        $this->makeFaq($this->tenantA->id, 'FAQ Booking', 'Jawaban booking', 'booking');

        $results = $this->knowledgeService->getFaqsByCategory($this->tenantA->id, null);

        $this->assertCount(2, $results);
    }

    public function test_get_faq_as_grounding_refs(): void
    {
        $faq1 = $this->makeFaq($this->tenantA->id, 'Pertanyaan A', 'Jawaban A', 'umum');
        $faq2 = $this->makeFaq($this->tenantA->id, 'Pertanyaan B', 'Jawaban B', 'umum');

        $faqs = $this->knowledgeService->getFaqsByCategory($this->tenantA->id);
        $refs = $this->knowledgeService->getFaqAsGroundingRefs($faqs);

        $this->assertCount(2, $refs);
        $this->assertEquals('structured', $refs[0]->type);
        $this->assertEquals('faqs', $refs[0]->source);
        $this->assertNotEmpty($refs[0]->id);
        $this->assertNotEmpty($refs[0]->key_data);
    }

    public function test_get_active_pricelist_returns_pricelist_asset(): void
    {
        $this->makeAsset($this->tenantA->id, 'Pricelist 2026', AssetType::PRICELIST);
        $this->makeAsset($this->tenantA->id, 'Portofolio', AssetType::PORTFOLIO);

        $asset = $this->assetResolver->getActivePricelist($this->tenantA->id);

        $this->assertNotNull($asset);
        $this->assertEquals(AssetType::PRICELIST, $asset->type);
        $this->assertEquals('Pricelist 2026', $asset->name);
    }

    public function test_get_active_pricelist_returns_null_if_none(): void
    {
        $result = $this->assetResolver->getActivePricelist($this->tenantA->id);

        $this->assertNull($result);
    }

    public function test_get_assets_by_type(): void
    {
        $this->makeAsset($this->tenantA->id, 'Pricelist A', AssetType::PRICELIST);
        $this->makeAsset($this->tenantA->id, 'Pricelist B', AssetType::PRICELIST);
        $this->makeAsset($this->tenantA->id, 'Portfolio', AssetType::PORTFOLIO);

        $pricelists = $this->assetResolver->getAssetsByType($this->tenantA->id, AssetType::PRICELIST);

        $this->assertCount(2, $pricelists);
    }

    public function test_knowledge_retriever_interface_is_bound(): void
    {
        $this->assertTrue(
            app()->bound(KnowledgeRetrieverInterface::class),
            'KnowledgeRetrieverInterface must be bound in the container'
        );
    }

    public function test_retrieve_returns_valid_grounded_knowledge_dto(): void
    {
        $pkg = $this->makePackage($this->tenantA->id, 'Paket Standard', 'standard');
        $this->makeFaq($this->tenantA->id, 'Berapa harga?', 'Mulai 8 juta.', 'harga');

        $result = $this->retriever->retrieve('ask_package_list', [], $this->tenantA->id);

        $this->assertInstanceOf(GroundedKnowledgeDTO::class, $result);
        $this->assertEquals('tsvector', $result->search_method);
        $this->assertArrayHasKey('packages', $result->structured_data);
    }

    public function test_retrieve_grounding_refs_not_empty_when_packages_exist(): void
    {
        $this->makePackage($this->tenantA->id, 'Paket Intimate', 'intimate');
        $this->makeFaq($this->tenantA->id, 'FAQ satu', 'Jawaban satu', 'umum');

        $result = $this->retriever->retrieve('ask_package_list', [], $this->tenantA->id);

        $this->assertNotEmpty($result->grounding_refs);
    }

    public function test_retrieve_with_ask_price_intent_includes_price_range(): void
    {
        $pkg   = $this->makePackage($this->tenantA->id, 'Paket Standard', 'standard');
        $today = Carbon::today()->toDateString();
        $this->makePrice($pkg->id, $this->tenantA->id, 15_000_000, $today, null);

        $result = $this->retriever->retrieve('ask_price', [], $this->tenantA->id);

        $this->assertArrayHasKey('price_range', $result->structured_data);
    }

    public function test_retrieve_with_package_interest_entity_sets_matched_package(): void
    {
        $this->makePackage($this->tenantA->id, 'Paket Standard', 'standard');

        $result = $this->retriever->retrieve(
            'ask_package_detail',
            ['package_interest' => 'Standard'],
            $this->tenantA->id
        );

        $this->assertArrayHasKey('matched_package', $result->structured_data);
        $this->assertNotNull($result->structured_data['matched_package']);
    }

    // ────────────────── helpers ──────────────────

    private function makeSuperadmin(): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'admin@platform.com',
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

    private function makeFaq(
        string $tenantId,
        string $question,
        string $answer,
        string $category,
        int $sortOrder = 0,
    ): Faq {
        return Faq::create([
            'id'         => Str::uuid()->toString(),
            'tenant_id'  => $tenantId,
            'question'   => $question,
            'answer'     => $answer,
            'category'   => $category,
            'is_active'  => true,
            'sort_order' => $sortOrder,
        ]);
    }

    private function makeAsset(
        string $tenantId,
        string $name,
        AssetType $type,
        bool $isActive = true,
    ): Asset {
        return Asset::create([
            'id'          => Str::uuid()->toString(),
            'tenant_id'   => $tenantId,
            'type'        => $type->value,
            'name'        => $name,
            'file_path'   => 'assets/' . Str::slug($name) . '.pdf',
            'mime_type'   => 'application/pdf',
            'file_size_kb' => 512,
            'is_active'   => $isActive,
        ]);
    }

    private function makePackage(
        string $tenantId,
        string $name,
        string $slug,
        bool $isActive = true,
    ): Package {
        return Package::create([
            'id'         => Str::uuid()->toString(),
            'tenant_id'  => $tenantId,
            'name'       => $name,
            'slug'       => $slug,
            'is_active'  => $isActive,
            'sort_order' => 0,
        ]);
    }

    private function makePrice(
        string $packageId,
        string $tenantId,
        int $priceIdr,
        string $validFrom,
        ?string $validUntil,
        bool $isActive = true,
    ): PackagePrice {
        return PackagePrice::create([
            'id'          => Str::uuid()->toString(),
            'tenant_id'   => $tenantId,
            'package_id'  => $packageId,
            'label'       => 'Weekday',
            'price_idr'   => $priceIdr,
            'valid_from'  => $validFrom,
            'valid_until' => $validUntil,
            'is_active'   => $isActive,
        ]);
    }
}
