<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs tenant_policies rows that hold legacy / invalid values for the two
 * pricelist policies. A live tenant was found with:
 *   - pricelist_min_requirement = '0'       (rejected by the gate, leaked pricelist)
 *   - pricelist_mode            = 'on_request' (not in MODE_*, fell through to text)
 *
 * Code now fails safe on unknown values, but DB stays clean.
 */
return new class extends Migration
{
    public function up(): void
    {
        $validRequirements = ['none', 'require_customer_name', 'after_qualification', 'after_event_date'];
        $validModes        = ['pdf', 'text', 'hybrid', 'disabled'];

        DB::table('tenant_policies')
            ->where('policy_key', 'pricelist_min_requirement')
            ->whereNotIn('policy_value', $validRequirements)
            ->update(['policy_value' => 'require_customer_name', 'updated_at' => now()]);

        DB::table('tenant_policies')
            ->where('policy_key', 'pricelist_mode')
            ->whereNotIn('policy_value', $validModes)
            ->update(['policy_value' => 'text', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Non-destructive repair — nothing to roll back.
    }
};
