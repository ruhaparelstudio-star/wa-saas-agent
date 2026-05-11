<?php

namespace App\Modules\Shared\Tests;

use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Shared\Models\TenantBaseModel;
use App\Modules\Shared\Scopes\TenantScope;
use Tests\TestCase;

class BaseModelTest extends TestCase
{
    public function test_base_model_is_not_incrementing(): void
    {
        $model = new ConcreteBaseModel();

        $this->assertFalse($model->getIncrementing());
        $this->assertEquals('string', $model->getKeyType());
    }

    public function test_base_model_has_uuid_trait(): void
    {
        $this->assertTrue(in_array(
            \App\Modules\Shared\Models\Traits\HasUuid::class,
            class_uses_recursive(ConcreteBaseModel::class)
        ));
    }

    public function test_tenant_base_model_has_tenant_scope_registered(): void
    {
        $model = new ConcreteTenantModel();
        $scopes = $model->getGlobalScopes();

        $this->assertArrayHasKey(TenantScope::class, $scopes);
    }

    public function test_tenant_base_model_has_tenant_column(): void
    {
        $model = new ConcreteTenantModel();

        $this->assertEquals('tenant_id', $model->tenantColumn);
    }

    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->get('/health');

        $response->assertStatus(200)
                 ->assertJson(['status' => 'ok', 'service' => 'app']);
    }

    public function test_health_queue_endpoint_returns_driver(): void
    {
        $response = $this->get('/health/queue');

        $response->assertStatus(200)
                 ->assertJsonStructure(['status', 'driver']);
    }
}

class ConcreteBaseModel extends BaseModel
{
    protected $table = 'test_items';
    protected $fillable = ['name'];
}

class ConcreteTenantModel extends TenantBaseModel
{
    protected $table = 'test_tenant_items';
    public string $tenantColumn = 'tenant_id';
    protected $fillable = ['name', 'tenant_id'];
}
