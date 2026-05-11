<?php

namespace App\Modules\Shared\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WebhookController extends Controller
{
    /**
     * PRINSIP 13 — Contract: POST /webhook/inbound (WA Gateway → Laravel)
     *
     * Required fields: wa_account_id, from_phone, message_type, body, received_at
     * Required header: X-Internal-Secret
     *
     * Full processing implemented in Phase 4 (InboundProcessor).
     */
    public function inbound(Request $request): JsonResponse
    {
        $secret = config('services.wa_gateway.internal_secret', env('WA_INTERNAL_SECRET', ''));

        if ($secret && $request->header('X-Internal-Secret') !== $secret) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'wa_account_id' => 'required|string',
            'from_phone'    => 'required|string',
            'message_type'  => 'required|string',
            'body'          => 'nullable|string',
            'received_at'   => 'required|string',
        ]);

        // Phase 4: dispatch ProcessInboundMessageJob here
        return response()->json(['accepted' => true, 'wa_account_id' => $validated['wa_account_id']]);
    }
}
