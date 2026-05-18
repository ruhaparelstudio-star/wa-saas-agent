<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('tenant.{tenantId}.quality', function ($user, string $tenantId) {
    return $user->tenant_id === $tenantId;
});

Broadcast::channel('tenant.{tenantId}.handoff', function ($user, string $tenantId) {
    return $user->tenant_id === $tenantId;
});
