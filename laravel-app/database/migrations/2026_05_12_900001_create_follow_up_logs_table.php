<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_up_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('conversation_id')->nullable();
            $table->uuid('booking_id')->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->string('reason', 50);
            $table->timestamp('sent_at');
            $table->text('message_body');
            $table->boolean('delivered')->default(false);
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->nullOnDelete();
            $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();

            $table->index(['tenant_id', 'sent_at']);
            $table->index(['conversation_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_logs');
    }
};
