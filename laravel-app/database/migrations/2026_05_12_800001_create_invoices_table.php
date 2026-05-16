<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('booking_id');
            $table->string('invoice_number', 30)->unique();
            $table->string('type', 20);
            $table->string('status', 20)->default('issued');
            $table->bigInteger('amount');
            $table->date('due_date');
            $table->text('notes')->nullable();
            $table->integer('sent_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_proof_url', 500)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();

            $table->index(['tenant_id', 'status']);
            $table->index(['booking_id']);
            $table->index(['tenant_id', 'due_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
