<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('conversation_id')->nullable();
            $table->uuid('lead_id')->nullable();
            $table->uuid('package_id')->nullable();
            $table->string('booking_code', 20)->unique();
            $table->string('status', 20)->default('draft');
            $table->date('event_date');
            $table->time('event_time_start')->nullable();
            $table->time('event_time_end')->nullable();
            $table->string('event_type', 50)->nullable();
            $table->string('location', 255)->nullable();
            $table->integer('guest_count')->nullable();
            $table->string('customer_name', 255)->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->bigInteger('total_amount')->default(0);
            $table->bigInteger('dp_amount')->default(0);
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->string('calendar_event_id', 255)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->nullOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
            $table->foreign('package_id')->references('id')->on('packages')->nullOnDelete();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'event_date']);
        });

        // Partial unique index: 1 event_type per tenant per date for active statuses
        DB::statement("
            CREATE UNIQUE INDEX bookings_tenant_date_type_active_unique
            ON bookings (tenant_id, event_date, event_type)
            WHERE status IN ('confirmed', 'awaiting_dp', 'paid')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
