<?php

namespace App\Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantAdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->isTenantAdmin()) {
            return response()->json(['message' => 'Forbidden. Tenant admin only.'], 403);
        }

        return $next($request);
    }
}
