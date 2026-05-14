<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('conversation_id')->unique();
            $table->string('customer_name', 255)->nullable();
            $table->string('customer_phone', 20);
            $table->date('event_date')->nullable();
            $table->string('event_type', 50)->nullable();
            $table->string('location', 255)->nullable();
            $table->integer('guest_count')->nullable();
            $table->bigInteger('budget_min')->nullable();
            $table->bigInteger('budget_max')->nullable();
            $table->string('package_interest', 255)->nullable();
            $table->string('package_slug', 100)->nullable();
            $table->integer('lead_score')->default(0);
            $table->string('temperature', 20)->default('cold');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();

            $table->index(['tenant_id', 'temperature']);
            $table->index(['tenant_id', 'event_date']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
