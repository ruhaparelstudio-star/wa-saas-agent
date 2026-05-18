<?php

use App\Modules\Shared\Services\PhoneNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private const TABLES = ['conversations', 'leads', 'bookings'];

    public function up(): void
    {
        $totals = [];

        foreach (self::TABLES as $table) {
            $count = 0;
            DB::table($table)
                ->whereNotNull('customer_phone')
                ->where(function ($q) {
                    $q->where('customer_phone', '~*', '^(lid|wid|s\.whatsapp\.net):')
                      ->orWhere('customer_phone', '~*', '@(lid|s\.whatsapp\.net|c\.us|g\.us|broadcast)$');
                })
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($table, &$count) {
                    foreach ($rows as $row) {
                        try {
                            $normalized = PhoneNormalizer::normalize($row->customer_phone);
                            DB::table($table)
                                ->where('id', $row->id)
                                ->update(['customer_phone' => $normalized]);
                            $count++;
                        } catch (\InvalidArgumentException $e) {
                            Log::warning("Cannot normalize phone in {$table}.{$row->id}: {$row->customer_phone}");
                        }
                    }
                });

            $totals[$table] = $count;
        }

        Log::info('PhoneNormalizer backfill complete', $totals);
    }

    public function down(): void
    {
        // Cannot reliably reverse normalization (lossy).
    }
};
