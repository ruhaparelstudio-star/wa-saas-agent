<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Modules\AgentCore\Jobs\ProcessInboundMessageJob;
use App\Modules\Shared\DTOs\InboundMessageDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    /**
     * PRINSIP 13 — Contract: POST /webhook/inbound (WA Gateway → Laravel)
     *
     * Required fields: wa_account_id, from_phone, message_type, body, received_at
     * Required header: X-Internal-Secret
     *
     * Phase 3: tenant_id required until wa_accounts lookup is implemented in Phase 4.
     */
    public function inbound(Request $request): JsonResponse
    {
        $secret = config('services.wa_gateway.internal_secret', env('WA_INTERNAL_SECRET', ''));

        if ($secret && $request->header('X-Internal-Secret') !== $secret) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'wa_account_id'       => 'required|string',
            'tenant_id'           => 'required|string|uuid',
            'from_phone'          => 'required|string',
            'message_type'        => 'required|string|in:text,image,audio,document,video,sticker',
            'body'                => 'nullable|string',
            'media_url'           => 'nullable|string',
            'provider_message_id' => 'nullable|string',
            'received_at'         => 'required|string',
        ]);

        $inbound = InboundMessageDTO::from([
            'wa_account_id'       => $validated['wa_account_id'],
            'provider_message_id' => $validated['provider_message_id'] ?? Str::uuid()->toString(),
            'from_phone'          => $validated['from_phone'],
            'message_type'        => $validated['message_type'],
            'body'                => $validated['body'] ?? '',
            'media_url'           => $validated['media_url'] ?? null,
            'raw_payload'         => $validated,  // only validated scalar fields — safe to serialize
            'received_at'         => $validated['received_at'],
        ]);

        ProcessInboundMessageJob::dispatch($inbound, $validated['tenant_id']);

        return response()->json([
            'status'     => 'queued',
            'message_id' => $inbound->provider_message_id,
            'accepted'   => true,
        ]);
    }
}
