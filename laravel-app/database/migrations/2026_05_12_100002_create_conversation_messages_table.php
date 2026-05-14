<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('conversation_id');
            $table->string('direction', 10);
            $table->string('message_type', 20)->default('text');
            $table->text('body')->nullable();
            $table->string('media_url', 500)->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->string('intent', 100)->nullable();
            $table->boolean('is_injection_attempt')->default(false);
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['tenant_id', 'direction']);
        });

        // Partial unique index for provider_message_id (only when not null)
        DB::statement('CREATE UNIQUE INDEX conversation_messages_provider_message_id_unique ON conversation_messages (provider_message_id) WHERE provider_message_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
