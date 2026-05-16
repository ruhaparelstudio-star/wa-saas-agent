<?php

namespace App\Modules\Shared\Contracts;

use App\Modules\Shared\DTOs\CalendarEventDTO;

interface CalendarProviderInterface
{
    /** Create a calendar event. Returns the external event id, or null if disabled/failed. */
    public function createEvent(string $tenantId, CalendarEventDTO $event): ?string;

    /** Update an existing calendar event. Returns true on success. */
    public function updateEvent(string $tenantId, string $eventId, CalendarEventDTO $event): bool;

    /** Delete a calendar event. Returns true on success. */
    public function deleteEvent(string $tenantId, string $eventId): bool;

    /** Fetch a calendar event by external id. Returns null if not found or disabled. */
    public function getEvent(string $tenantId, string $eventId): ?CalendarEventDTO;
}
