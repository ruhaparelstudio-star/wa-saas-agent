<?php

namespace App\Filament\Superadmin\Widgets;

use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\InvoiceStatus;
use Illuminate\Support\Facades\DB;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformStatsWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $thisMonth = now()->startOfMonth();

        $conversations = DB::table('conversations')
            ->where('created_at', '>=', $thisMonth)
            ->count();

        $bookings = DB::table('bookings')
            ->whereNotIn('status', [BookingStatus::DRAFT->value, BookingStatus::EXPIRED->value, BookingStatus::CANCELLED->value])
            ->where('created_at', '>=', $thisMonth)
            ->count();

        $revenue = (int) DB::table('invoices')
            ->where('status', InvoiceStatus::PAID->value)
            ->where('paid_at', '>=', $thisMonth)
            ->sum('amount');

        $revenueFormatted = 'Rp ' . number_format($revenue, 0, ',', '.');

        return [
            Stat::make('Leads Bulan Ini', number_format($conversations, 0, ',', '.'))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('info'),
            Stat::make('Booking Aktif Bulan Ini', number_format($bookings, 0, ',', '.'))
                ->icon('heroicon-o-calendar')
                ->color('success'),
            Stat::make('Revenue Bulan Ini', $revenueFormatted)
                ->icon('heroicon-o-banknotes')
                ->color('warning'),
        ];
    }
}
