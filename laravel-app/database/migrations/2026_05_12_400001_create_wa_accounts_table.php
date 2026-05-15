<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->string('phone_number', 20)->nullable();
            $table->string('display_name', 255)->nullable();
            $table->string('status', 30)->default('disconnected');
            $table->text('session_data')->nullable();
            $table->text('qr_code')->nullable();
            $table->timestamp('qr_expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->integer('reconnect_attempts')->default(0);
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_accounts');
    }
};
