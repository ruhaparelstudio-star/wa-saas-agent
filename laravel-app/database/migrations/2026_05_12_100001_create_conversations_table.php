<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('wa_account_id')->nullable();
            $table->string('customer_phone', 20);
            $table->string('customer_name', 255)->nullable();
            $table->string('stage', 50)->default('new_lead');
            $table->string('agent_mode', 20)->default('active');
            $table->string('memory_mode', 20)->default('active');
            $table->string('lead_temperature', 20)->default('cold');
            $table->jsonb('entity_cache')->default('{}');
            $table->text('context_summary')->nullable();
            $table->integer('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unique(['tenant_id', 'customer_phone']);
            $table->index(['tenant_id', 'stage', 'agent_mode']);
            $table->index(['tenant_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
