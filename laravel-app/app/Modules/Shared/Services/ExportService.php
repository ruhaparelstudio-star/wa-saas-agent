<?php

namespace App\Modules\Shared\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Invoice\Models\Invoice;
use Illuminate\Support\Facades\DB;

class ExportService
{
    private const MAX_ROWS = 1000;
    private const UTF8_BOM = "\xEF\xBB\xBF";

    public function exportBookings(string $tenantId, array $filters = []): string
    {
        $query = Booking::withoutGlobalScopes()
            ->where('bookings.tenant_id', $tenantId)
            ->leftJoin('packages', 'packages.id', '=', 'bookings.package_id')
            ->select([
                'bookings.booking_code',
                'bookings.customer_name',
                'bookings.customer_phone',
                'bookings.event_date',
                'bookings.event_type',
                'bookings.location',
                'packages.name as package_name',
                'bookings.status',
                'bookings.total_amount',
                'bookings.dp_amount',
                'bookings.notes',
                'bookings.created_at',
            ])
            ->orderBy('bookings.created_at', 'desc')
            ->limit(self::MAX_ROWS);

        if (!empty($filters['status'])) {
            $query->where('bookings.status', $filters['status']);
        }

        $rows    = $query->get();
        $headers = ['booking_code', 'customer_name', 'customer_phone', 'event_date',
                    'event_type', 'location', 'package_name', 'status',
                    'total_amount', 'dp_amount', 'notes', 'created_at'];

        return $this->buildCsv($headers, $rows->toArray());
    }

    public function exportInvoices(string $tenantId, array $filters = []): string
    {
        $query = Invoice::withoutGlobalScopes()
            ->where('invoices.tenant_id', $tenantId)
            ->leftJoin('bookings', 'bookings.id', '=', 'invoices.booking_id')
            ->select([
                'invoices.invoice_number',
                'bookings.booking_code',
                'invoices.type',
                'invoices.status',
                'invoices.amount',
                'invoices.due_date',
                'invoices.sent_count',
                'invoices.paid_at',
                'invoices.created_at',
            ])
            ->orderBy('invoices.created_at', 'desc')
            ->limit(self::MAX_ROWS);

        if (!empty($filters['status'])) {
            $query->where('invoices.status', $filters['status']);
        }

        $rows    = $query->get();
        $headers = ['invoice_number', 'booking_code', 'type', 'status', 'amount',
                    'due_date', 'sent_count', 'paid_at', 'created_at'];

        return $this->buildCsv($headers, $rows->toArray());
    }

    public function exportLeads(string $tenantId, array $filters = []): string
    {
        $rows = Conversation::withoutGlobalScopes()
            ->where('conversations.tenant_id', $tenantId)
            ->select([
                'conversations.customer_name',
                'conversations.customer_phone',
                'conversations.stage',
                'conversations.lead_temperature',
                'conversations.created_at',
                'conversations.last_message_at',
                DB::raw('(SELECT COUNT(*) FROM bookings WHERE bookings.conversation_id = conversations.id) as booking_count'),
            ])
            ->orderBy('conversations.created_at', 'desc')
            ->limit(self::MAX_ROWS)
            ->get();

        $headers = ['customer_name', 'phone', 'stage', 'temperature',
                    'created_at', 'last_message_at', 'booking_count'];

        $data = $rows->map(function ($conv) {
            return [
                'customer_name'   => $conv->customer_name ?? '',
                'phone'           => $this->maskPhone($conv->customer_phone ?? ''),
                'stage'           => $conv->stage instanceof \BackedEnum ? $conv->stage->value : ($conv->stage ?? ''),
                'temperature'     => $conv->lead_temperature instanceof \BackedEnum ? $conv->lead_temperature->value : ($conv->lead_temperature ?? ''),
                'created_at'      => $conv->created_at ?? '',
                'last_message_at' => $conv->last_message_at ?? '',
                'booking_count'   => $conv->booking_count ?? 0,
            ];
        })->toArray();

        return $this->buildCsv($headers, $data);
    }

    private function buildCsv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            $row = is_array($row) ? $row : (array) $row;
            $values = array_map(function ($v) {
                if ($v instanceof \Carbon\Carbon || $v instanceof \DateTimeInterface) {
                    return $v->format('Y-m-d H:i:s');
                }
                if ($v instanceof \BackedEnum) {
                    return $v->value;
                }
                return $v ?? '';
            }, array_values($row));
            fputcsv($handle, $values);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return self::UTF8_BOM . $csv;
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 7) {
            return $phone;
        }
        return substr($phone, 0, 3) . str_repeat('*', strlen($phone) - 6) . substr($phone, -3);
    }
}
