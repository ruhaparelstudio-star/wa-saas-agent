<?php

namespace App\Modules\Knowledge\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Knowledge\Models\Faq;
use App\Modules\Knowledge\Models\KnowledgeItem;
use App\Modules\Knowledge\Services\KnowledgeService;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TsvectorSearchTest extends TestCase
{
    use RefreshDatabase;

    private KnowledgeService $knowledgeService;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->knowledgeService = app(KnowledgeService::class);
        $superadmin = $this->makeSuperadmin();
        $this->tenant = $this->makeTenant($superadmin);
    }

    public function test_search_finds_faq_by_keyword_in_question(): void
    {
        $this->makeFaq('paket foto outdoor', 'Kami menyediakan paket foto di berbagai lokasi outdoor');
        $this->makeFaq('harga paket intimate', 'Harga mulai dari 8 juta');

        $results = $this->knowledgeService->searchFaqs($this->tenant->id, 'outdoor');

        $this->assertGreaterThanOrEqual(1, $results->count());
        $this->assertTrue($results->contains('question', 'paket foto outdoor'));
    }

    public function test_search_finds_faq_by_keyword_in_answer(): void
    {
        $this->makeFaq('harga pernikahan 2024', 'Harga paket wedding mulai dari 15 juta rupiah');

        $results = $this->knowledgeService->searchFaqs($this->tenant->id, 'harga');

        $this->assertGreaterThanOrEqual(1, $results->count());
        $this->assertTrue($results->contains('question', 'harga pernikahan 2024'));
    }

    public function test_search_returns_empty_collection_for_nonexistent_word(): void
    {
        $this->makeFaq('paket foto outdoor', 'Layanan foto wedding profesional');

        $results = $this->knowledgeService->searchFaqs($this->tenant->id, 'xyznotexistqwerty');

        $this->assertCount(0, $results);
    }

    public function test_search_only_returns_active_faqs(): void
    {
        $this->makeFaq('paket aktif tentang harga', 'Detail harga', true);
        $this->makeFaq('paket nonaktif tentang harga', 'Detail harga nonaktif', false);

        $results = $this->knowledgeService->searchFaqs($this->tenant->id, 'harga');

        $this->assertTrue($results->every(fn ($faq) => $faq->is_active === true));
        $this->assertFalse($results->contains('question', 'paket nonaktif tentang harga'));
    }

    public function test_tenant_isolation_search_faqs(): void
    {
        $other = $this->makeTenant($this->makeSuperadmin('other@platform.com'));
        $this->makeFaqForTenant($this->tenant->id, 'harga paket tenant A', 'Info harga tenant A');
        $this->makeFaqForTenant($other->id, 'harga paket tenant B', 'Info harga tenant B');

        $results = $this->knowledgeService->searchFaqs($this->tenant->id, 'harga');

        $this->assertTrue($results->every(fn ($faq) => $faq->tenant_id === $this->tenant->id));
        $this->assertFalse($results->contains('question', 'harga paket tenant B'));
    }

    public function test_insert_faq_auto_fills_search_vector_on_pgsql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Trigger test requires PostgreSQL');
        }

        $faq = $this->makeFaq('Berapa paket harga foto wedding?', 'Mulai dari 5 juta');

        $row = DB::selectOne('SELECT search_vector FROM faqs WHERE id = ?', [$faq->id]);

        $this->assertNotNull($row->search_vector, 'search_vector harus terisi otomatis via trigger');
    }

    public function test_update_faq_question_updates_search_vector_on_pgsql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Trigger test requires PostgreSQL');
        }

        $faq = $this->makeFaq('paket foto studio', 'Layanan studio indoor');

        $before = $this->knowledgeService->searchFaqs($this->tenant->id, 'outdoor');
        $this->assertFalse($before->contains('id', $faq->id));

        $faq->update(['question' => 'paket foto outdoor terbaru']);

        $after = $this->knowledgeService->searchFaqs($this->tenant->id, 'outdoor');
        $this->assertTrue($after->contains('id', $faq->id));
    }

    public function test_search_knowledge_items_by_title(): void
    {
        KnowledgeItem::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'title'     => 'Syarat dan Ketentuan Booking',
            'content'   => 'Pembayaran DP minimal 30 persen',
            'category'  => 'terms',
            'tags'      => [],
            'is_active' => true,
        ]);

        $results = $this->knowledgeService->searchKnowledgeItems($this->tenant->id, 'booking');

        $this->assertGreaterThanOrEqual(1, $results->count());
        $this->assertTrue($results->contains('title', 'Syarat dan Ketentuan Booking'));
    }

    public function test_search_knowledge_items_returns_empty_for_unknown_word(): void
    {
        KnowledgeItem::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'title'     => 'Syarat Booking',
            'content'   => 'Detail persyaratan',
            'category'  => 'terms',
            'tags'      => [],
            'is_active' => true,
        ]);

        $results = $this->knowledgeService->searchKnowledgeItems($this->tenant->id, 'xyznotexistqwerty');

        $this->assertCount(0, $results);
    }

    private function makeSuperadmin(string $email = 'admin@platform.com'): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => $email,
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function makeTenant(User $createdBy): Tenant
    {
        $slug = 'vendor-' . Str::random(6);
        return Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Vendor ' . $slug,
            'slug'          => $slug,
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => $slug . '@example.com',
            'created_by_id' => $createdBy->id,
        ]);
    }

    private function makeFaq(string $question, string $answer, bool $isActive = true): Faq
    {
        return $this->makeFaqForTenant($this->tenant->id, $question, $answer, $isActive);
    }

    private function makeFaqForTenant(
        string $tenantId,
        string $question,
        string $answer,
        bool $isActive = true,
    ): Faq {
        return Faq::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'question'  => $question,
            'answer'    => $answer,
            'is_active' => $isActive,
            'sort_order' => 0,
        ]);
    }
}
