<?php

namespace App\Modules\Auth\Tests;

use App\Modules\Auth\Http\Middleware\SuperadminOnly;
use App\Modules\Auth\Http\Middleware\TenantAdminOnly;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Shared\Enums\UserRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuthService $authService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authService = new AuthService();
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name'      => 'Test User',
            'email'     => 'test@example.com',
            'password'  => Hash::make('Password123!'),
            'role'      => UserRole::TENANT_ADMIN,
            'is_active' => true,
        ], $overrides));
    }

    public function test_superadmin_can_login_and_get_token(): void
    {
        $user = $this->makeUser([
            'email' => 'super@platform.com',
            'role'  => UserRole::SUPERADMIN,
        ]);

        $result = $this->authService->login('super@platform.com', 'Password123!');

        $this->assertArrayHasKey('token', $result);
        $this->assertNotEmpty($result['token']);
        $this->assertEquals(UserRole::SUPERADMIN, $result['user']->role);
    }

    public function test_tenant_admin_can_login_and_get_token(): void
    {
        $this->makeUser(['email' => 'tenant@vendor.com']);

        $result = $this->authService->login('tenant@vendor.com', 'Password123!');

        $this->assertArrayHasKey('token', $result);
        $this->assertNotEmpty($result['token']);
        $this->assertEquals(UserRole::TENANT_ADMIN, $result['user']->role);
    }

    public function test_wrong_password_throws_authentication_exception(): void
    {
        $this->makeUser();

        $this->expectException(AuthenticationException::class);

        $this->authService->login('test@example.com', 'WrongPassword!');
    }

    public function test_inactive_user_throws_authentication_exception(): void
    {
        $this->makeUser(['is_active' => false]);

        $this->expectException(AuthenticationException::class);

        $this->authService->login('test@example.com', 'Password123!');
    }

    public function test_token_valid_for_me_endpoint(): void
    {
        $this->makeUser(['email' => 'me@example.com']);

        $result   = $this->authService->login('me@example.com', 'Password123!');
        $response = $this->withToken($result['token'])->getJson('/api/auth/me');

        $response->assertOk()
                 ->assertJsonPath('email', 'me@example.com');
    }

    public function test_logout_invalidates_token(): void
    {
        $this->makeUser(['email' => 'logout@example.com']);

        $result = $this->authService->login('logout@example.com', 'Password123!');
        $token  = $result['token'];

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_superadmin_only_middleware_blocks_tenant_admin(): void
    {
        $tenantAdmin = $this->makeUser(['role' => UserRole::TENANT_ADMIN]);

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $tenantAdmin);

        $middleware = new SuperadminOnly();
        $response   = $middleware->handle($request, fn () => response()->json([], 200));

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_tenant_admin_only_middleware_blocks_superadmin(): void
    {
        $superadmin = $this->makeUser([
            'email' => 'super2@platform.com',
            'role'  => UserRole::SUPERADMIN,
        ]);

        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn () => $superadmin);

        $middleware = new TenantAdminOnly();
        $response   = $middleware->handle($request, fn () => response()->json([], 200));

        $this->assertEquals(403, $response->getStatusCode());
    }
}
