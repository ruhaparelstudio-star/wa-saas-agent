<?php

namespace App\Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperadminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->isSuperadmin()) {
            return response()->json(['message' => 'Forbidden. Superadmin only.'], 403);
        }

        return $next($request);
    }
}
