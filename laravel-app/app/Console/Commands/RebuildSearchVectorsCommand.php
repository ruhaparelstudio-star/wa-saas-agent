<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildSearchVectorsCommand extends Command
{
    protected $signature = 'knowledge:rebuild-search-vectors {--tenant= : Tenant ID to rebuild (all tenants if omitted)}';

    protected $description = 'Rebuild tsvector search_vector columns for faqs and knowledge_items';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('tsvector requires PostgreSQL. Current driver: ' . DB::getDriverName());
            return self::FAILURE;
        }

        $tenantId = $this->option('tenant');

        $faqCount = $this->rebuildFaqs($tenantId);
        $itemCount = $this->rebuildKnowledgeItems($tenantId);

        $tenantLabel = $tenantId ? "tenant {$tenantId}" : 'all tenants';
        $this->info("Rebuilt {$faqCount} FAQ vectors and {$itemCount} knowledge item vectors for {$tenantLabel}.");

        return self::SUCCESS;
    }

    private function rebuildFaqs(?string $tenantId): int
    {
        $sql = "UPDATE faqs
                SET search_vector = to_tsvector('simple',
                    coalesce(question, '') || ' ' || coalesce(answer, '')
                )";

        $bindings = [];
        if ($tenantId) {
            $sql .= ' WHERE tenant_id = ?';
            $bindings[] = $tenantId;
        }

        DB::statement($sql, $bindings);

        $countSql = 'SELECT COUNT(*) as cnt FROM faqs' . ($tenantId ? ' WHERE tenant_id = ?' : '');
        $row = DB::selectOne($countSql, $tenantId ? [$tenantId] : []);

        return (int) $row->cnt;
    }

    private function rebuildKnowledgeItems(?string $tenantId): int
    {
        $sql = "UPDATE knowledge_items
                SET search_vector = to_tsvector('simple',
                    coalesce(title, '') || ' ' || coalesce(content, '') || ' ' || coalesce(category, '')
                )";

        $bindings = [];
        if ($tenantId) {
            $sql .= ' WHERE tenant_id = ?';
            $bindings[] = $tenantId;
        }

        DB::statement($sql, $bindings);

        $countSql = 'SELECT COUNT(*) as cnt FROM knowledge_items' . ($tenantId ? ' WHERE tenant_id = ?' : '');
        $row = DB::selectOne($countSql, $tenantId ? [$tenantId] : []);

        return (int) $row->cnt;
    }
}
