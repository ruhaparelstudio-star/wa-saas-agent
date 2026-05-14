<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('question', 500);
            $table->text('answer');
            $table->string('category', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE faqs ADD COLUMN search_vector tsvector');
            DB::statement('CREATE INDEX faqs_search_vector_idx ON faqs USING GIN (search_vector)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};