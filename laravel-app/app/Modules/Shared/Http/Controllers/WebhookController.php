<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Modules\AgentCore\Jobs\ProcessInboundMessageJob;
use App\Modules\Shared\DTOs\InboundMessageDTO;
use App\Modules\Shared\Services\PhoneNormalizer;
use App\Modules\WhatsApp\Models\WaAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    /**
     * PRINSIP 13 — Contract: POST /webhook/inbound (WA Gateway → Laravel)
     *
     * Required fields: wa_account_id, from_phone, message_type, body, received_at
     * Required header: X-Internal-Secret
     *
     * tenant_id is looked up from wa_accounts using wa_account_id — not required in payload.
     */
    public function inbound(Request $request): JsonResponse
    {
        $secret = config('services.wa_gateway.internal_secret', env('WA_INTERNAL_SECRET', ''));

        if ($secret && $request->header('X-Internal-Secret') !== $secret) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'wa_account_id'       => 'required|string',
            'from_phone'          => 'required|string',
            'message_type'        => 'required|string|in:text,image,audio,document,video,sticker',
            'body'                => 'nullable|string',
            'media_url'           => 'nullable|string',
            'provider_message_id' => 'nullable|string',
            'received_at'         => 'required|string',
        ]);

        $waAccount = WaAccount::withoutGlobalScopes()
            ->where('id', $validated['wa_account_id'])
            ->first();

        if (!$waAccount) {
            return response()->json(['error' => 'WA account not found'], 404);
        }

        try {
            $normalizedPhone = PhoneNormalizer::normalize($validated['from_phone']);
        } catch (\InvalidArgumentException $e) {
            Log::warning('Webhook rejected malformed from_phone', [
                'raw' => $validated['from_phone'],
                'wa_account_id' => $validated['wa_account_id'],
            ]);
            return response()->json(['error' => 'Invalid from_phone'], 422);
        }

        $inbound = InboundMessageDTO::from([
            'wa_account_id'       => $validated['wa_account_id'],
            'provider_message_id' => $validated['provider_message_id'] ?? Str::uuid()->toString(),
            'from_phone'          => $normalizedPhone,
            'message_type'        => $validated['message_type'],
            'body'                => $validated['body'] ?? '',
            'media_url'           => $validated['media_url'] ?? null,
            'raw_payload'         => $validated,
            'received_at'         => $validated['received_at'],
        ]);

        ProcessInboundMessageJob::dispatch($inbound, $waAccount->tenant_id);

        return response()->json([
            'status'     => 'queued',
            'message_id' => $inbound->provider_message_id,
            'accepted'   => true,
        ]);
    }
}
