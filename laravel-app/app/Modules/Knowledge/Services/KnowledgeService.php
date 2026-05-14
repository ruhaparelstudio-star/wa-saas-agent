<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Models\Faq;
use App\Modules\Knowledge\Models\KnowledgeItem;
use App\Modules\Shared\DTOs\GroundingRefDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KnowledgeService
{
    public function getFaqsByCategory(string $tenantId, ?string $category = null): Collection
    {
        $query = Faq::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->active();

        if ($category !== null) {
            $query->byCategory($category);
        }

        return $query->get();
    }

    public function searchFaqs(string $tenantId, string $query, int $limit = 5): Collection
    {
        $base = Faq::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true);

        // tsvector search (PostgreSQL only)
        if (DB::getDriverName() === 'pgsql' && !empty(trim($query))) {
            $results = (clone $base)
                ->whereRaw("search_vector @@ plainto_tsquery('simple', ?)", [$query])
                ->orderByRaw("ts_rank(search_vector, plainto_tsquery('simple', ?)) DESC", [$query])
                ->limit($limit)
                ->get();

            if ($results->isNotEmpty()) {
                return $results;
            }
        }

        // LIKE/ILIKE fallback (also primary path for non-pgsql environments)
        if (empty(trim($query))) {
            return $base->orderBy('sort_order')->limit($limit)->get();
        }

        $operator = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

        return $base
            ->where(function ($q) use ($query, $operator) {
                $q->where('question', $operator, "%{$query}%")
                  ->orWhere('answer', $operator, "%{$query}%");
            })
            ->orderBy('sort_order')
            ->limit($limit)
            ->get();
    }

    public function searchKnowledgeItems(string $tenantId, string $query, int $limit = 5): Collection
    {
        $base = KnowledgeItem::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true);

        // tsvector search (PostgreSQL only)
        if (DB::getDriverName() === 'pgsql' && !empty(trim($query))) {
            $results = (clone $base)
                ->whereRaw("search_vector @@ plainto_tsquery('simple', ?)", [$query])
                ->orderByRaw("ts_rank(search_vector, plainto_tsquery('simple', ?)) DESC", [$query])
                ->limit($limit)
                ->get();

            if ($results->isNotEmpty()) {
                return $results;
            }
        }

        // LIKE/ILIKE fallback
        if (empty(trim($query))) {
            return $base->orderBy('id')->limit($limit)->get();
        }

        $operator = DB::getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';

        return $base
            ->where(function ($q) use ($query, $operator) {
                $q->where('title', $operator, "%{$query}%")
                  ->orWhere('content', $operator, "%{$query}%");
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return GroundingRefDTO[]
     */
    public function getFaqAsGroundingRefs(Collection $faqs): array
    {
        return $faqs->map(fn ($faq) => new GroundingRefDTO(
            type: 'structured',
            source: 'faqs',
            id: $faq->id,
            key_data: $faq->question,
        ))->values()->all();
    }
}
