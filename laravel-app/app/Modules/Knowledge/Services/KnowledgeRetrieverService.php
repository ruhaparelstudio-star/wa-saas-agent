<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Shared\Contracts\KnowledgeRetrieverInterface;
use App\Modules\Shared\DTOs\GroundedKnowledgeDTO;
use App\Modules\Shared\DTOs\GroundingRefDTO;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class KnowledgeRetrieverService implements KnowledgeRetrieverInterface
{
    public function __construct(
        private readonly PackageResolver $packageResolver,
        private readonly PriceResolver $priceResolver,
        private readonly KnowledgeService $knowledgeService,
    ) {}

    public function retrieve(string $intent, array $entities, string $tenantId): GroundedKnowledgeDTO
    {
        $structuredData = [];
        $groundingRefs  = [];

        // Always fetch active packages
        $packages = $this->packageResolver->getActivePackages($tenantId);

        // Normalize to plain arrays so formatGroundingData can access 'price' reliably
        $structuredData['packages'] = $packages->map(fn ($pkg) => [
            'id'          => $pkg->id,
            'name'        => $pkg->name,
            'slug'        => $pkg->slug,
            'description' => $pkg->description,
            'price'       => $pkg->activePrices->sortBy('price_idr')->first()?->price_idr,
        ])->values()->all();

        foreach ($packages as $pkg) {
            $groundingRefs[] = new GroundingRefDTO(
                type: 'structured',
                source: 'packages',
                id: $pkg->id,
                key_data: $pkg->name,
            );
        }

        // Price range if intent is price-related
        if (str_contains($intent, 'price') || str_contains($intent, 'harga')) {
            $priceRange = $this->priceResolver->getPriceRange($tenantId);
            $structuredData['price_range'] = [
                'min'  => $priceRange['min'],
                'max'  => $priceRange['max'],
                'date' => $priceRange['date']->toDateString(),
            ];
        }

        // Matched package if entity package_interest present
        if (!empty($entities['package_interest'])) {
            $matched = $this->packageResolver->matchByName($tenantId, $entities['package_interest']);

            $structuredData['matched_package'] = $matched ? [
                'id'          => $matched->id,
                'name'        => $matched->name,
                'slug'        => $matched->slug,
                'description' => $matched->description,
                'price'       => $matched->activePrices->sortBy('price_idr')->first()?->price_idr,
            ] : null;

            if ($matched) {
                $groundingRefs[] = new GroundingRefDTO(
                    type: 'structured',
                    source: 'packages',
                    id: $matched->id,
                    key_data: $matched->name,
                );
            }
        }

        // Availability check — direct DB query so LLM gets a definitive answer
        // before composing the reply (avoids "kami akan cek" non-answers).
        if ($intent === 'ask_availability' && !empty($entities['event_date'])) {
            try {
                $dateStr   = Carbon::parse($entities['event_date'])->toDateString();
                $eventType = $entities['event_type'] ?? null;

                $query = DB::table('bookings')
                    ->where('tenant_id', $tenantId)
                    ->where('event_date', $dateStr)
                    ->whereIn('status', ['confirmed', 'awaiting_dp', 'paid']);

                if ($eventType) {
                    $query->where('event_type', $eventType);
                }

                $isAvailable = !$query->exists();

                $structuredData['availability'] = [
                    'date'         => $dateStr,
                    'is_available' => $isAvailable,
                    'event_type'   => $eventType,
                ];
            } catch (Throwable) {
                // Non-fatal — LLM will respond without availability data
            }
        }

        // FAQ search from entity values
        $searchQuery    = implode(' ', array_filter(array_values($entities)));
        $faqs           = $this->knowledgeService->searchFaqs($tenantId, $searchQuery);
        $structuredData['faqs'] = $faqs->values()->all();

        $faqRefs       = $this->knowledgeService->getFaqAsGroundingRefs($faqs);
        $groundingRefs = array_merge($groundingRefs, $faqRefs);

        return GroundedKnowledgeDTO::from([
            'structured_data' => $structuredData,
            'vector_results'  => [], // Phase 3
            'grounding_refs'  => $groundingRefs,
            'search_method'   => 'tsvector',
        ]);
    }
}
