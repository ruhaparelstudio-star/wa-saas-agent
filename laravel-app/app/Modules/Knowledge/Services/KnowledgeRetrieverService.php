<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Shared\Contracts\KnowledgeRetrieverInterface;
use App\Modules\Shared\DTOs\GroundedKnowledgeDTO;
use App\Modules\Shared\DTOs\GroundingRefDTO;

/**
 * STUB implementation for Phase 2.
 * Full implementation with tsvector + pgvector ranking is in Phase 3.
 */
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
        $structuredData['packages'] = $packages->values()->all();

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
            $structuredData['matched_package'] = $matched;

            if ($matched) {
                $groundingRefs[] = new GroundingRefDTO(
                    type: 'structured',
                    source: 'packages',
                    id: $matched->id,
                    key_data: $matched->name,
                );
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
