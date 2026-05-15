<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalSecretMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.wa_gateway.internal_secret', env('WA_INTERNAL_SECRET', ''));

        if (empty($expected) || $request->header('X-Internal-Secret') !== $expected) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
