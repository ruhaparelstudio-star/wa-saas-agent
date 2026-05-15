<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->string('version', 20);
            $table->text('template');
            $table->boolean('is_active')->default(true);
            $table->jsonb('accuracy_history')->default('[]');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['name', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_templates');
    }
};
