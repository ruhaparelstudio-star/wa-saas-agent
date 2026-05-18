<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->boolean('llm_grader_enabled')->default(false);
            $table->integer('llm_grader_daily_budget_idr')->default(5000);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_settings', function (Blueprint $table) {
            $table->dropColumn(['llm_grader_enabled', 'llm_grader_daily_budget_idr']);
        });
    }
};
