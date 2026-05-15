<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handoff_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->uuid('conversation_id');
            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
            $table->uuid('wa_account_id')->nullable();
            $table->foreign('wa_account_id')->references('id')->on('wa_accounts')->onDelete('set null');
            $table->uuid('assigned_to')->nullable();
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
            $table->string('status', 20)->default('pending');
            $table->string('priority', 10)->default('medium');
            $table->text('reason')->nullable();
            $table->string('trigger_intent', 100)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'priority', 'created_at']);
            $table->index(['conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handoff_records');
    }
};
