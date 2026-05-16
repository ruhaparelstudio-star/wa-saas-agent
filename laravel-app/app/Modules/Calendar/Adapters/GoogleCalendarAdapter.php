<?php

namespace App\Modules\Calendar\Adapters;

use App\Modules\Notification\Services\NotificationService;
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Shared\Contracts\CalendarProviderInterface;
use App\Modules\Shared\DTOs\CalendarEventDTO;
use App\Modules\TenantConfig\Models\TenantSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleCalendarAdapter implements CalendarProviderInterface
{
    private string $baseUrl;

    public function __construct(
        private readonly FeatureGateService $featureGate,
        private readonly NotificationService $notificationService,
    ) {
        $this->baseUrl = rtrim(config('services.google_calendar.base_url', 'https://www.googleapis.com/calendar/v3'), '/');
    }

    public function createEvent(string $tenantId, CalendarEventDTO $event): ?string
    {
        if (!$this->featureGate->isCalendarEnabled($tenantId)) {
            return null;
        }

        $token = $this->getOAuthToken($tenantId);

        try {
            $response = Http::withToken($token)
                ->post("{$this->baseUrl}/calendars/primary/events", [
                    'summary'     => $event->title,
                    'description' => $event->description,
                    'start'       => ['dateTime' => $event->start_at, 'timeZone' => 'UTC'],
                    'end'         => ['dateTime' => $event->end_at,   'timeZone' => 'UTC'],
                    'location'    => $event->location,
                    'attendees'   => array_map(fn ($email) => ['email' => $email], $event->attendees),
                ]);

            if ($response->successful()) {
                return $response->json('id');
            }

            $this->handleError($tenantId, 'createEvent', $response->body());
            return null;
        } catch (\Throwable $e) {
            $this->handleError($tenantId, 'createEvent', $e->getMessage());
            return null;
        }
    }

    public function updateEvent(string $tenantId, string $eventId, CalendarEventDTO $event): bool
    {
        if (!$this->featureGate->isCalendarEnabled($tenantId)) {
            return false;
        }

        $token = $this->getOAuthToken($tenantId);

        try {
            $response = Http::withToken($token)
                ->put("{$this->baseUrl}/calendars/primary/events/{$eventId}", [
                    'summary'     => $event->title,
                    'description' => $event->description,
                    'start'       => ['dateTime' => $event->start_at, 'timeZone' => 'UTC'],
                    'end'         => ['dateTime' => $event->end_at,   'timeZone' => 'UTC'],
                    'location'    => $event->location,
                    'attendees'   => array_map(fn ($email) => ['email' => $email], $event->attendees),
                ]);

            if ($response->successful()) {
                return true;
            }

            $this->handleError($tenantId, 'updateEvent', $response->body());
            return false;
        } catch (\Throwable $e) {
            $this->handleError($tenantId, 'updateEvent', $e->getMessage());
            return false;
        }
    }

    public function deleteEvent(string $tenantId, string $eventId): bool
    {
        if (!$this->featureGate->isCalendarEnabled($tenantId)) {
            return false;
        }

        $token = $this->getOAuthToken($tenantId);

        try {
            $response = Http::withToken($token)
                ->delete("{$this->baseUrl}/calendars/primary/events/{$eventId}");

            // 204 No Content = success
            if ($response->successful() || $response->status() === 204) {
                return true;
            }

            $this->handleError($tenantId, 'deleteEvent', $response->body());
            return false;
        } catch (\Throwable $e) {
            $this->handleError($tenantId, 'deleteEvent', $e->getMessage());
            return false;
        }
    }

    public function getEvent(string $tenantId, string $eventId): ?CalendarEventDTO
    {
        if (!$this->featureGate->isCalendarEnabled($tenantId)) {
            return null;
        }

        $token = $this->getOAuthToken($tenantId);

        try {
            $response = Http::withToken($token)
                ->get("{$this->baseUrl}/calendars/primary/events/{$eventId}");

            if (!$response->successful()) {
                $this->handleError($tenantId, 'getEvent', $response->body());
                return null;
            }

            $data = $response->json();

            return CalendarEventDTO::from([
                'id'          => $data['id'] ?? null,
                'tenant_id'   => $tenantId,
                'title'       => $data['summary'] ?? '',
                'description' => $data['description'] ?? '',
                'start_at'    => $data['start']['dateTime'] ?? '',
                'end_at'      => $data['end']['dateTime'] ?? '',
                'location'    => $data['location'] ?? null,
                'attendees'   => array_column($data['attendees'] ?? [], 'email'),
                'metadata'    => [],
            ]);
        } catch (\Throwable $e) {
            $this->handleError($tenantId, 'getEvent', $e->getMessage());
            return null;
        }
    }

    private function getOAuthToken(string $tenantId): string
    {
        $setting = TenantSetting::where('tenant_id', $tenantId)->first();
        return $setting?->google_oauth_token ?? '';
    }

    private function handleError(string $tenantId, string $method, string $error): void
    {
        Log::error("GoogleCalendarAdapter::{$method} failed.", [
            'tenant_id' => $tenantId,
            'error'     => $error,
        ]);

        $this->notificationService->notifyCalendarError($tenantId, $method, $error);
    }
}
