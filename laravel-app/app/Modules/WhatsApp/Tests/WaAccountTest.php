<?php

namespace App\Modules\WhatsApp\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Repositories\WaAccountRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WaAccountTest extends TestCase
{
    use RefreshDatabase;

    private WaAccountRepository $repo;
    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = app(WaAccountRepository::class);

        $superadmin = $this->makeSuperadmin();
        $this->actingAs($superadmin);

        $this->tenantA = $this->makeTenant('Tenant A', $superadmin);
        $this->tenantB = $this->makeTenant('Tenant B', $superadmin);
    }

    public function test_create_wa_account_with_disconnected_status(): void
    {
        $account = $this->repo->create($this->tenantA->id, 'CS Utama');

        $this->assertNotNull($account->id);
        $this->assertEquals($this->tenantA->id, $account->tenant_id);
        $this->assertEquals('CS Utama', $account->display_name);
        $this->assertEquals(WaAccountStatus::DISCONNECTED, $account->status);
        $this->assertNull($account->phone_number);
        $this->assertNull($account->qr_code);
        $this->assertEquals(0, $account->reconnect_attempts);
    }

    public function test_mark_qr_pending_sets_status_and_qr_code(): void
    {
        $account = $this->repo->create($this->tenantA->id, 'CS Utama');
        $qrBase64 = base64_encode('fake-qr-data');

        $account->markQrPending($qrBase64);
        $account->refresh();

        $this->assertEquals(WaAccountStatus::QR_PENDING, $account->status);
        $this->assertEquals($qrBase64, $account->qr_code);
        $this->assertNotNull($account->qr_expires_at);
        $this->assertTrue($account->qr_expires_at->isFuture());
        $this->assertTrue($account->qr_expires_at->diffInSeconds(now()) <= 300);
    }

    public function test_is_qr_expired_returns_false_when_fresh(): void
    {
        $account = $this->repo->create($this->tenantA->id, 'CS Utama');
        $account->markQrPending(base64_encode('fake-qr'));
        $account->refresh();

        $this->assertFalse($account->isQrExpired());
    }

    public function test_is_qr_expired_returns_true_after_expiry(): void
    {
        $account = $this->repo->create($this->tenantA->id, 'CS Utama');
        $account->markQrPending(base64_encode('fake-qr'));

        $account->update(['qr_expires_at' => now()->subMinute()]);
        $account->refresh();

        $this->assertTrue($account->isQrExpired());
    }

    public function test_mark_connected_sets_status_and_phone(): void
    {
        $account = $this->repo->create($this->tenantA->id, 'CS Utama');
        $account->markQrPending(base64_encode('fake-qr'));

        $account->markConnected('+6281234567890');
        $account->refresh();

        $this->assertEquals(WaAccountStatus::CONNECTED, $account->status);
        $this->assertEquals('+6281234567890', $account->phone_number);
        $this->assertNotNull($account->connected_at);
        $this->assertEquals(0, $account->reconnect_attempts);
        $this->assertTrue($account->isConnected());
    }

    public function test_mark_disconnected_clears_qr_code(): void
    {
        $account = $this->repo->create($this->tenantA->id, 'CS Utama');
        $account->markQrPending(base64_encode('fake-qr'));
        $account->markConnected('+6281234567890');

        $account->markDisconnected();
        $account->refresh();

        $this->assertEquals(WaAccountStatus::DISCONNECTED, $account->status);
        $this->assertNull($account->qr_code);
        $this->assertFalse($account->isConnected());
    }

    public function test_mark_failed_increments_reconnect_attempts(): void
    {
        $account = $this->repo->create($this->tenantA->id, 'CS Utama');
        $this->assertEquals(0, $account->reconnect_attempts);

        $account->markFailed();
        $account->refresh();

        $this->assertEquals(WaAccountStatus::FAILED, $account->status);
        $this->assertEquals(1, $account->reconnect_attempts);

        $account->markFailed();
        $account->refresh();

        $this->assertEquals(2, $account->reconnect_attempts);
    }

    public function test_find_by_tenant_does_not_return_other_tenant_accounts(): void
    {
        $this->repo->create($this->tenantA->id, 'CS A-1');
        $this->repo->create($this->tenantA->id, 'CS A-2');
        $this->repo->create($this->tenantB->id, 'CS B-1');

        $accountsA = $this->repo->findByTenant($this->tenantA->id);
        $accountsB = $this->repo->findByTenant($this->tenantB->id);

        $this->assertCount(2, $accountsA);
        $this->assertCount(1, $accountsB);

        foreach ($accountsA as $acc) {
            $this->assertEquals($this->tenantA->id, $acc->tenant_id);
        }
        foreach ($accountsB as $acc) {
            $this->assertEquals($this->tenantB->id, $acc->tenant_id);
        }
    }

    public function test_get_active_for_tenant_returns_first_connected(): void
    {
        $disconnected = $this->repo->create($this->tenantA->id, 'CS Offline');
        $connected1 = $this->repo->create($this->tenantA->id, 'CS Active 1');
        $connected1->markConnected('+6281111111111');
        $connected2 = $this->repo->create($this->tenantA->id, 'CS Active 2');
        $connected2->markConnected('+6282222222222');

        $active = $this->repo->getActiveForTenant($this->tenantA->id);

        $this->assertNotNull($active);
        $this->assertEquals(WaAccountStatus::CONNECTED, $active->status);
        $this->assertNull($this->repo->getActiveForTenant($this->tenantB->id));
    }

    public function test_session_data_is_stored_encrypted(): void
    {
        $account = $this->repo->create($this->tenantA->id, 'CS Utama');
        $plainSession = json_encode(['key' => 'secret-baileys-session-data']);

        $account->update(['session_data' => $plainSession]);

        $rawInDb = DB::table('wa_accounts')->where('id', $account->id)->value('session_data');

        $this->assertNotEquals($plainSession, $rawInDb);
        $this->assertNotNull($rawInDb);

        $account->refresh();
        $this->assertEquals($plainSession, $account->session_data);
    }

    public function test_to_status_dto_returns_correct_data(): void
    {
        $account = $this->repo->create($this->tenantA->id, 'CS Utama');
        $account->markConnected('+6281234567890');
        $account->refresh();

        $dto = $account->toStatusDTO();

        $this->assertEquals($account->id, $dto->id);
        $this->assertEquals($this->tenantA->id, $dto->tenant_id);
        $this->assertEquals('+6281234567890', $dto->phone_number);
        $this->assertEquals('CS Utama', $dto->display_name);
        $this->assertEquals('connected', $dto->status);
        $this->assertFalse($dto->is_qr_expired);
    }

    private function makeSuperadmin(string $email = 'admin@platform.com'): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => $email,
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function makeTenant(string $name, User $createdBy): Tenant
    {
        $slug = \Illuminate\Support\Str::slug($name);

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
