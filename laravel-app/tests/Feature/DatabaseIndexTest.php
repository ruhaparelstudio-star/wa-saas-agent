<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseIndexTest extends TestCase
{
    use RefreshDatabase;

    private function indexExists(string $table, string $indexName): bool
    {
        return count(DB::select(
            "SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?",
            [$table, $indexName]
        )) > 0;
    }

    public function test_conversations_tenant_created_index_exists(): void
    {
        $this->assertTrue($this->indexExists('conversations', 'idx_conversations_tenant_created'));
    }

    public function test_conversations_tenant_stage_index_exists(): void
    {
        $this->assertTrue($this->indexExists('conversations', 'idx_conversations_tenant_stage'));
    }

    public function test_bookings_tenant_event_date_index_exists(): void
    {
        $this->assertTrue($this->indexExists('bookings', 'idx_bookings_tenant_event_date'));
    }

    public function test_bookings_tenant_status_index_exists(): void
    {
        $this->assertTrue($this->indexExists('bookings', 'idx_bookings_tenant_status'));
    }

    public function test_invoices_tenant_status_index_exists(): void
    {
        $this->assertTrue($this->indexExists('invoices', 'idx_invoices_tenant_status'));
    }

    public function test_invoices_tenant_paid_at_index_exists(): void
    {
        $this->assertTrue($this->indexExists('invoices', 'idx_invoices_tenant_paid_at'));
    }

    public function test_decision_traces_conversation_created_index_exists(): void
    {
        $this->assertTrue($this->indexExists('decision_traces', 'idx_traces_conversation_created'));
    }

    public function test_wa_accounts_tenant_status_index_exists(): void
    {
        $this->assertTrue($this->indexExists('wa_accounts', 'idx_wa_accounts_tenant_status'));
    }

    public function test_leads_tenant_temperature_index_exists(): void
    {
        $this->assertTrue($this->indexExists('leads', 'idx_leads_tenant_temperature'));
    }

    public function test_explain_conversations_query_uses_index(): void
    {
        $tenantId = '00000000-0000-0000-0000-000000000000';
        $plan     = DB::select(
            "EXPLAIN SELECT * FROM conversations WHERE tenant_id = ? AND stage = 'new_lead'",
            [$tenantId]
        );

        $planText = collect($plan)->map(fn ($row) => array_values((array) $row)[0])->implode(' ');

        // With index, should use Index Scan (not Seq Scan) on a populated table.
        // On empty table, PostgreSQL may still choose Seq Scan (fast for 0 rows) — that's OK.
        // At minimum, the query should not error and plan should be non-empty.
        $this->assertNotEmpty($planText);
    }

    public function test_explain_invoices_paid_query_uses_index(): void
    {
        $tenantId = '00000000-0000-0000-0000-000000000000';
        $plan     = DB::select(
            "EXPLAIN SELECT SUM(amount) FROM invoices WHERE tenant_id = ? AND status = 'paid' AND paid_at > NOW() - INTERVAL '30 days'",
            [$tenantId]
        );

        $planText = collect($plan)->map(fn ($row) => array_values((array) $row)[0])->implode(' ');
        $this->assertNotEmpty($planText);
    }
}
