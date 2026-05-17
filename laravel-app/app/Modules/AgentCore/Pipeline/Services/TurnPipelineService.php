<?php

namespace App\Modules\AgentCore\Pipeline\Services;

use App\Modules\AgentCore\Classification\Services\IntentClassifierService;
use App\Modules\AgentCore\Composer\Services\ResponseComposerService;
use App\Modules\AgentCore\Decision\Services\DecisionEngineService;
use App\Modules\AgentCore\Extraction\Services\EntityExtractionService;
use App\Modules\AgentCore\LLM\Services\TokenUsageLogger;
use App\Modules\AgentCore\Security\Services\InputSanitizerService;
use App\Modules\AgentCore\Validators\ValidatorChainService;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Shared\Contracts\KnowledgeRetrieverInterface;
use App\Modules\Shared\DTOs\ConversationDTO;
use App\Modules\Shared\DTOs\ConversationStateDTO;
use App\Modules\Shared\DTOs\EntityResultDTO;
use App\Modules\Shared\DTOs\GroundedKnowledgeDTO;
use App\Modules\Shared\DTOs\InboundMessageDTO;
use App\Modules\Shared\DTOs\IntentResultDTO;
use App\Modules\Shared\DTOs\LeadProfileDTO;
use App\Modules\Shared\DTOs\TenantConfigDTO;
use App\Modules\Shared\DTOs\TenantDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\DTOs\TurnResultDTO;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\LeadTemperature;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\TenantConfig\Services\TenantConfigResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PRINSIP 2 — Urutan pipeline tidak boleh diubah.
 * Orchestrates all 14 steps from inbound message to DecisionTrace.
 */
class TurnPipelineService
{
    private const IDEMPOTENCY_TTL = 86400; // 24 hours
    private const LOCK_TTL        = 30;    // 30 seconds

    public function __construct(
        private readonly InputSanitizerService       $sanitizer,
        private readonly IntentClassifierService     $classifier,
        private readonly EntityExtractionService     $extractor,
        private readonly KnowledgeRetrieverInterface $knowledgeRetriever,
        private readonly DecisionEngineService       $decisionEngine,
        private readonly ValidatorChainService       $validatorChain,
        private readonly ResponseComposerService     $composer,
        private readonly ActionDispatcher            $dispatcher,
        private readonly DecisionTraceLogger         $traceLogger,
        private readonly ConversationRepository      $conversations,
        private readonly TokenUsageLogger            $tokenUsageLogger,
        private readonly TenantConfigResolver        $configResolver,
        private readonly NotificationService         $notificationService,
    ) {}

    /**
     * Process an inbound message through the full AI pipeline.
     *
     * PRINSIP 2 — 14 ordered steps, no shortcuts.
     */
    public function process(InboundMessageDTO $message, string $tenantId): TurnResultDTO
    {
        $startTime = microtime(true);

        // ── Step 0: Idempotency check ──────────────────────────────────────
        $idempotencyKey = "inbound:{$message->provider_message_id}";
        if (Cache::has($idempotencyKey)) {
            Log::info('TurnPipeline: duplicate message skipped', [
                'provider_message_id' => $message->provider_message_id,
            ]);
            return TurnResultDTO::from([
                'reply_sent'          => false,
                'actions_dispatched'  => [],
                'decision_trace_id'   => '',
                'conversation_id'     => '',
                'new_state'           => ConversationStateDTO::from([]),
                'processing_time_ms'  => 0,
            ]);
        }

        // ── Step 1: Acquire conversation lock ─────────────────────────────
        $lockKey = "conversation_lock:{$tenantId}:{$message->from_phone}";
        $lock    = Cache::lock($lockKey, self::LOCK_TTL);

        if (! $lock->get()) {
            Log::warning('TurnPipeline: cannot acquire lock, retry queued', [
                'tenant_id'  => $tenantId,
                'from_phone' => $message->from_phone,
            ]);
            // Re-throw to let the job retry
            throw new \RuntimeException('Cannot acquire conversation lock for ' . $message->from_phone);
        }

        try {
            return $this->runPipeline($message, $tenantId, $startTime, $idempotencyKey);
        } finally {
            $lock->release();
        }
    }

    private function runPipeline(
        InboundMessageDTO $message,
        string $tenantId,
        float $startTime,
        string $idempotencyKey,
    ): TurnResultDTO {
        $llmData     = [];
        $conversation = null;

        try {
            // ── Step 2: Find/create conversation ──────────────────────────
            $conversation = $this->conversations->findOrCreateByPhone($tenantId, $message->from_phone);
            $lead         = $conversation->lead;

            // ── Step 3: Handle media messages ─────────────────────────────
            if (in_array($message->message_type, ['audio', 'image', 'document', 'video'], true)) {
                return $this->handleMediaMessage($message, $tenantId, $conversation, $startTime, $idempotencyKey);
            }

            // ── Step 4: SanitizeInput ──────────────────────────────────────
            $sanitized = $this->sanitizer->sanitize(
                $message->body,
                $tenantId,
                $conversation->id,
            );

            if ($sanitized->injection_detected) {
                $this->notificationService->notifyInjectionAttempt($tenantId, $conversation->id);
            }

            // ── Step 5: Load tenant + config ──────────────────────────────
            $tenant    = Tenant::find($tenantId);
            $config    = $this->configResolver->resolve($tenantId);
            $tenantDto = $this->buildTenantDto($tenant);
            $configDto = $config;

            // ── Step 6: Build conversation context ────────────────────────
            $recentMessages = $conversation->getRecentMessages(5)
                ->map(fn ($m) => ['role' => $m->direction, 'body' => $m->body])
                ->values()
                ->toArray();

            // ── Step 7: IntentClassifierService ───────────────────────────
            $intentResult = $this->classifier->classify(
                $sanitized->sanitized_text,
                $tenantId,
                $recentMessages,
            );
            $llmData['intent_raw'] = $intentResult->raw_response;

            // ── Step 8: EntityExtractionService ───────────────────────────
            $existingEntities = $conversation->entity_cache ?? [];
            $entityResult     = $this->extractor->extract(
                $sanitized->sanitized_text,
                $tenantId,
                $existingEntities,
                $recentMessages,
            );
            $llmData['entity_raw'] = json_encode($entityResult->entities);

            // Merge new entities into conversation cache
            $conversation->updateEntityCache($entityResult->entities);
            $conversation->refresh();

            // Update lead from entities
            if ($lead) {
                $lead->updateFromEntities($entityResult->entities);
                $lead->refresh();
            }

            // ── Step 9: KnowledgeRetrieverInterface ───────────────────────
            $knowledge = $this->knowledgeRetriever->retrieve(
                $intentResult->intent,
                $entityResult->entities,
                $tenantId,
            );

            // ── Step 10: Complete TurnContextDTO ──────────────────────────
            $context = $this->buildContext(
                message: $message,
                tenantDto: $tenantDto,
                conversation: $conversation,
                lead: $lead,
                intentResult: $intentResult,
                entityResult: $entityResult,
                knowledge: $knowledge,
                configDto: $configDto,
                isSanitized: true,
                injectionDetected: $sanitized->injection_detected,
            );

            // ── Step 11: DecisionEngineService (PHP ONLY) ─────────────────
            $stageBefore = $conversation->stage->value;
            $decision    = $this->decisionEngine->decide($context);

            // ── Step 12–15: ValidatorChain ────────────────────────────────
            $validatorResult = $this->validatorChain->runAll($context, $decision);

            // Rebuild context with updated decision for composer
            $context = $this->rebuildContext($context, $decision, $validatorResult);

            // ── Step 13: ResponseComposerService ─────────────────────────
            $reply = $this->composer->compose($context, $decision, $validatorResult);
            $llmData['composer_raw'] = $reply->reply_text;

            // ── Step 14: ActionDispatcher ─────────────────────────────────
            $dispatched = $this->dispatcher->dispatch($context, $reply, $decision);

            $processingMs = (int) round((microtime(true) - $startTime) * 1000);

            $newConversation = $this->conversations->findById($conversation->id);
            $newState        = $this->buildState($newConversation ?? $conversation);

            $result = TurnResultDTO::from([
                'reply_sent'         => in_array('send_reply', $dispatched, true),
                'actions_dispatched' => $dispatched,
                'decision_trace_id'  => '',
                'conversation_id'    => $conversation->id,
                'new_state'          => $newState,
                'processing_time_ms' => $processingMs,
            ]);

            // Collect token totals
            $llmData['decision']         = $decision;
            $llmData['validator_result'] = $validatorResult;
            $llmData['composed_reply']   = $reply;
            $llmData['stage_before']     = $stageBefore;
            $llmData['token_totals']     = [
                'prompt'     => 0,
                'completion' => 0,
            ];

            // ── Step 15: DecisionTraceLogger ──────────────────────────────
            $trace = $this->traceLogger->log($context, $result, $llmData);

            // Set idempotency key after successful processing
            Cache::put($idempotencyKey, true, self::IDEMPOTENCY_TTL);

            // Return with trace ID
            return TurnResultDTO::from([
                'reply_sent'         => $result->reply_sent,
                'actions_dispatched' => $dispatched,
                'decision_trace_id'  => $trace->id,
                'conversation_id'    => $conversation->id,
                'new_state'          => $newState,
                'processing_time_ms' => $processingMs,
            ]);

        } catch (Throwable $e) {
            Log::error('TurnPipeline: exception', [
                'error'      => $e->getMessage(),
                'from_phone' => $message->from_phone,
                'tenant_id'  => $tenantId,
            ]);

            $processingMs = (int) round((microtime(true) - $startTime) * 1000);

            // Mark idempotency so we don't retry infinitely on the same bad message
            Cache::put($idempotencyKey, true, self::IDEMPOTENCY_TTL);

            // Build minimal fallback result
            $fallbackResult = TurnResultDTO::from([
                'reply_sent'         => false,
                'actions_dispatched' => [],
                'decision_trace_id'  => '',
                'conversation_id'    => $conversation?->id ?? '',
                'new_state'          => ConversationStateDTO::from([]),
                'processing_time_ms' => $processingMs,
            ]);

            // Try to log the error trace
            if ($conversation) {
                try {
                    $context = $this->buildFallbackContext($message, $tenantId, $conversation);
                    $llmData['error_message'] = $e->getMessage();
                    $trace = $this->traceLogger->log($context, $fallbackResult, $llmData);
                    $fallbackResult = TurnResultDTO::from([
                        'reply_sent'         => false,
                        'actions_dispatched' => [],
                        'decision_trace_id'  => $trace->id,
                        'conversation_id'    => $conversation->id,
                        'new_state'          => ConversationStateDTO::from([]),
                        'processing_time_ms' => $processingMs,
                    ]);
                } catch (Throwable) {
                    // Trace logging itself failed — don't cascade
                }

                // Send error fallback message
                try {
                    $this->sendFallbackReply($message, $tenantId, $conversation);
                } catch (Throwable) {
                    // Gateway failure is non-fatal
                }
            }

            return $fallbackResult;
        }
    }

    private function handleMediaMessage(
        InboundMessageDTO $message,
        string $tenantId,
        Conversation $conversation,
        float $startTime,
        string $idempotencyKey,
    ): TurnResultDTO {
        $presetText = match ($message->message_type) {
            'audio'                       => ResponseComposerService::VOICE_NOTE,
            'image', 'document', 'video'  => ResponseComposerService::IMAGE_ACK,
            default                       => ResponseComposerService::ERROR_FALLBACK,
        };

        $conversation->addMessage([
            'direction'    => 'outbound',
            'message_type' => 'text',
            'body'         => $presetText,
        ]);

        $processingMs = (int) round((microtime(true) - $startTime) * 1000);

        Cache::put($idempotencyKey, true, self::IDEMPOTENCY_TTL);

        return TurnResultDTO::from([
            'reply_sent'         => true,
            'actions_dispatched' => ['send_media_preset'],
            'decision_trace_id'  => '',
            'conversation_id'    => $conversation->id,
            'new_state'          => $this->buildState($conversation),
            'processing_time_ms' => $processingMs,
        ]);
    }

    private function sendFallbackReply(
        InboundMessageDTO $message,
        string $tenantId,
        Conversation $conversation,
    ): void {
        $conversation->addMessage([
            'direction'    => 'outbound',
            'message_type' => 'text',
            'body'         => ResponseComposerService::ERROR_FALLBACK,
        ]);
    }

    private function buildTenantDto(?Tenant $tenant): TenantDTO
    {
        return TenantDTO::from([
            'id'            => $tenant?->id ?? '',
            'name'          => $tenant?->name ?? '',
            'slug'          => $tenant?->slug ?? '',
            'status'        => $tenant?->status->value ?? 'active',
            'industry'      => $tenant?->industry ?? 'wedding',
            'contact_email' => $tenant?->contact_email ?? '',
            'contact_phone' => $tenant?->contact_phone ?? null,
            'created_at'    => $tenant?->created_at?->toISOString() ?? now()->toISOString(),
        ]);
    }

    private function buildContext(
        InboundMessageDTO $message,
        TenantDTO $tenantDto,
        Conversation $conversation,
        mixed $lead,
        IntentResultDTO $intentResult,
        EntityResultDTO $entityResult,
        GroundedKnowledgeDTO $knowledge,
        TenantConfigDTO $configDto,
        bool $isSanitized,
        bool $injectionDetected,
    ): TurnContextDTO {
        $convDto = ConversationDTO::from([
            'id'              => $conversation->id,
            'tenant_id'       => $conversation->tenant_id,
            'wa_account_id'   => $conversation->wa_account_id ?? '',
            'from_phone'      => $conversation->customer_phone,
            'stage'           => $conversation->stage?->value ?? 'new_lead',
            'agent_mode'      => $conversation->agent_mode?->value ?? 'active',
            'memory_mode'     => $conversation->memory_mode?->value ?? 'active',
            'context_summary' => $conversation->context_summary,
            'created_at'      => $conversation->created_at?->toISOString() ?? now()->toISOString(),
            'updated_at'      => $conversation->updated_at?->toISOString() ?? now()->toISOString(),
        ]);

        $leadDto = $lead
            ? $lead->toLeadProfileDTO()
            : LeadProfileDTO::from([]);

        return TurnContextDTO::from([
            'tenant'             => $tenantDto,
            'conversation'       => $convDto,
            'state'              => $this->buildState($conversation),
            'lead'               => $leadDto,
            'intent'             => $intentResult,
            'entities'           => $entityResult,
            'knowledge'          => $knowledge,
            'config'             => $configDto,
            'inbound_message'    => $message,
            'is_sanitized'       => $isSanitized,
            'injection_detected' => $injectionDetected,
        ]);
    }

    private function rebuildContext(
        TurnContextDTO $context,
        mixed $decision,
        mixed $validatorResult,
    ): TurnContextDTO {
        // Context is immutable — return as-is; the composer reads decision directly
        return $context;
    }

    private function buildFallbackContext(
        InboundMessageDTO $message,
        string $tenantId,
        Conversation $conversation,
    ): TurnContextDTO {
        $tenant    = Tenant::find($tenantId);
        $tenantDto = $this->buildTenantDto($tenant);
        $config    = $this->configResolver->resolve($tenantId);

        return TurnContextDTO::from([
            'tenant'             => $tenantDto,
            'conversation'       => ConversationDTO::from([
                'id'              => $conversation->id,
                'tenant_id'       => $conversation->tenant_id,
                'wa_account_id'   => $conversation->wa_account_id ?? '',
                'from_phone'      => $conversation->customer_phone,
                'stage'           => $conversation->stage?->value ?? 'new_lead',
                'agent_mode'      => $conversation->agent_mode?->value ?? 'active',
                'memory_mode'     => $conversation->memory_mode?->value ?? 'active',
                'context_summary' => null,
                'created_at'      => now()->toISOString(),
                'updated_at'      => now()->toISOString(),
            ]),
            'state'              => $this->buildState($conversation),
            'lead'               => LeadProfileDTO::from([]),
            'intent'             => IntentResultDTO::from(['intent' => 'unclear_message', 'confidence' => 0.0, 'reason' => 'error', 'raw_response' => '']),
            'entities'           => EntityResultDTO::from([]),
            'knowledge'          => GroundedKnowledgeDTO::from([]),
            'config'             => $config,
            'inbound_message'    => $message,
            'is_sanitized'       => false,
            'injection_detected' => false,
        ]);
    }

    private function buildState(Conversation $conversation): ConversationStateDTO
    {
        // DB defaults may not populate in-memory instance — use fallbacks for enums
        $temperature = $conversation->lead_temperature ?? LeadTemperature::COLD;

        return ConversationStateDTO::from([
            'stage'            => $conversation->stage?->value ?? 'new_lead',
            'agent_mode'       => $conversation->agent_mode?->value ?? 'active',
            'memory_mode'      => $conversation->memory_mode?->value ?? 'active',
            'lead_temperature' => $temperature->value,
            'entities'         => $conversation->entity_cache ?? [],
            'turn_count'       => $conversation->message_count ?? 0,
            'last_intent'      => null,
        ]);
    }

}
