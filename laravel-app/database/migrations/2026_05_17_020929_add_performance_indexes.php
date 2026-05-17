<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Conversations — analytics + follow-up queries
        Schema::table('conversations', function (Blueprint $table) {
            if (!$this->indexExists('conversations', 'idx_conversations_tenant_created')) {
                $table->index(['tenant_id', 'created_at'], 'idx_conversations_tenant_created');
            }
            if (!$this->indexExists('conversations', 'idx_conversations_tenant_stage')) {
                $table->index(['tenant_id', 'stage'], 'idx_conversations_tenant_stage');
            }
            if (!$this->indexExists('conversations', 'idx_conversations_tenant_stage_created')) {
                $table->index(['tenant_id', 'stage', 'created_at'], 'idx_conversations_tenant_stage_created');
            }
            if (!$this->indexExists('conversations', 'idx_conversations_last_message')) {
                $table->index('last_message_at', 'idx_conversations_last_message');
            }
        });

        // Bookings — availability check + analytics
        Schema::table('bookings', function (Blueprint $table) {
            if (!$this->indexExists('bookings', 'idx_bookings_tenant_event_date')) {
                $table->index(['tenant_id', 'event_date'], 'idx_bookings_tenant_event_date');
            }
            if (!$this->indexExists('bookings', 'idx_bookings_tenant_status')) {
                $table->index(['tenant_id', 'status'], 'idx_bookings_tenant_status');
            }
            if (!$this->indexExists('bookings', 'idx_bookings_tenant_created')) {
                $table->index(['tenant_id', 'created_at'], 'idx_bookings_tenant_created');
            }
            if (!$this->indexExists('bookings', 'idx_bookings_conversation')) {
                $table->index('conversation_id', 'idx_bookings_conversation');
            }
        });

        // Invoices — revenue analytics + due-date checks
        Schema::table('invoices', function (Blueprint $table) {
            if (!$this->indexExists('invoices', 'idx_invoices_tenant_status')) {
                $table->index(['tenant_id', 'status'], 'idx_invoices_tenant_status');
            }
            if (!$this->indexExists('invoices', 'idx_invoices_tenant_paid_at')) {
                $table->index(['tenant_id', 'paid_at'], 'idx_invoices_tenant_paid_at');
            }
            if (!$this->indexExists('invoices', 'idx_invoices_tenant_due_date')) {
                $table->index(['tenant_id', 'due_date'], 'idx_invoices_tenant_due_date');
            }
            if (!$this->indexExists('invoices', 'idx_invoices_booking')) {
                $table->index('booking_id', 'idx_invoices_booking');
            }
        });

        // Decision traces — conversation lookup
        Schema::table('decision_traces', function (Blueprint $table) {
            if (!$this->indexExists('decision_traces', 'idx_traces_conversation_created')) {
                $table->index(['conversation_id', 'created_at'], 'idx_traces_conversation_created');
            }
        });

        // WA Accounts — tenant active account lookup
        Schema::table('wa_accounts', function (Blueprint $table) {
            if (!$this->indexExists('wa_accounts', 'idx_wa_accounts_tenant_status')) {
                $table->index(['tenant_id', 'status'], 'idx_wa_accounts_tenant_status');
            }
        });

        // Leads — temperature filtering
        Schema::table('leads', function (Blueprint $table) {
            if (!$this->indexExists('leads', 'idx_leads_tenant_temperature')) {
                $table->index(['tenant_id', 'temperature'], 'idx_leads_tenant_temperature');
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $this->dropIfExists($table, 'idx_conversations_tenant_created');
            $this->dropIfExists($table, 'idx_conversations_tenant_stage');
            $this->dropIfExists($table, 'idx_conversations_tenant_stage_created');
            $this->dropIfExists($table, 'idx_conversations_last_message');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $this->dropIfExists($table, 'idx_bookings_tenant_event_date');
            $this->dropIfExists($table, 'idx_bookings_tenant_status');
            $this->dropIfExists($table, 'idx_bookings_tenant_created');
            $this->dropIfExists($table, 'idx_bookings_conversation');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $this->dropIfExists($table, 'idx_invoices_tenant_status');
            $this->dropIfExists($table, 'idx_invoices_tenant_paid_at');
            $this->dropIfExists($table, 'idx_invoices_tenant_due_date');
            $this->dropIfExists($table, 'idx_invoices_booking');
        });

        Schema::table('decision_traces', function (Blueprint $table) {
            $this->dropIfExists($table, 'idx_traces_conversation_created');
        });

        Schema::table('wa_accounts', function (Blueprint $table) {
            $this->dropIfExists($table, 'idx_wa_accounts_tenant_status');
        });

        Schema::table('leads', function (Blueprint $table) {
            $this->dropIfExists($table, 'idx_leads_tenant_temperature');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return count(DB::select(
            "SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?",
            [$table, $indexName]
        )) > 0;
    }

    private function dropIfExists(Blueprint $table, string $indexName): void
    {
        try {
            $table->dropIndex($indexName);
        } catch (\Exception) {
            // index may not exist — safe to ignore
        }
    }
};
