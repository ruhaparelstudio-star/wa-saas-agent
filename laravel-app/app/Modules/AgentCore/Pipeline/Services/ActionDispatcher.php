<?php

namespace App\Modules\AgentCore\Pipeline\Services;

use App\Modules\Booking\Services\BookingService;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Handoff\Services\HandoffService;
use App\Modules\Knowledge\Services\PricelistService;
use App\Modules\Shared\Contracts\ChannelGatewayInterface;
use App\Modules\Shared\DTOs\ComposedReplyDTO;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use Illuminate\Support\Facades\Log;

class ActionDispatcher
{
    public function __construct(
        private readonly ?ChannelGatewayInterface $gateway,
        private readonly ConversationRepository $conversations,
        private readonly ?HandoffService $handoffService = null,
        private readonly ?PricelistService $pricelistService = null,
        private readonly ?BookingService $bookingService = null,
    ) {}

    /**
     * Dispatch reply and execute non-blocked actions from the decision.
     *
     * @return array<string> Names of actions successfully dispatched.
     */
    public function dispatch(
        TurnContextDTO $context,
        ComposedReplyDTO $reply,
        DecisionDTO $decision,
    ): array {
        $dispatched = [];

        $blockedNames = array_map(
            fn($b) => is_array($b) ? ($b['action'] ?? '') : $b->action,
            $decision->blocked_actions,
        );

        $pricelistAllowed = in_array('send_pricelist', $decision->desired_actions, true)
            && !in_array('send_pricelist', $blockedNames, true);

        $pricelistMode = $pricelistAllowed && $this->pricelistService !== null
            ? $this->pricelistService->getMode($context->tenant->id)
            : null;

        // For PDF mode, the file caption replaces the standalone text reply
        // to avoid sending the same message twice.
        $skipTextReply = $pricelistMode === PricelistService::MODE_PDF;

        if ($reply->reply_text !== '' && !$skipTextReply) {
            $sent = $this->sendReply($context, $reply);
            if ($sent) {
                $dispatched[] = 'send_reply';
            }

            $this->conversations->findById($context->conversation->id)?->addMessage([
                'direction'    => 'outbound',
                'message_type' => $reply->reply_type === 'text' ? 'text' : $reply->reply_type,
                'body'         => $reply->reply_text,
            ]);
        }

        foreach ($decision->desired_actions as $action) {
            if (in_array($action, $blockedNames, true)) {
                continue;
            }

            match ($action) {
                'update_stage'           => $this->updateConversationStage(
                    $context,
                    $decision->stage_transition ?? $context->conversation->stage->value,
                ),
                'update_lead'            => $this->updateLead($context),
                'flag_handoff'           => $this->flagHandoff($context, $decision),
                'send_pricelist'         => $this->sendPricelist($context, $reply, $pricelistMode),
                'create_booking'         => $this->createBooking($context, $decision),
                'increment_message_count' => null,
                default                  => null,
            };

            if (!in_array($action, ['update_stage', 'update_lead', 'flag_handoff', 'increment_message_count'], true)) {
                $dispatched[] = $action;
            } else {
                $dispatched[] = $action;
            }
        }

        if ($decision->stage_transition !== null) {
            $this->updateConversationStage($context, $decision->stage_transition);
            if (!in_array('update_stage', $dispatched, true)) {
                $dispatched[] = 'update_stage';
            }
        }

        // Backfill an existing DRAFT booking with any new entities the customer
        // provided this turn (e.g. event_type came in after the draft was created).
        // Only runs when the conversation already has a booking on file — never
        // creates a new one and never overwrites filled fields.
        if ($this->maybePatchDraftBooking($context)) {
            $dispatched[] = 'update_booking';
        }

        $this->conversations->findById($context->conversation->id)
            ?->update(['last_message_at' => now()]);

        return array_values(array_unique($dispatched));
    }

    private function maybePatchDraftBooking(TurnContextDTO $context): bool
    {
        if ($this->bookingService === null) {
            return false;
        }

        $conversation = $this->conversations->findById($context->conversation->id);
        if ($conversation === null) {
            return false;
        }

        $entityCache = $conversation->entity_cache ?? [];
        $bookingCode = $entityCache['last_booking_code'] ?? null;
        if (empty($bookingCode)) {
            return false;
        }

        $booking = \App\Modules\Booking\Models\Booking::withoutGlobalScopes()
            ->where('tenant_id', $context->tenant->id)
            ->where('booking_code', $bookingCode)
            ->first();

        if ($booking === null) {
            return false;
        }

        $turnEntities = $context->entities->entities ?? [];
        if ($turnEntities === []) {
            return false;
        }

        $beforeFields = $booking->only(['event_type', 'event_time_start', 'event_time_end', 'location', 'guest_count']);
        $patched      = $this->bookingService->updateDraftFromEntities($booking, $turnEntities);
        $afterFields  = $patched->only(['event_type', 'event_time_start', 'event_time_end', 'location', 'guest_count']);

        return $beforeFields !== $afterFields;
    }

    public function sendTextDirect(string $waAccountId, string $toPhone, string $body): bool
    {
        if ($this->gateway === null) {
            Log::info('ActionDispatcher: gateway not configured, skipping sendTextDirect.', [
                'wa_account_id' => $waAccountId,
                'to_phone'      => $toPhone,
            ]);
            return false;
        }

        try {
            return $this->gateway->sendText($waAccountId, $toPhone, $body);
        } catch (\Throwable $e) {
            Log::warning('ActionDispatcher: sendTextDirect failed.', [
                'error'         => $e->getMessage(),
                'wa_account_id' => $waAccountId,
            ]);
            return false;
        }
    }

    public function sendReply(TurnContextDTO $context, ComposedReplyDTO $reply): bool
    {
        $payload = [
            'wa_account_id' => $context->conversation->wa_account_id,
            'to_phone'      => $context->conversation->from_phone,
            'message_type'  => 'text',
            'body'          => $reply->reply_text,
        ];

        if ($this->gateway === null) {
            Log::info('ActionDispatcher: gateway not configured, skipping send.', $payload);
            return false;
        }

        try {
            return $this->gateway->sendText(
                $payload['wa_account_id'],
                $payload['to_phone'],
                $payload['body'],
            );
        } catch (\Throwable $e) {
            Log::warning('ActionDispatcher: gateway send failed.', [
                'error'         => $e->getMessage(),
                'wa_account_id' => $payload['wa_account_id'],
            ]);
            return false;
        }
    }

    public function updateConversationStage(TurnContextDTO $context, string $newStage): void
    {
        $conversation = $this->conversations->findById($context->conversation->id);
        if ($conversation === null) {
            return;
        }

        $old = $conversation->stage->value;
        $conversation->update(['stage' => $newStage]);

        Log::info('ActionDispatcher: stage transition.', [
            'conversation_id' => $context->conversation->id,
            'from'            => $old,
            'to'              => $newStage,
        ]);
    }

    private function updateLead(TurnContextDTO $context): void
    {
        $conversation = $this->conversations->findById($context->conversation->id);
        $lead = $conversation?->lead;
        if ($lead === null) {
            return;
        }

        $entities = $context->entities->entities ?? [];
        if (!empty($entities)) {
            $lead->updateFromEntities($entities);
        }
    }

    public function sendPricelist(
        TurnContextDTO $context,
        ComposedReplyDTO $reply,
        ?string $mode = null,
    ): bool {
        if ($this->pricelistService === null) {
            Log::info('ActionDispatcher: PricelistService not configured, skipping send_pricelist.');
            return false;
        }

        $mode ??= $this->pricelistService->getMode($context->tenant->id);

        // Text mode — already sent as part of the normal text reply
        if ($mode === PricelistService::MODE_TEXT) {
            return true;
        }

        if ($this->gateway === null) {
            Log::info('ActionDispatcher: gateway not configured, skipping send_pricelist file.');
            return false;
        }

        $asset = $this->pricelistService->getPricelistAsset($context->tenant->id);

        if ($asset === null) {
            Log::warning('ActionDispatcher: pricelist asset not found, falling back to text.', [
                'tenant_id' => $context->tenant->id,
                'mode'      => $mode,
            ]);

            // Fallback — make sure the customer receives the text body if the
            // PDF flow skipped it earlier.
            if ($mode === PricelistService::MODE_PDF && $reply->reply_text !== '') {
                $sent = $this->sendReply($context, $reply);
                if ($sent) {
                    $this->conversations->findById($context->conversation->id)?->addMessage([
                        'direction'    => 'outbound',
                        'message_type' => 'text',
                        'body'         => $reply->reply_text,
                    ]);
                }
            }

            return false;
        }

        $caption  = $mode === PricelistService::MODE_PDF ? $reply->reply_text : '';
        $fileUrl  = $this->rewriteAssetUrlForGateway($asset->file_url);

        try {
            $sent = $this->gateway->sendFile(
                $context->conversation->wa_account_id,
                $context->conversation->from_phone,
                $fileUrl,
                $caption,
            );
        } catch (\Throwable $e) {
            Log::warning('ActionDispatcher: sendPricelist file failed.', [
                'error'         => $e->getMessage(),
                'wa_account_id' => $context->conversation->wa_account_id,
            ]);
            return false;
        }

        if ($sent) {
            $body = $caption !== '' ? $caption : ('[pricelist] ' . $asset->name);
            $this->conversations->findById($context->conversation->id)?->addMessage([
                'direction'    => 'outbound',
                'message_type' => 'document',
                'body'         => $body,
            ]);
        }

        return $sent;
    }

    public function createBooking(TurnContextDTO $context, DecisionDTO $decision): void
    {
        if ($this->bookingService === null) {
            Log::info('ActionDispatcher: BookingService not configured, skipping create_booking.');
            return;
        }

        $entities = $context->entities->entities ?? [];
        if (empty($entities['event_date'])) {
            Log::info('ActionDispatcher: create_booking skipped — event_date missing.', [
                'conversation_id' => $context->conversation->id,
            ]);
            return;
        }

        $booking = $this->bookingService->createDraft($context);

        if ($booking === null) {
            Log::info('ActionDispatcher: create_booking — date unavailable.', [
                'tenant_id'  => $context->tenant->id,
                'event_date' => $entities['event_date'],
            ]);
            return;
        }

        // Store booking code in conversation metadata so the composer can reference it
        $conversation = $this->conversations->findById($context->conversation->id);
        if ($conversation !== null) {
            $meta = $conversation->entity_cache ?? [];
            $meta['last_booking_code'] = $booking->booking_code;
            $conversation->update(['entity_cache' => $meta]);
        }

        Log::info('ActionDispatcher: booking draft created.', [
            'booking_code' => $booking->booking_code,
        ]);
    }

    /**
     * Asset URLs are stored with the public APP_URL (e.g. http://localhost:8080),
     * which is unreachable from inside the wa-gateway container. Rewrite to the
     * docker-network internal base so Baileys can fetch the file.
     *
     * If wa_gateway.asset_internal_base is unset or the URL host already matches
     * an external CDN (R2/S3), the URL is returned untouched.
     */
    private function rewriteAssetUrlForGateway(string $url): string
    {
        if ($url === '') {
            return $url;
        }

        $internalBase = (string) config('services.wa_gateway.asset_internal_base', '');
        if ($internalBase === '') {
            return $url;
        }

        $appUrl = (string) config('app.url', '');
        if ($appUrl === '') {
            return $url;
        }

        $appHost = parse_url($appUrl, PHP_URL_HOST);
        $urlHost = parse_url($url, PHP_URL_HOST);

        // Only rewrite URLs that point at our own app — leave CDN/R2 URLs alone.
        if ($appHost === null || $urlHost === null || $appHost !== $urlHost) {
            return $url;
        }

        $path  = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return rtrim($internalBase, '/') . $path . ($query ? '?' . $query : '');
    }

    private function flagHandoff(TurnContextDTO $context, DecisionDTO $decision): void
    {
        $conversation = $this->conversations->findById($context->conversation->id);
        if ($conversation === null) {
            return;
        }

        if ($this->handoffService !== null) {
            $this->handoffService->triggerHandoff($conversation, $decision);
        } else {
            $conversation->update(['agent_mode' => \App\Modules\Shared\Enums\AgentMode::HANDOFF->value]);
            Log::info('ActionDispatcher: conversation flagged for handoff (no HandoffService).', [
                'conversation_id' => $context->conversation->id,
            ]);
        }
    }
}
