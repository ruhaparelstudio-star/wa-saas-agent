<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_traces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('conversation_id');
            $table->uuid('conversation_message_id')->nullable();

            // Input
            $table->text('raw_message')->nullable();
            $table->string('message_type', 20)->nullable();
            $table->boolean('is_sanitized')->default(false);
            $table->boolean('injection_detected')->default(false);

            // Intent
            $table->string('intent', 100)->nullable();
            $table->decimal('intent_confidence', 4, 3)->nullable();
            $table->text('intent_reason')->nullable();
            $table->text('intent_raw_response')->nullable();

            // Entity
            $table->jsonb('extracted_entities')->default('{}');
            $table->decimal('entity_confidence', 4, 3)->nullable();
            $table->jsonb('needs_clarification')->default('[]');

            // Knowledge
            $table->jsonb('grounding_refs')->default('[]');
            $table->string('search_method', 20)->nullable();

            // Decision
            $table->string('decision', 50)->nullable();
            $table->jsonb('desired_actions')->default('[]');
            $table->jsonb('allowed_actions')->default('[]');
            $table->jsonb('blocked_actions')->default('[]');
            $table->string('stage_before', 50)->nullable();
            $table->string('stage_after', 50)->nullable();
            $table->boolean('handoff_required')->default(false);

            // Validation
            $table->string('policy_result', 20)->nullable();
            $table->string('grounding_result', 20)->nullable();
            $table->string('permission_result', 20)->nullable();
            $table->string('mode_result', 20)->nullable();
            $table->jsonb('validator_warnings')->default('[]');

            // LLM
            $table->text('intent_prompt')->nullable();
            $table->text('entity_prompt')->nullable();
            $table->text('composer_prompt')->nullable();
            $table->text('intent_llm_response')->nullable();
            $table->text('entity_llm_response')->nullable();
            $table->text('composer_llm_response')->nullable();
            $table->integer('prompt_tokens_total')->default(0);
            $table->integer('completion_tokens_total')->default(0);

            // Output
            $table->text('final_reply')->nullable();
            $table->string('reply_type', 20)->nullable();
            $table->boolean('detected_hallucination')->default(false);
            $table->jsonb('actions_dispatched')->default('[]');

            // Meta
            $table->integer('processing_time_ms')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_traces');
    }
};
