<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Shared\DTOs\AnalyticsPeriodDTO;
use App\Modules\Shared\DTOs\ConversionMetricDTO;
use App\Modules\Shared\DTOs\LeadFunnelDTO;
use App\Modules\Shared\DTOs\ResponseTimeMetricDTO;
use App\Modules\Shared\DTOs\RevenueMetricDTO;
use App\Modules\Shared\DTOs\TenantAnalyticsSummaryDTO;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\FeatureKey;
use App\Modules\Shared\Enums\InvoiceStatus;
use App\Modules\Shared\Enums\InvoiceType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    public function __construct(
        private readonly FeatureGateService $featureGate,
    ) {}

    public function getSummary(string $tenantId, AnalyticsPeriodDTO $period): TenantAnalyticsSummaryDTO
    {
        $isAdvanced = $this->featureGate->check($tenantId, FeatureKey::ANALYTICS_ADVANCED);

        if (! $isAdvanced) {
            return new TenantAnalyticsSummaryDTO(
                period: $period,
                lead_funnel: [],
                revenue: $this->emptyRevenue($period->label),
                conversion: new ConversionMetricDTO(
                    total_leads: DB::table('conversations')
                        ->where('tenant_id', $tenantId)
                        ->whereBetween('created_at', [$period->start_date, $period->end_date])
                        ->count(),
                    leads_to_booking: 0,
                    conversion_rate: 0.0,
                    avg_days_to_booking: 0.0,
                ),
                response_time: new ResponseTimeMetricDTO(null, null, 0),
                top_packages: [],
                is_advanced: false,
            );
        }

        return new TenantAnalyticsSummaryDTO(
            period: $period,
            lead_funnel: $this->getLeadFunnel($tenantId, $period),
            revenue: $this->getRevenue($tenantId, $period),
            conversion: $this->getConversion($tenantId, $period),
            response_time: $this->getResponseTime($tenantId, $period),
            top_packages: $this->getTopPackages($tenantId, $period),
            is_advanced: true,
        );
    }

    /** @return LeadFunnelDTO[] */
    public function getLeadFunnel(string $tenantId, AnalyticsPeriodDTO $period): array
    {
        $rows = DB::table('conversations')
            ->select('stage', DB::raw('COUNT(*) as cnt'))
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$period->start_date, $period->end_date])
            ->groupBy('stage')
            ->get();

        $total = $rows->sum('cnt');
        if ($total === 0) {
            return [];
        }

        return $rows->map(fn ($r) => new LeadFunnelDTO(
            stage: $r->stage,
            count: (int) $r->cnt,
            percentage: round(($r->cnt / $total) * 100, 1),
        ))->sortByDesc('count')->values()->all();
    }

    public function getRevenue(string $tenantId, AnalyticsPeriodDTO $period): RevenueMetricDTO
    {
        $invoices = DB::table('invoices')
            ->where('tenant_id', $tenantId)
            ->where('status', InvoiceStatus::PAID->value)
            ->whereBetween('paid_at', [$period->start_date, $period->end_date])
            ->get();

        $allInvoices = DB::table('invoices')
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$period->start_date, $period->end_date])
            ->count();

        return new RevenueMetricDTO(
            period_label: $period->label,
            total_revenue: (int) $invoices->sum('amount'),
            dp_revenue: (int) $invoices->where('type', InvoiceType::DP->value)->sum('amount'),
            pelunasan_revenue: (int) $invoices->where('type', InvoiceType::PELUNASAN->value)->sum('amount'),
            invoice_count: $allInvoices,
            paid_count: $invoices->count(),
        );
    }

    public function getConversion(string $tenantId, AnalyticsPeriodDTO $period): ConversionMetricDTO
    {
        $totalLeads = DB::table('conversations')
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$period->start_date, $period->end_date])
            ->count();

        if ($totalLeads === 0) {
            return new ConversionMetricDTO(0, 0, 0.0, 0.0);
        }

        $excludedStatuses = [
            BookingStatus::DRAFT->value,
            BookingStatus::EXPIRED->value,
            BookingStatus::CANCELLED->value,
        ];

        $convertedConversationIds = DB::table('bookings')
            ->join('conversations', 'bookings.conversation_id', '=', 'conversations.id')
            ->where('bookings.tenant_id', $tenantId)
            ->whereNotIn('bookings.status', $excludedStatuses)
            ->whereBetween('conversations.created_at', [$period->start_date, $period->end_date])
            ->distinct('bookings.conversation_id')
            ->count('bookings.conversation_id');

        $avgDays = DB::table('bookings')
            ->join('conversations', 'bookings.conversation_id', '=', 'conversations.id')
            ->where('bookings.tenant_id', $tenantId)
            ->whereNotIn('bookings.status', $excludedStatuses)
            ->whereBetween('conversations.created_at', [$period->start_date, $period->end_date])
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (bookings.created_at - conversations.created_at)) / 86400) as avg_days')
            ->value('avg_days');

        $rate = $totalLeads > 0 ? round(($convertedConversationIds / $totalLeads) * 100, 1) : 0.0;

        return new ConversionMetricDTO(
            total_leads: $totalLeads,
            leads_to_booking: $convertedConversationIds,
            conversion_rate: $rate,
            avg_days_to_booking: round((float) ($avgDays ?? 0), 1),
        );
    }

    public function getResponseTime(string $tenantId, AnalyticsPeriodDTO $period): ResponseTimeMetricDTO
    {
        // First outbound message per conversation
        $rows = DB::table('conversations as c')
            ->join('conversation_messages as m', function ($join) {
                $join->on('m.conversation_id', '=', 'c.id')
                    ->where('m.direction', '=', 'outbound');
            })
            ->where('c.tenant_id', $tenantId)
            ->whereBetween('c.created_at', [$period->start_date, $period->end_date])
            ->select(
                'c.id',
                DB::raw('MIN(m.created_at) as first_reply_at'),
                DB::raw('c.created_at as conv_created_at'),
            )
            ->groupBy('c.id', 'c.created_at')
            ->get();

        $sampleCount = $rows->count();

        if ($sampleCount < 5) {
            return new ResponseTimeMetricDTO(null, null, $sampleCount);
        }

        $avgMinutes = $rows->avg(function ($r) {
            return Carbon::parse($r->conv_created_at)->diffInMinutes(Carbon::parse($r->first_reply_at));
        });

        return new ResponseTimeMetricDTO(
            avg_first_response_minutes: round($avgMinutes, 1),
            avg_handling_minutes: null,
            sample_count: $sampleCount,
        );
    }

    public function getTopPackages(string $tenantId, AnalyticsPeriodDTO $period): array
    {
        $excludedStatuses = [
            BookingStatus::DRAFT->value,
            BookingStatus::EXPIRED->value,
            BookingStatus::CANCELLED->value,
        ];

        return DB::table('bookings')
            ->join('packages', 'bookings.package_id', '=', 'packages.id')
            ->where('bookings.tenant_id', $tenantId)
            ->whereNotIn('bookings.status', $excludedStatuses)
            ->whereNotNull('bookings.package_id')
            ->whereBetween('bookings.created_at', [$period->start_date, $period->end_date])
            ->select('packages.name as package_name', DB::raw('COUNT(*) as booking_count'))
            ->groupBy('packages.id', 'packages.name')
            ->orderByDesc('booking_count')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['package_name' => $r->package_name, 'booking_count' => (int) $r->booking_count])
            ->all();
    }

    public function makePeriod(string $label = 'last_30_days'): AnalyticsPeriodDTO
    {
        $now = Carbon::now();

        [$start, $end] = match ($label) {
            'last_7_days'  => [$now->copy()->subDays(7)->startOfDay(), $now->copy()->endOfDay()],
            'last_30_days' => [$now->copy()->subDays(30)->startOfDay(), $now->copy()->endOfDay()],
            'this_month'   => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'last_month'   => [$now->copy()->subMonth()->startOfMonth(), $now->copy()->subMonth()->endOfMonth()],
            default        => [$now->copy()->subDays(30)->startOfDay(), $now->copy()->endOfDay()],
        };

        return new AnalyticsPeriodDTO(
            start_date: $start,
            end_date: $end,
            label: $label,
        );
    }

    private function emptyRevenue(string $label): RevenueMetricDTO
    {
        return new RevenueMetricDTO(
            period_label: $label,
            total_revenue: 0,
            dp_revenue: 0,
            pelunasan_revenue: 0,
            invoice_count: 0,
            paid_count: 0,
        );
    }
}
