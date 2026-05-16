<?php

namespace App\Filament\Tenant\Widgets;

use App\Modules\Booking\Models\Booking;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\InvoiceStatus;
use App\Modules\WhatsApp\Models\WaAccount;
use App\Modules\Shared\Enums\WaAccountStatus;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TenantOverviewWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $tenantId = auth()->user()->tenant_id;

        $waConnected = WaAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', WaAccountStatus::CONNECTED->value)
            ->exists();

        $waAccount = WaAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->first();

        $activeLeads = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('stage', [ConversationStage::CLOSED->value])
            ->count();

        $leadsToday = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('created_at', today())
            ->count();

        $activeBookings = Booking::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', [
                BookingStatus::CANCELLED->value,
                BookingStatus::EXPIRED->value,
                BookingStatus::DRAFT->value,
            ])
            ->count();

        $revenueThisMonth = (int) Invoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', InvoiceStatus::PAID->value)
            ->whereMonth('paid_at', now()->month)
            ->whereYear('paid_at', now()->year)
            ->sum('amount');

        $packages = Package::where('tenant_id', $tenantId)->count();

        if (!$waAccount) {
            $waStatus = 'Belum Ada Akun';
            $waColor  = 'gray';
        } elseif ($waConnected) {
            $waStatus = 'Terhubung ✓';
            $waColor  = 'success';
        } else {
            $waStatus = match ($waAccount->status) {
                WaAccountStatus::QR_PENDING,
                WaAccountStatus::CONNECTING   => 'Menunggu QR Scan',
                WaAccountStatus::RECONNECTING => 'Reconnecting...',
                WaAccountStatus::FAILED       => 'Gagal',
                default                       => 'Terputus',
            };
            $waColor = 'warning';
        }

        return [
            Stat::make('Status WhatsApp', $waStatus)
                ->icon('heroicon-o-device-phone-mobile')
                ->color($waColor)
                ->description($waAccount?->display_name ?? 'Tambah akun di menu WhatsApp'),

            Stat::make('Lead Aktif', number_format($activeLeads))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('info')
                ->description("Masuk hari ini: {$leadsToday}"),

            Stat::make('Booking Aktif', number_format($activeBookings))
                ->icon('heroicon-o-calendar-days')
                ->color('success'),

            Stat::make('Revenue Bulan Ini', 'Rp ' . number_format($revenueThisMonth, 0, ',', '.'))
                ->icon('heroicon-o-banknotes')
                ->color('warning'),

            Stat::make('Paket Aktif', $packages)
                ->icon('heroicon-o-gift')
                ->color('primary')
                ->description('Paket yang ditawarkan'),
        ];
    }
}
