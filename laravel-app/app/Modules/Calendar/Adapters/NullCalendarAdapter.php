<?php

namespace App\Modules\Calendar\Adapters;

use App\Modules\Shared\Contracts\CalendarProviderInterface;
use App\Modules\Shared\DTOs\CalendarEventDTO;

class NullCalendarAdapter implements CalendarProviderInterface
{
    public function createEvent(string $tenantId, CalendarEventDTO $event): ?string
    {
        return null;
    }

    public function updateEvent(string $tenantId, string $eventId, CalendarEventDTO $event): bool
    {
        return false;
    }

    public function deleteEvent(string $tenantId, string $eventId): bool
    {
        return false;
    }

    public function getEvent(string $tenantId, string $eventId): ?CalendarEventDTO
    {
        return null;
    }
}
