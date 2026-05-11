<?php

namespace App\Modules\Shared\Contracts;

use App\Modules\Shared\DTOs\AvailabilityResultDTO;

interface CalendarProviderInterface
{
    /** Check if a date is available for booking. */
    public function checkAvailability(string $date, string $tenantId): AvailabilityResultDTO;

    /** Get all events on a specific date for a calendar. */
    public function getEventsOnDate(string $date, string $calendarId): array;
}
