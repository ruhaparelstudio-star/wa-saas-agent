<?php

namespace App\Modules\Notification\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Handoff\Models\HandoffRecord;
use App\Modules\Notification\Jobs\SendHandoffEmailJob;
use App\Modules\Notification\Mail\HandoffRequiredMail;
use App\Modules\Notification\Models\AdminNotification;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\HandoffPriority;
use App\Modules\Shared\Enums\HandoffStatus;
use App\Modules\Shared\Enums\NotificationType;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WaAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;
    private Tenant $tenantA;
    private Tenant $tenantB;
    private User $adminA;
    private User $adminA2;
    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(NotificationService::class);

        $superadmin    = $this->makeSuperadmin();
        $this->actingAs($superadmin);

        $this->tenantA = $this->makeTenant('Tenant A', $superadmin);
        $this->tenantB = $this->makeTenant('Tenant B', $superadmin);

        $this->adminA  = $this->makeAdminUser($this->tenantA->id, 'admin-a1@tenant.com');
        $this->adminA2 = $this->makeAdminUser($this->tenantA->id, 'admin-a2@tenant.com');
        $this->adminB  = $this->makeAdminUser($this->tenantB->id, 'admin-b@tenant.com');
    }

    // ── notifyHandoffRequired ────────────────────────────────────────────

    public function test_notify_handoff_required_creates_notifications_for_all_tenant_admins(): void
    {
        Mail::fake();

        $conv   = $this->makeConversation($this->tenantA->id);
        $record = $this->makeHandoffRecord($conv);

        $this->service->notifyHandoffRequired($conv, $record);

        // Both admins of tenantA receive notification
        $this->assertEquals(2, AdminNotification::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('type', NotificationType::HANDOFF_REQUIRED->value)
            ->count());
    }

    public function test_notify_handoff_required_queues_mail_for_each_admin(): void
    {
        Queue::fake();

        $conv   = $this->makeConversation($this->tenantA->id);
        $record = $this->makeHandoffRecord($conv);

        $this->service->notifyHandoffRequired($conv, $record);

        Queue::assertPushed(SendHandoffEmailJob::class, 2);
    }

    public function test_notify_handoff_required_does_not_notify_other_tenant_admins(): void
    {
        Mail::fake();

        $conv   = $this->makeConversation($this->tenantA->id);
        $record = $this->makeHandoffRecord($conv);

        $this->service->notifyHandoffRequired($conv, $record);

        // Tenant B admin has no notification
        $this->assertEquals(0, AdminNotification::withoutGlobalScopes()
            ->where('user_id', $this->adminB->id)
            ->count());
    }

    // ── notifyWaDisconnected ─────────────────────────────────────────────

    public function test_notify_wa_disconnected_creates_notifications_for_tenant_admins(): void
    {
        $account = $this->makeWaAccount($this->tenantA->id);

        $this->service->notifyWaDisconnected($account);

        $this->assertEquals(2, AdminNotification::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('type', NotificationType::WA_DISCONNECTED->value)
            ->count());
    }

    public function test_notify_wa_disconnected_does_not_notify_other_tenant(): void
    {
        $account = $this->makeWaAccount($this->tenantA->id);

        $this->service->notifyWaDisconnected($account);

        $this->assertEquals(0, AdminNotification::withoutGlobalScopes()
            ->where('user_id', $this->adminB->id)
            ->count());
    }

    // ── notifyInjectionAttempt ───────────────────────────────────────────

    public function test_notify_injection_attempt_creates_dashboard_notification_only(): void
    {
        Queue::fake();

        $convId = Str::uuid()->toString();
        $this->service->notifyInjectionAttempt($this->tenantA->id, $convId);

        $this->assertEquals(2, AdminNotification::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('type', NotificationType::INJECTION_ATTEMPT_DETECTED->value)
            ->count());

        // No email job dispatched for injection attempt
        Queue::assertNotPushed(SendHandoffEmailJob::class);
    }

    // ── markAllRead ──────────────────────────────────────────────────────

    public function test_mark_all_read_sets_read_at_for_user(): void
    {
        Mail::fake();

        $conv   = $this->makeConversation($this->tenantA->id);
        $record = $this->makeHandoffRecord($conv);
        $this->service->notifyHandoffRequired($conv, $record);

        // Verify unread before
        $this->assertEquals(1, $this->service->countUnread($this->adminA->id));

        $this->service->markAllRead($this->adminA->id);

        $this->assertEquals(0, $this->service->countUnread($this->adminA->id));
    }

    public function test_mark_all_read_does_not_affect_other_users(): void
    {
        Mail::fake();

        $conv   = $this->makeConversation($this->tenantA->id);
        $record = $this->makeHandoffRecord($conv);
        $this->service->notifyHandoffRequired($conv, $record);

        $this->service->markAllRead($this->adminA->id);

        // adminA2 still has unread
        $this->assertEquals(1, $this->service->countUnread($this->adminA2->id));
    }

    // ── countUnread ──────────────────────────────────────────────────────

    public function test_count_unread_returns_correct_count(): void
    {
        Mail::fake();

        $conv   = $this->makeConversation($this->tenantA->id);
        $record = $this->makeHandoffRecord($conv);
        $this->service->notifyHandoffRequired($conv, $record);

        $waAccount = $this->makeWaAccount($this->tenantA->id);
        $this->service->notifyWaDisconnected($waAccount);

        $this->assertEquals(2, $this->service->countUnread($this->adminA->id));
    }

    public function test_count_unread_is_zero_after_mark_all_read(): void
    {
        Mail::fake();

        $conv   = $this->makeConversation($this->tenantA->id);
        $record = $this->makeHandoffRecord($conv);
        $this->service->notifyHandoffRequired($conv, $record);

        $this->service->markAllRead($this->adminA->id);
        $this->assertEquals(0, $this->service->countUnread($this->adminA->id));
    }

    // ── getUnread ────────────────────────────────────────────────────────

    public function test_get_unread_returns_only_notifications_for_that_user(): void
    {
        Mail::fake();

        $conv   = $this->makeConversation($this->tenantA->id);
        $record = $this->makeHandoffRecord($conv);
        $this->service->notifyHandoffRequired($conv, $record);

        $unreadA  = $this->service->getUnread($this->adminA->id);
        $unreadB  = $this->service->getUnread($this->adminB->id);

        $this->assertCount(1, $unreadA);
        $this->assertCount(0, $unreadB);
    }

    // ── tenant isolation ─────────────────────────────────────────────────

    public function test_tenant_isolation_notif_tenant_a_not_visible_to_admin_b(): void
    {
        Mail::fake();

        $conv   = $this->makeConversation($this->tenantA->id);
        $record = $this->makeHandoffRecord($conv);
        $this->service->notifyHandoffRequired($conv, $record);

        $this->assertEquals(0, $this->service->countUnread($this->adminB->id));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function makeConversation(string $tenantId, string $phone = '+628111222333'): Conversation
    {
        return Conversation::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $tenantId,
            'customer_phone' => $phone,
            'stage'          => ConversationStage::NEW_LEAD->value,
            'agent_mode'     => AgentMode::ACTIVE->value,
        ]);
    }

    private function makeHandoffRecord(Conversation $conv): HandoffRecord
    {
        return HandoffRecord::create([
            'id'              => Str::uuid()->toString(),
            'tenant_id'       => $conv->tenant_id,
            'conversation_id' => $conv->id,
            'status'          => HandoffStatus::PENDING->value,
            'priority'        => HandoffPriority::MEDIUM->value,
            'reason'          => 'Customer requested human agent',
        ]);
    }

    private function makeWaAccount(string $tenantId): WaAccount
    {
        return WaAccount::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $tenantId,
            'display_name' => 'CS Utama',
            'status'       => WaAccountStatus::DISCONNECTED->value,
        ]);
    }

    private function makeSuperadmin(): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'super@platform.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function makeAdminUser(string $tenantId, string $email): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Admin',
            'email'     => $email,
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::TENANT_ADMIN,
            'tenant_id' => $tenantId,
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
            'status'        => TenantStatus::TRIAL,
            'industry'      => 'wedding',
            'contact_email' => $slug . '@example.com',
            'created_by_id' => $createdBy->id,
        ]);
    }
}
