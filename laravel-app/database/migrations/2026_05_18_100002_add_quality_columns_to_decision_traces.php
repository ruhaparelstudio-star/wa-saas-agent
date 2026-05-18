<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('decision_traces', function (Blueprint $table) {
            $table->decimal('quality_score', 3, 2)->nullable();
            $table->jsonb('llm_grade')->nullable();
            $table->timestamp('llm_graded_at')->nullable();
            $table->string('guard_verdict', 20)->nullable();
            $table->boolean('reply_overridden')->default(false);
        });

        DB::statement("CREATE INDEX IF NOT EXISTS idx_traces_tenant_quality ON decision_traces (tenant_id, quality_score) WHERE quality_score IS NOT NULL");
        DB::statement("CREATE INDEX IF NOT EXISTS idx_traces_tenant_guard ON decision_traces (tenant_id, guard_verdict, created_at)");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS idx_traces_tenant_quality");
        DB::statement("DROP INDEX IF EXISTS idx_traces_tenant_guard");

        Schema::table('decision_traces', function (Blueprint $table) {
            $table->dropColumn([
                'quality_score',
                'llm_grade',
                'llm_graded_at',
                'guard_verdict',
                'reply_overridden',
            ]);
        });
    }
};
