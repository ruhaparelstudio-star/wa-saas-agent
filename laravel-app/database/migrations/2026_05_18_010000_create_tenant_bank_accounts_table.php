<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('bank_name', 100);
            $table->string('account_number', 50);
            $table->string('account_holder', 150);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'is_active', 'sort_order']);
        });

        // Postgres partial unique — only one default per tenant.
        DB::statement('CREATE UNIQUE INDEX tenant_bank_accounts_one_default_per_tenant ON tenant_bank_accounts (tenant_id) WHERE is_default = true');

        // Seed legacy tenant_settings.bank_* into the new table so existing
        // tenants don't lose their saved details.
        if (Schema::hasColumn('tenant_settings', 'bank_name')) {
            $legacy = DB::table('tenant_settings')
                ->whereNotNull('bank_name')
                ->whereNotNull('bank_account_number')
                ->get(['tenant_id', 'bank_name', 'bank_account_number', 'bank_account_name']);

            foreach ($legacy as $row) {
                DB::table('tenant_bank_accounts')->insert([
                    'id'             => (string) Str::uuid(),
                    'tenant_id'      => $row->tenant_id,
                    'bank_name'      => $row->bank_name,
                    'account_number' => $row->bank_account_number,
                    'account_holder' => $row->bank_account_name ?? '-',
                    'is_default'     => true,
                    'sort_order'     => 0,
                    'is_active'      => true,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_bank_accounts');
    }
};
