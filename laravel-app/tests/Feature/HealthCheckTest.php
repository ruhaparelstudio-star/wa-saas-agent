<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_returns_200(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
        $response->assertSee('OK');
    }

    public function test_health_endpoint_does_not_require_auth(): void
    {
        // /up must be publicly accessible — no auth, no CSRF
        $response = $this->get('/up');

        $response->assertStatus(200);
    }
}
