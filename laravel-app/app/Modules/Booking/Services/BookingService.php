<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Repositories\BookingRepository;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Shared\Contracts\CalendarProviderInterface;
use App\Modules\Shared\DTOs\CalendarEventDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\NotificationType;
use App\Modules\Auth\Models\User;
use App\Modules\Notification\Models\AdminNotification;
use App\Modules\Shared\Enums\UserRole;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingService
{
    public function __construct(
        private readonly BookingRepository $bookingRepository,
        private readonly NotificationService $notificationService,
        private readonly ConversationRepository $conversationRepository,
        private readonly CalendarProviderInterface $calendar,
    ) {}

    /**
     * Check if a date+eventType slot is available for the tenant.
     * Uses pessimistic lock (PRINSIP 14) to prevent race conditions.
     */
    public function checkAvailability(string $tenantId, Carbon $date, ?string $eventType = null): bool
    {
        return DB::transaction(function () use ($tenantId, $date, $eventType) {
            return Booking::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('event_date', $date->toDateString())
                ->when($eventType, fn ($q, $e) => $q->where('event_type', $e))
                ->whereIn('status', [
                    BookingStatus::CONFIRMED->value,
                    BookingStatus::AWAITING_DP->value,
                    BookingStatus::PAID->value,
                ])
                ->lockForUpdate()
                ->doesntExist();
        });
    }

    /**
     * Create a DRAFT booking from TurnContextDTO (pipeline-driven).
     * Returns null if the date is already taken.
     */
    public function createDraft(TurnContextDTO $context): ?Booking
    {
        $entities = $context->entities->entities ?? [];

        $eventDateRaw = $entities['event_date'] ?? null;
        if (empty($eventDateRaw)) {
            return null;
        }

        try {
            $eventDate = Carbon::parse($eventDateRaw);
        } catch (\Throwable $e) {
            Log::warning('BookingService: invalid event_date format', [
                'event_date' => $eventDateRaw,
                'tenant_id'  => $context->tenant->id,
            ]);
            return null;
        }

        $eventType = $entities['event_type'] ?? null;

        return DB::transaction(function () use ($context, $entities, $eventDate, $eventType) {
            // Availability check inside transaction for pessimistic lock
            $available = $this->checkAvailability($context->tenant->id, $eventDate, $eventType);
            if (!$available) {
                return null;
            }

            $booking = $this->bookingRepository->create([
                'tenant_id'       => $context->tenant->id,
                'conversation_id' => $context->conversation->id,
                'event_date'      => $eventDate->toDateString(),
                'event_time_start' => $entities['event_time_start'] ?? null,
                'event_time_end'   => $entities['event_time_end'] ?? null,
                'event_type'      => $eventType,
                'location'        => $entities['location'] ?? null,
                'guest_count'     => isset($entities['guest_count']) ? (int) $entities['guest_count'] : null,
                'customer_name'   => $entities['customer_name'] ?? null,
                'customer_phone'  => $context->conversation->from_phone,
                'status'          => BookingStatus::DRAFT->value,
            ]);

            // Update conversation stage to WAITING_BOOKING
            $this->conversationRepository->updateState($context->conversation->id, [
                'stage' => ConversationStage::WAITING_BOOKING->value,
            ]);

            $this->sendBookingNotification(
                $context->tenant->id,
                $booking,
                'BOOKING_DRAFTED',
                sprintf(
                    'Booking draft %s dibuat untuk %s pada %s.',
                    $booking->booking_code,
                    $this->maskPhone($context->conversation->from_phone),
                    $eventDate->toDateString()
                )
            );

            Log::info('BookingService: draft created.', [
                'booking_id'   => $booking->id,
                'booking_code' => $booking->booking_code,
                'tenant_id'    => $context->tenant->id,
            ]);

            return $booking;
        });
    }

    /**
     * Confirm a booking. Re-checks availability as a defensive measure.
     */
    public function confirm(Booking $booking): void
    {
        DB::transaction(function () use ($booking) {
            // Defensive re-check: make sure the slot is still free (excluding this booking)
            $conflict = Booking::withoutGlobalScopes()
                ->where('tenant_id', $booking->tenant_id)
                ->where('event_date', $booking->event_date->toDateString())
                ->when($booking->event_type, fn ($q, $e) => $q->where('event_type', $e))
                ->whereIn('status', [
                    BookingStatus::CONFIRMED->value,
                    BookingStatus::AWAITING_DP->value,
                    BookingStatus::PAID->value,
                ])
                ->where('id', '!=', $booking->id)
                ->lockForUpdate()
                ->exists();

            if ($conflict) {
                throw new \RuntimeException(
                    "Booking conflict on {$booking->event_date->toDateString()} for {$booking->event_type}"
                );
            }

            $booking->markConfirmed();
        });

        // Calendar sync — side-effect; failure must NOT roll back confirmation
        try {
            $event   = $this->buildCalendarEvent($booking->fresh());
            $eventId = $this->calendar->createEvent($booking->tenant_id, $event);
            if ($eventId) {
                $booking->update(['calendar_event_id' => $eventId]);
            }
        } catch (\Throwable $e) {
            Log::warning('BookingService: calendar createEvent failed after confirm.', [
                'booking_id' => $booking->id,
                'error'      => $e->getMessage(),
            ]);
            $this->notificationService->notifyCalendarError(
                $booking->tenant_id,
                'confirmBooking',
                $e->getMessage()
            );
        }

        // Update conversation stage
        if ($booking->conversation_id) {
            $this->conversationRepository->updateState($booking->conversation_id, [
                'stage' => ConversationStage::BOOKING->value,
            ]);
        }

        $this->sendBookingNotification(
            $booking->tenant_id,
            $booking,
            'BOOKING_CONFIRMED',
            sprintf('Booking %s dikonfirmasi.', $booking->booking_code)
        );

        Log::info('BookingService: booking confirmed.', [
            'booking_id'   => $booking->id,
            'booking_code' => $booking->booking_code,
        ]);
    }

    /**
     * Cancel a booking and notify admins.
     */
    public function cancel(Booking $booking, string $reason = ''): void
    {
        // Calendar delete — attempt before marking cancelled so event_id is still set
        if ($booking->calendar_event_id) {
            try {
                $this->calendar->deleteEvent($booking->tenant_id, $booking->calendar_event_id);
            } catch (\Throwable $e) {
                Log::warning('BookingService: calendar deleteEvent failed during cancel.', [
                    'booking_id'       => $booking->id,
                    'calendar_event_id' => $booking->calendar_event_id,
                    'error'            => $e->getMessage(),
                ]);
            }
        }

        $booking->markCancelled($reason);

        $this->sendBookingNotification(
            $booking->tenant_id,
            $booking,
            'BOOKING_CANCELLED',
            sprintf('Booking %s dibatalkan. Alasan: %s', $booking->booking_code, $reason ?: '-')
        );

        Log::info('BookingService: booking cancelled.', [
            'booking_id' => $booking->id,
            'reason'     => $reason,
        ]);
    }

    /**
     * Suggest available dates within ±$range days from $date.
     *
     * @return array<int, array{date: Carbon, distance_days: int}>
     */
    public function suggestAlternatives(string $tenantId, Carbon $date, int $range = 14): array
    {
        $alternatives = [];

        for ($offset = 1; $offset <= $range; $offset++) {
            foreach ([-$offset, $offset] as $delta) {
                $candidate = $date->copy()->addDays($delta);
                if ($candidate->isPast()) {
                    continue;
                }
                if ($this->checkAvailability($tenantId, $candidate)) {
                    $alternatives[] = [
                        'date'          => $candidate,
                        'distance_days' => abs($delta),
                    ];
                }
            }
        }

        // Sort by distance ascending, deduplicate
        usort($alternatives, fn ($a, $b) => $a['distance_days'] <=> $b['distance_days']);

        return $alternatives;
    }

    /**
     * Reschedule a booking to a new date. Re-checks availability with a pessimistic lock.
     * Updates the Google Calendar event if one exists.
     */
    public function rescheduleBooking(Booking $booking, Carbon $newDate, ?string $newTimeStart = null): bool
    {
        $success = DB::transaction(function () use ($booking, $newDate, $newTimeStart) {
            $conflict = Booking::withoutGlobalScopes()
                ->where('tenant_id', $booking->tenant_id)
                ->where('event_date', $newDate->toDateString())
                ->when($booking->event_type, fn ($q, $e) => $q->where('event_type', $e))
                ->whereIn('status', [
                    BookingStatus::CONFIRMED->value,
                    BookingStatus::AWAITING_DP->value,
                    BookingStatus::PAID->value,
                ])
                ->where('id', '!=', $booking->id)
                ->lockForUpdate()
                ->exists();

            if ($conflict) {
                return false;
            }

            $updates = ['event_date' => $newDate->toDateString()];
            if ($newTimeStart !== null) {
                $updates['event_time_start'] = $newTimeStart;
            }
            $booking->update($updates);

            return true;
        });

        if ($success && $booking->calendar_event_id) {
            try {
                $event = $this->buildCalendarEvent($booking->fresh());
                $this->calendar->updateEvent($booking->tenant_id, $booking->calendar_event_id, $event);
            } catch (\Throwable $e) {
                Log::warning('BookingService: calendar updateEvent failed during reschedule.', [
                    'booking_id' => $booking->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        Log::info('BookingService: booking rescheduled.', [
            'booking_id' => $booking->id,
            'new_date'   => $newDate->toDateString(),
            'success'    => $success,
        ]);

        return $success;
    }

    private function buildCalendarEvent(Booking $booking): CalendarEventDTO
    {
        $customerName = $booking->customer_name ?? 'Customer';
        $eventType    = $booking->event_type ?? 'event';
        $title        = "{$customerName} — {$eventType} — {$booking->booking_code}";

        $timezone = 'Asia/Jakarta';
        $dateStr  = $booking->event_date->toDateString();
        $startStr = $booking->event_time_start ?? '08:00';

        $startAt = Carbon::createFromFormat('Y-m-d H:i', "{$dateStr} {$startStr}", $timezone)
            ->setTimezone('UTC')
            ->toIso8601String();

        $endAt = $booking->event_time_end
            ? Carbon::createFromFormat('Y-m-d H:i', "{$dateStr} {$booking->event_time_end}", $timezone)
                ->setTimezone('UTC')
                ->toIso8601String()
            : Carbon::createFromFormat('Y-m-d H:i', "{$dateStr} {$startStr}", $timezone)
                ->addHours(4)
                ->setTimezone('UTC')
                ->toIso8601String();

        return CalendarEventDTO::from([
            'id'          => $booking->calendar_event_id,
            'tenant_id'   => $booking->tenant_id,
            'title'       => $title,
            'description' => sprintf(
                "Booking: %s\nCustomer: %s\nGuest: %d",
                $booking->booking_code,
                $customerName,
                $booking->guest_count ?? 0
            ),
            'start_at'  => $startAt,
            'end_at'    => $endAt,
            'location'  => $booking->location,
            'attendees' => [],
            'metadata'  => ['booking_id' => $booking->id],
        ]);
    }

    private function sendBookingNotification(
        string $tenantId,
        Booking $booking,
        string $subtype,
        string $body,
    ): void {
        $admins = User::where('tenant_id', $tenantId)
            ->where('role', UserRole::TENANT_ADMIN->value)
            ->where('is_active', true)
            ->get();

        foreach ($admins as $admin) {
            AdminNotification::create([
                'tenant_id' => $tenantId,
                'user_id'   => $admin->id,
                'type'      => NotificationType::BOOKING_ACTION->value,
                'title'     => 'Booking: ' . $booking->booking_code,
                'body'      => $body,
                'data'      => [
                    'booking_id'   => $booking->id,
                    'booking_code' => $booking->booking_code,
                    'subtype'      => $subtype,
                ],
            ]);
        }
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) {
            return $phone;
        }
        return substr($phone, 0, 4) . '***' . substr($phone, -4);
    }
}
