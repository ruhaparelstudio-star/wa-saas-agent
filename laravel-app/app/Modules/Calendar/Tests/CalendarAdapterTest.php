<?php

namespace App\Modules\Calendar\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Calendar\Adapters\GoogleCalendarAdapter;
use App\Modules\Calendar\Adapters\NullCalendarAdapter;
use App\Modules\Notification\Models\AdminNotification;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Shared\Contracts\CalendarProviderInterface;
use App\Modules\Shared\DTOs\CalendarEventDTO;
use App\Modules\Shared\Enums\NotificationType;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\TenantConfig\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CalendarAdapterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_calendar.base_url' => 'http://fake-google-calendar.test/calendar/v3',
            'services.calendar.provider'         => 'null',
        ]);

        $superadmin    = $this->makeSuperadmin();
        $this->tenantA = $this->makeTenant('Vendor A', $superadmin);
        $this->tenantB = $this->makeTenant('Vendor B', $superadmin);
        $this->adminA  = $this->makeTenantAdmin($this->tenantA);
    }

    // --- NullCalendarAdapter ---

    public function test_null_adapter_create_event_returns_null(): void
    {
        $adapter = app(NullCalendarAdapter::class);
        $event   = $this->makeEvent($this->tenantA->id);

        $result = $adapter->createEvent($this->tenantA->id, $event);

        $this->assertNull($result);
    }

    public function test_null_adapter_update_event_returns_false(): void
    {
        $adapter = app(NullCalendarAdapter::class);
        $event   = $this->makeEvent($this->tenantA->id);

        $result = $adapter->updateEvent($this->tenantA->id, 'evt-123', $event);

        $this->assertFalse($result);
    }

    public function test_null_adapter_delete_event_returns_false(): void
    {
        $adapter = app(NullCalendarAdapter::class);

        $result = $adapter->deleteEvent($this->tenantA->id, 'evt-123');

        $this->assertFalse($result);
    }

    public function test_null_adapter_get_event_returns_null(): void
    {
        $adapter = app(NullCalendarAdapter::class);

        $result = $adapter->getEvent($this->tenantA->id, 'evt-123');

        $this->assertNull($result);
    }

    // --- CalendarProviderInterface binding: default = NullCalendarAdapter ---

    public function test_default_provider_resolves_to_null_adapter(): void
    {
        $adapter = app(CalendarProviderInterface::class);

        $this->assertInstanceOf(NullCalendarAdapter::class, $adapter);
    }

    // --- GoogleCalendarAdapter: feature disabled ---

    public function test_google_adapter_create_event_returns_null_when_feature_disabled(): void
    {
        Http::fake(); // ensure no real HTTP calls

        $featureGate = $this->mockFeatureGate(enabled: false);
        $adapter     = $this->makeGoogleAdapter($featureGate);
        $event       = $this->makeEvent($this->tenantA->id);

        $result = $adapter->createEvent($this->tenantA->id, $event);

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_google_adapter_update_event_returns_false_when_feature_disabled(): void
    {
        Http::fake();

        $featureGate = $this->mockFeatureGate(enabled: false);
        $adapter     = $this->makeGoogleAdapter($featureGate);
        $event       = $this->makeEvent($this->tenantA->id);

        $result = $adapter->updateEvent($this->tenantA->id, 'evt-123', $event);

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    public function test_google_adapter_delete_event_returns_false_when_feature_disabled(): void
    {
        Http::fake();

        $featureGate = $this->mockFeatureGate(enabled: false);
        $adapter     = $this->makeGoogleAdapter($featureGate);

        $result = $adapter->deleteEvent($this->tenantA->id, 'evt-123');

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    // --- GoogleCalendarAdapter: feature enabled + Http::fake ---

    public function test_google_adapter_create_event_returns_event_id_on_success(): void
    {
        Http::fake([
            '*/calendars/primary/events' => Http::response(['id' => 'evt-abc-123'], 200),
        ]);

        $this->makeTenantSetting($this->tenantA->id, 'fake-oauth-token');

        $featureGate = $this->mockFeatureGate(enabled: true);
        $adapter     = $this->makeGoogleAdapter($featureGate);
        $event       = $this->makeEvent($this->tenantA->id);

        $result = $adapter->createEvent($this->tenantA->id, $event);

        $this->assertSame('evt-abc-123', $result);
        Http::assertSentCount(1);
    }

    public function test_google_adapter_update_event_returns_true_on_success(): void
    {
        Http::fake([
            '*/calendars/primary/events/evt-abc-123' => Http::response(['id' => 'evt-abc-123'], 200),
        ]);

        $this->makeTenantSetting($this->tenantA->id, 'fake-oauth-token');

        $featureGate = $this->mockFeatureGate(enabled: true);
        $adapter     = $this->makeGoogleAdapter($featureGate);
        $event       = $this->makeEvent($this->tenantA->id);

        $result = $adapter->updateEvent($this->tenantA->id, 'evt-abc-123', $event);

        $this->assertTrue($result);
    }

    public function test_google_adapter_delete_event_returns_true_on_success(): void
    {
        Http::fake([
            '*/calendars/primary/events/evt-abc-123' => Http::response(null, 204),
        ]);

        $this->makeTenantSetting($this->tenantA->id, 'fake-oauth-token');

        $featureGate = $this->mockFeatureGate(enabled: true);
        $adapter     = $this->makeGoogleAdapter($featureGate);

        $result = $adapter->deleteEvent($this->tenantA->id, 'evt-abc-123');

        $this->assertTrue($result);
    }

    public function test_google_adapter_get_event_returns_dto_on_success(): void
    {
        Http::fake([
            '*/calendars/primary/events/evt-abc-123' => Http::response([
                'id'          => 'evt-abc-123',
                'summary'     => 'Wedding Event',
                'description' => 'Resepsi',
                'start'       => ['dateTime' => '2026-09-01T08:00:00Z'],
                'end'         => ['dateTime' => '2026-09-01T12:00:00Z'],
                'location'    => 'Jakarta',
                'attendees'   => [['email' => 'customer@example.com']],
            ], 200),
        ]);

        $this->makeTenantSetting($this->tenantA->id, 'fake-oauth-token');

        $featureGate = $this->mockFeatureGate(enabled: true);
        $adapter     = $this->makeGoogleAdapter($featureGate);

        $result = $adapter->getEvent($this->tenantA->id, 'evt-abc-123');

        $this->assertInstanceOf(CalendarEventDTO::class, $result);
        $this->assertSame('evt-abc-123', $result->id);
        $this->assertSame('Wedding Event', $result->title);
        $this->assertSame('Jakarta', $result->location);
        $this->assertContains('customer@example.com', $result->attendees);
    }

    // --- GoogleCalendarAdapter: error handling ---

    public function test_google_adapter_create_event_returns_null_and_emits_calendar_error_on_failure(): void
    {
        Http::fake([
            '*/calendars/primary/events' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $this->makeTenantSetting($this->tenantA->id, 'bad-token');

        $featureGate = $this->mockFeatureGate(enabled: true);
        $adapter     = $this->makeGoogleAdapter($featureGate);
        $event       = $this->makeEvent($this->tenantA->id);

        $result = $adapter->createEvent($this->tenantA->id, $event);

        $this->assertNull($result);

        $this->assertDatabaseHas('admin_notifications', [
            'tenant_id' => $this->tenantA->id,
            'user_id'   => $this->adminA->id,
            'type'      => NotificationType::CALENDAR_ERROR->value,
        ]);
    }

    // --- Tenant isolation ---

    public function test_google_adapter_uses_own_tenant_token_not_another_tenants(): void
    {
        $captured = [];

        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->header('Authorization');
            return Http::response(['id' => 'evt-xyz'], 200);
        });

        // Tenant A has token-A, Tenant B has token-B
        $this->makeTenantSetting($this->tenantA->id, 'token-for-tenant-a');
        $this->makeTenantSetting($this->tenantB->id, 'token-for-tenant-b');

        $featureGate = $this->mockFeatureGate(enabled: true);
        $adapter     = $this->makeGoogleAdapter($featureGate);
        $event       = $this->makeEvent($this->tenantA->id);

        $adapter->createEvent($this->tenantA->id, $event);

        // Must use tenant A's token
        $this->assertNotEmpty($captured);
        $authHeader = $captured[0][0] ?? '';
        $this->assertStringContainsString('token-for-tenant-a', $authHeader);
        $this->assertStringNotContainsString('token-for-tenant-b', $authHeader);
    }

    // --- Helpers ---

    private function makeEvent(string $tenantId): CalendarEventDTO
    {
        return CalendarEventDTO::from([
            'tenant_id'   => $tenantId,
            'title'       => 'Resepsi Pernikahan',
            'description' => 'Detail acara resepsi',
            'start_at'    => '2026-09-01T08:00:00Z',
            'end_at'      => '2026-09-01T12:00:00Z',
            'location'    => 'Jakarta Selatan',
            'attendees'   => [],
            'metadata'    => [],
        ]);
    }

    private function mockFeatureGate(bool $enabled): FeatureGateService
    {
        $mock = $this->createMock(FeatureGateService::class);
        $mock->method('isCalendarEnabled')->willReturn($enabled);
        return $mock;
    }

    private function makeGoogleAdapter(FeatureGateService $featureGate): GoogleCalendarAdapter
    {
        return new GoogleCalendarAdapter(
            $featureGate,
            app(NotificationService::class),
            app(\App\Modules\Calendar\Services\GoogleOAuthService::class),
        );
    }

    private function makeTenantSetting(string $tenantId, string $oauthToken): TenantSetting
    {
        return TenantSetting::create([
            'tenant_id'          => $tenantId,
            'google_oauth_token' => json_encode([
                'access_token'  => $oauthToken,
                'refresh_token' => 'refresh-' . $oauthToken,
                'expires_at'    => now()->addHour()->toIso8601String(),
            ]),
        ]);
    }

    private function makeSuperadmin(): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'admin-' . Str::random(6) . '@platform.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function makeTenant(string $name, User $createdBy): Tenant
    {
        $slug = Str::slug($name) . '-' . Str::random(4);

        return Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => $name,
            'slug'          => $slug,
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => $slug . '@example.com',
            'created_by_id' => $createdBy->id,
        ]);
    }

    private function makeTenantAdmin(Tenant $tenant): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Admin ' . $tenant->name,
            'email'     => 'admin-' . Str::random(6) . '@' . $tenant->slug . '.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::TENANT_ADMIN,
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);
    }
}
