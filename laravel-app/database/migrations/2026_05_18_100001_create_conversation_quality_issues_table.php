<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_quality_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('conversation_id');
            $table->uuid('decision_trace_id')->nullable();
            $table->string('code', 50);
            $table->string('severity', 20);
            $table->string('source', 20)->default('guard');
            $table->text('message');
            $table->jsonb('evidence')->default('{}');
            $table->boolean('blocked')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->string('resolution_type', 30)->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('decision_trace_id')->references('id')->on('decision_traces')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['decision_trace_id', 'code'], 'cqi_trace_code_unique');
            $table->index(['tenant_id', 'severity', 'created_at']);
            $table->index(['tenant_id', 'resolved_at']);
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_quality_issues');
    }
};
