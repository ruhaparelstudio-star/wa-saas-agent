<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('tone', 50)->default('semi_formal');
            $table->string('timezone', 100)->default('Asia/Jakarta');
            $table->string('business_hours_start', 5)->default('08:00');
            $table->string('business_hours_end', 5)->default('21:00');
            $table->jsonb('business_days')->default('[1,2,3,4,5,6]');
            $table->text('after_hours_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
