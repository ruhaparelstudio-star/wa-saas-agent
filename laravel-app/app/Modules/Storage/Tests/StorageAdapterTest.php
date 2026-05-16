<?php

namespace App\Modules\Storage\Tests;

use App\Modules\Shared\Contracts\StorageProviderInterface;
use App\Modules\Shared\Storage\NullStorageAdapter;
use App\Modules\Storage\Adapters\R2StorageAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_null_adapter_upload_returns_path_string(): void
    {
        $adapter = new NullStorageAdapter();
        $result  = $adapter->upload('file content', 'invoices/test.pdf');

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_null_adapter_get_url_returns_string(): void
    {
        $adapter = new NullStorageAdapter();
        $url     = $adapter->getUrl('invoices/test.pdf');

        $this->assertIsString($url);
        $this->assertStringContainsString('http', $url);
    }

    public function test_null_adapter_delete_returns_true(): void
    {
        $adapter = new NullStorageAdapter();
        $result  = $adapter->delete('invoices/test.pdf');

        $this->assertTrue($result);
    }

    public function test_r2_adapter_upload_stores_file(): void
    {
        Storage::fake('r2');

        $adapter = new R2StorageAdapter();
        $path    = 'invoices/test-tenant/INV-001.pdf';
        $result  = $adapter->upload('PDF content bytes', $path);

        $this->assertEquals($path, $result);
        Storage::disk('r2')->assertExists($path);
    }

    public function test_r2_adapter_get_url_uses_public_url_when_configured(): void
    {
        Storage::fake('r2');
        config(['filesystems.disks.r2.url' => 'https://cdn.example.com']);

        $adapter = new R2StorageAdapter();
        $url     = $adapter->getUrl('invoices/test.pdf');

        $this->assertStringContainsString('cdn.example.com', $url);
        $this->assertStringContainsString('invoices/test.pdf', $url);
    }

    public function test_r2_adapter_delete_removes_file(): void
    {
        Storage::fake('r2');
        Storage::disk('r2')->put('invoices/to-delete.pdf', 'content');

        $adapter = new R2StorageAdapter();
        $result  = $adapter->delete('invoices/to-delete.pdf');

        $this->assertTrue($result);
        Storage::disk('r2')->assertMissing('invoices/to-delete.pdf');
    }

    public function test_storage_provider_binding_resolves_to_null_adapter_by_default(): void
    {
        // In test environment, R2_ACCESS_KEY_ID is not set → NullStorageAdapter
        $storage = app(StorageProviderInterface::class);

        $this->assertInstanceOf(NullStorageAdapter::class, $storage);
    }
}
