<?php

namespace App\Modules\AgentCore\Tests;

use App\Modules\AgentCore\Extraction\Services\EntityExtractionService;
use App\Modules\AgentCore\LLM\Adapters\MockLlmAdapter;
use App\Modules\AgentCore\LLM\Services\TokenUsageLogger;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Knowledge\Services\PackageResolver;
use App\Modules\Shared\Contracts\LlmClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntityExtractionServiceTest extends TestCase
{
    use RefreshDatabase;

    private MockLlmAdapter $mock;
    private EntityExtractionService $extractor;
    private PackageResolver $mockResolver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);

        $this->mock = new MockLlmAdapter();
        $this->app->instance(LlmClientInterface::class, $this->mock);

        // Default: resolver returns null (no match)
        $this->mockResolver = $this->createMock(PackageResolver::class);
        $this->mockResolver->method('matchByName')->willReturn(null);

        $this->extractor = $this->makeExtractor($this->mockResolver);
    }

    // ──────────────────────────────────────────────────────────────
    // Basic extraction
    // ──────────────────────────────────────────────────────────────

    public function test_extract_returns_entity_result_dto(): void
    {
        $this->mock->setNextResponse(json_encode([
            'entities' => [
                'customer_name' => 'Budi',
                'event_date'    => '2026-06-15',
                'location'      => 'Jakarta',
            ],
            'corrections'         => [],
            'needs_clarification' => [],
            'detected_language'   => 'id',
            'confidence'          => 0.92,
        ]));

        $result = $this->extractor->extract(
            'nama saya Budi nikah tanggal 15 Juni 2026 di Jakarta',
            'tenant-1'
        );

        $this->assertSame('Budi', $result->entities['customer_name']);
        $this->assertSame('2026-06-15', $result->entities['event_date']);
        $this->assertSame('Jakarta', $result->entities['location']);
        $this->assertSame('id', $result->detected_language);
        $this->assertEqualsWithDelta(0.92, $result->confidence, 0.001);
    }

    public function test_call_count_is_one_per_extract(): void
    {
        $this->mock->setNextResponse(json_encode([
            'entities'            => [],
            'corrections'         => [],
            'needs_clarification' => [],
            'detected_language'   => 'id',
            'confidence'          => 0.5,
        ]));

        $this->extractor->extract('halo kak', 'tenant-1');

        $this->assertSame(1, $this->mock->getCallCount());
    }

    // ──────────────────────────────────────────────────────────────
    // Budget normalization (direct method tests)
    // ──────────────────────────────────────────────────────────────

    public function test_normalize_budget_juta(): void
    {
        $this->assertSame(30_000_000, $this->extractor->normalizeBudget('30 juta'));
    }

    public function test_normalize_budget_rupiah_format(): void
    {
        $this->assertSame(15_000_000, $this->extractor->normalizeBudget('Rp 15.000.000'));
    }

    public function test_normalize_budget_approximate_jt(): void
    {
        $this->assertSame(30_000_000, $this->extractor->normalizeBudget('30an jt'));
    }

    public function test_normalize_budget_ribu(): void
    {
        $this->assertSame(15_000, $this->extractor->normalizeBudget('15rb'));
    }

    public function test_normalize_budget_returns_null_for_unparseable(): void
    {
        $this->assertNull($this->extractor->normalizeBudget('mepet'));
    }

    // ──────────────────────────────────────────────────────────────
    // Date normalization
    // ──────────────────────────────────────────────────────────────

    public function test_normalize_date_iso_passthrough(): void
    {
        $this->assertSame('2026-06-15', $this->extractor->normalizeDate('2026-06-15'));
    }

    public function test_normalize_date_indonesian_month(): void
    {
        $result = $this->extractor->normalizeDate('15 Juni 2026');

        $this->assertSame('2026-06-15', $result);
    }

    public function test_normalize_date_ambiguous_returns_null(): void
    {
        // "minggu depan" is not parseable by Carbon → null
        $this->assertNull($this->extractor->normalizeDate('minggu depan'));
    }

    // ──────────────────────────────────────────────────────────────
    // Entity merge
    // ──────────────────────────────────────────────────────────────

    public function test_existing_entities_preserved_when_not_in_new_response(): void
    {
        $existing = ['customer_name' => 'Budi', 'event_date' => '2026-06-15'];

        $this->mock->setNextResponse(json_encode([
            'entities'            => ['budget_min' => 20_000_000, 'budget_max' => 30_000_000],
            'corrections'         => [],
            'needs_clarification' => [],
            'detected_language'   => 'id',
            'confidence'          => 0.85,
        ]));

        $result = $this->extractor->extract('budget 20-30 juta', 'tenant-1', $existing);

        $this->assertSame('Budi', $result->entities['customer_name']);
        $this->assertSame('2026-06-15', $result->entities['event_date']);
        $this->assertSame(20_000_000, $result->entities['budget_min']);
    }

    public function test_new_entity_overrides_existing_when_non_null(): void
    {
        $existing = ['event_date' => '2026-06-15'];

        $this->mock->setNextResponse(json_encode([
            'entities'            => ['event_date' => '2026-07-10'],
            'corrections'         => ['event_date'],
            'needs_clarification' => [],
            'detected_language'   => 'id',
            'confidence'          => 0.9,
        ]));

        $result = $this->extractor->extract('eh maaf, bukan juni, juli maksud saya', 'tenant-1', $existing);

        $this->assertSame('2026-07-10', $result->entities['event_date']);
        $this->assertContains('event_date', $result->corrections);
    }

    public function test_double_extract_accumulates_entities(): void
    {
        // Turn 1: customer_name
        $this->mock->setNextResponse(json_encode([
            'entities'            => ['customer_name' => 'Budi'],
            'corrections'         => [],
            'needs_clarification' => [],
            'detected_language'   => 'id',
            'confidence'          => 0.95,
        ]));

        $turn1 = $this->extractor->extract('nama saya Budi', 'tenant-1');
        $this->assertSame('Budi', $turn1->entities['customer_name']);

        // Turn 2: event_date — pass turn1 entities as existing
        $this->mock->setNextResponse(json_encode([
            'entities'            => ['event_date' => '2026-06-15'],
            'corrections'         => [],
            'needs_clarification' => [],
            'detected_language'   => 'id',
            'confidence'          => 0.93,
        ]));

        $turn2 = $this->extractor->extract(
            'nikah 15 juni 2026',
            'tenant-1',
            $turn1->entities
        );

        $this->assertSame('Budi', $turn2->entities['customer_name']);
        $this->assertSame('2026-06-15', $turn2->entities['event_date']);
    }

    // ──────────────────────────────────────────────────────────────
    // Package slug resolution
    // ──────────────────────────────────────────────────────────────

    public function test_package_interest_resolves_to_package_slug(): void
    {
        $fakePackage = new Package();
        $fakePackage->forceFill(['slug' => 'standard', 'name' => 'Paket Standard', 'is_active' => true]);

        $resolver = $this->createMock(PackageResolver::class);
        $resolver->expects($this->once())
            ->method('matchByName')
            ->with('tenant-1', 'foto standard')
            ->willReturn($fakePackage);

        $extractor = $this->makeExtractor($resolver);

        $this->mock->setNextResponse(json_encode([
            'entities'            => ['package_interest' => 'foto standard'],
            'corrections'         => [],
            'needs_clarification' => [],
            'detected_language'   => 'id',
            'confidence'          => 0.88,
        ]));

        $result = $extractor->extract('mau paket foto standard', 'tenant-1');

        $this->assertSame('foto standard', $result->entities['package_interest']);
        $this->assertSame('standard', $result->entities['package_slug']);
    }

    public function test_no_package_slug_when_match_returns_null(): void
    {
        $this->mock->setNextResponse(json_encode([
            'entities'            => ['package_interest' => 'paket platinum eksklusif'],
            'corrections'         => [],
            'needs_clarification' => [],
            'detected_language'   => 'id',
            'confidence'          => 0.7,
        ]));

        $result = $this->extractor->extract('mau paket platinum eksklusif', 'tenant-1');

        $this->assertArrayNotHasKey('package_slug', $result->entities);
    }

    // ──────────────────────────────────────────────────────────────
    // Detected language
    // ──────────────────────────────────────────────────────────────

    public function test_detected_language_id_is_set(): void
    {
        $this->mock->setNextResponse(json_encode([
            'entities'            => [],
            'corrections'         => [],
            'needs_clarification' => [],
            'detected_language'   => 'id',
            'confidence'          => 0.8,
        ]));

        $result = $this->extractor->extract('halo kak', 'tenant-1');

        $this->assertSame('id', $result->detected_language);
    }

    public function test_detected_language_en_is_preserved(): void
    {
        $this->mock->setNextResponse(json_encode([
            'entities'            => [],
            'corrections'         => [],
            'needs_clarification' => [],
            'detected_language'   => 'en',
            'confidence'          => 0.9,
        ]));

        $result = $this->extractor->extract('how much is the wedding package?', 'tenant-1');

        $this->assertSame('en', $result->detected_language);
    }

    // ──────────────────────────────────────────────────────────────
    // Needs clarification & corrections
    // ──────────────────────────────────────────────────────────────

    public function test_needs_clarification_preserved_from_llm(): void
    {
        $this->mock->setNextResponse(json_encode([
            'entities'            => ['event_date' => null],
            'corrections'         => [],
            'needs_clarification' => ['event_date'],
            'detected_language'   => 'id',
            'confidence'          => 0.6,
        ]));

        $result = $this->extractor->extract('mau nikah bulan april', 'tenant-1');

        $this->assertContains('event_date', $result->needs_clarification);
    }

    public function test_corrections_preserved_from_llm(): void
    {
        $this->mock->setNextResponse(json_encode([
            'entities'            => ['event_date' => '2026-07-20'],
            'corrections'         => ['event_date'],
            'needs_clarification' => [],
            'detected_language'   => 'id',
            'confidence'          => 0.9,
        ]));

        $result = $this->extractor->extract('bukan juni, juli maksud saya', 'tenant-1');

        $this->assertContains('event_date', $result->corrections);
        $this->assertSame('2026-07-20', $result->entities['event_date']);
    }

    // ──────────────────────────────────────────────────────────────
    // Prompt content verification
    // ──────────────────────────────────────────────────────────────

    public function test_prompt_contains_the_message(): void
    {
        $this->mock->setNextResponse(json_encode([
            'entities'            => [],
            'corrections'         => [],
            'needs_clarification' => [],
            'detected_language'   => 'id',
            'confidence'          => 0.5,
        ]));

        $message = 'nama saya Rina tanggal 20 april';
        $this->extractor->extract($message, 'tenant-1');

        $this->assertStringContainsString($message, $this->mock->getLastPrompt());
    }

    public function test_prompt_contains_existing_entities(): void
    {
        $this->mock->setNextResponse(json_encode([
            'entities'            => ['customer_name' => 'Budi'],
            'corrections'         => [],
            'needs_clarification' => [],
            'detected_language'   => 'id',
            'confidence'          => 0.9,
        ]));

        $this->extractor->extract('halo', 'tenant-1', ['customer_name' => 'Budi']);

        $this->assertStringContainsString('Budi', $this->mock->getLastPrompt());
    }

    public function test_prompt_contains_context(): void
    {
        $this->mock->setNextResponse(json_encode([
            'entities'            => [],
            'corrections'         => [],
            'needs_clarification' => [],
            'detected_language'   => 'id',
            'confidence'          => 0.5,
        ]));

        $context = [
            ['direction' => 'in',  'body' => 'halo kak'],
            ['direction' => 'out', 'body' => 'Halo Kak! Ada yang bisa dibantu?'],
        ];

        $this->extractor->extract('mau tanya paket', 'tenant-1', [], $context);

        $this->assertStringContainsString('halo kak', $this->mock->getLastPrompt());
    }

    // ──────────────────────────────────────────────────────────────
    // Token usage logger
    // ──────────────────────────────────────────────────────────────

    public function test_token_usage_logger_called_once_after_extract(): void
    {
        $this->mock->setNextResponse(json_encode([
            'entities'            => [],
            'corrections'         => [],
            'needs_clarification' => [],
            'detected_language'   => 'id',
            'confidence'          => 0.5,
        ]));

        $loggerMock = $this->getMockBuilder(TokenUsageLogger::class)
            ->disableOriginalConstructor()
            ->getMock();

        $loggerMock->expects($this->once())
            ->method('log')
            ->with('tenant-1', 'entity_extract', $this->anything());

        $extractor = new EntityExtractionService(
            $this->mock,
            $loggerMock,
            $this->mockResolver,
        );

        $extractor->extract('halo', 'tenant-1');
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function makeExtractor(PackageResolver $resolver): EntityExtractionService
    {
        return new EntityExtractionService(
            $this->mock,
            $this->app->make(TokenUsageLogger::class),
            $resolver,
        );
    }
}
