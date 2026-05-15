<?php

namespace App\Modules\Booking\Repositories;

use App\Modules\Booking\Models\Booking;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Scopes\TenantScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BookingRepository
{
    public function findById(string $id): ?Booking
    {
        return Booking::withoutGlobalScope(TenantScope::class)->find($id);
    }

    public function findByCode(string $code): ?Booking
    {
        return Booking::withoutGlobalScope(TenantScope::class)
            ->where('booking_code', $code)
            ->first();
    }

    public function findActiveByTenantAndDate(string $tenantId, Carbon $date): Collection
    {
        return Booking::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('event_date', $date->toDateString())
            ->whereNotIn('status', [
                BookingStatus::CANCELLED->value,
                BookingStatus::EXPIRED->value,
            ])
            ->get();
    }

    public function findUpcomingByTenant(string $tenantId, int $days = 30): Collection
    {
        return Booking::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('event_date', '>=', Carbon::today()->toDateString())
            ->where('event_date', '<=', Carbon::today()->addDays($days)->toDateString())
            ->whereNotIn('status', [
                BookingStatus::CANCELLED->value,
                BookingStatus::EXPIRED->value,
            ])
            ->orderBy('event_date')
            ->get();
    }

    public function countByStatus(string $tenantId, BookingStatus $status): int
    {
        return Booking::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('status', $status->value)
            ->count();
    }

    public function create(array $data): Booking
    {
        return DB::transaction(function () use ($data) {
            $tenantId = $data['tenant_id'];

            if (empty($data['booking_code'])) {
                $data['booking_code'] = Booking::generateBookingCode($tenantId);
            }

            $data['status'] = $data['status'] ?? BookingStatus::DRAFT->value;

            return Booking::withoutGlobalScope(TenantScope::class)->create($data);
        });
    }
}
