<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_trace_violations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('decision_trace_id');
            $table->uuid('quality_issue_id')->nullable();
            $table->string('code', 50);
            $table->string('severity', 20);
            $table->string('source', 20);
            $table->text('message');
            $table->jsonb('evidence')->default('{}');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('decision_trace_id')->references('id')->on('decision_traces')->cascadeOnDelete();
            $table->foreign('quality_issue_id')->references('id')->on('conversation_quality_issues')->nullOnDelete();

            $table->index(['tenant_id', 'code', 'created_at'], 'ix_dtv_tenant_code_created');
            $table->index('decision_trace_id', 'ix_dtv_trace');
            $table->index(['tenant_id', 'severity', 'created_at'], 'ix_dtv_severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_trace_violations');
    }
};
