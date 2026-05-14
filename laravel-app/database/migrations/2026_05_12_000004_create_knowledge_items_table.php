<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('title');
            $table->text('content');
            $table->string('category', 100);
            $table->jsonb('tags')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'category', 'is_active']);
        });

        \DB::statement('ALTER TABLE knowledge_items ADD COLUMN search_vector tsvector');
        \DB::statement('CREATE INDEX knowledge_items_search_vector_idx ON knowledge_items USING GIN (search_vector)');
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_items');
    }
};
