<?php

namespace App\Modules\AgentCore\Pipeline\Services;

use App\Modules\AgentCore\Classification\Services\IntentClassifierService;
use App\Modules\AgentCore\Composer\Services\ResponseComposerService;
use App\Modules\AgentCore\Decision\Services\DecisionEngineService;
use App\Modules\AgentCore\Extraction\Services\EntityExtractionService;
use App\Modules\AgentCore\LLM\Services\TokenUsageLogger;
use App\Modules\AgentCore\Security\Services\InputSanitizerService;
use App\Modules\AgentCore\Summarization\Jobs\SummarizeConversationJob;
use App\Modules\AgentCore\Summarization\Services\ConversationSummarizerService;
use App\Modules\AgentCore\Validators\ValidatorChainService;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\QualityGuard\Enums\QualitySeverity;
use App\Modules\QualityGuard\Events\QualityIssueDetected;
use App\Modules\QualityGuard\Jobs\GradeReplyWithLlmJob;
use App\Modules\QualityGuard\Models\ConversationQualityIssue;
use App\Modules\QualityGuard\Services\ConversationQualityGuard;
use App\Modules\Shared\Contracts\KnowledgeRetrieverInterface;
use App\Modules\Shared\DTOs\ComposedReplyDTO;
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
        private readonly ConversationSummarizerService $summarizer,
        private readonly ConversationQualityGuard    $qualityGuard,
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
            $conversation = $this->conversations->findOrCreateByPhone($tenantId, $message->from_phone, $message->wa_account_id);
            $lead         = $conversation->lead;

            // ── Save inbound message ───────────────────────────────────────
            $inboundRecord = $conversation->addMessage([
                'direction'            => 'inbound',
                'message_type'         => $message->message_type,
                'body'                 => $message->body,
                'media_url'            => $message->media_url,
                'provider_message_id'  => $message->provider_message_id,
                'is_injection_attempt' => false,
            ]);

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
                $inboundRecord->update(['is_injection_attempt' => true]);
                $this->notificationService->notifyInjectionAttempt($tenantId, $conversation->id);
            }

            // ── Step 5: Load tenant + config ──────────────────────────────
            $tenant    = Tenant::find($tenantId);
            $config    = $this->configResolver->resolve($tenantId);
            $tenantDto = $this->buildTenantDto($tenant);
            $configDto = $config;

            // ── Step 6: Build conversation context ────────────────────────
            // Composer needs the wider window for continuity/repetition checks.
            // Classifier/extractor use a smaller window — they don't need deep
            // history, just enough for pronoun + correction resolution.
            $composerWindow   = $this->resolveContextWindow($tenantId, 'composer_context_window', 20);
            $classifierWindow = $this->resolveContextWindow($tenantId, 'classifier_context_window', 10);

            $recentMessages = $conversation->getRecentMessages($composerWindow)
                ->map(fn ($m) => ['role' => $m->direction, 'direction' => $m->direction, 'body' => $m->body])
                ->values()
                ->toArray();

            $classifierContext = $classifierWindow >= count($recentMessages)
                ? $recentMessages
                : array_slice($recentMessages, -$classifierWindow);

            $contextSummary = $conversation->context_summary;

            // ── Step 7: IntentClassifierService ───────────────────────────
            $intentResult = $this->classifier->classify(
                $sanitized->sanitized_text,
                $tenantId,
                $classifierContext,
                $contextSummary,
            );
            $llmData['intent_raw']    = $intentResult->raw_response;
            $llmData['intent_prompt'] = $this->classifier->getLastPrompt();

            // ── Step 8: EntityExtractionService ───────────────────────────
            $existingEntities = $conversation->entity_cache ?? [];
            $entityResult     = $this->extractor->extract(
                $sanitized->sanitized_text,
                $tenantId,
                $existingEntities,
                $classifierContext,
                $contextSummary,
            );
            $llmData['entity_raw']    = json_encode($entityResult->entities);
            $llmData['entity_prompt'] = $this->extractor->getLastPrompt();

            // Merge new entities into conversation cache
            $conversation->updateEntityCache($entityResult->entities);
            $conversation->refresh();

            // Update lead from entities
            if ($lead) {
                $lead->updateFromEntities($entityResult->entities);
                $lead->refresh();
            }

            // Sync extracted entities to conversation columns (customer_name, customer_email).
            // Prevents the bug where leads.customer_name = "Aris" but conversations.customer_name = NULL.
            $conversation->updateFromEntities($entityResult->entities);
            $conversation->refresh();

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
                recentMessages: $recentMessages,
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
            $llmData['composer_raw']    = $reply->reply_text;
            $llmData['composer_prompt'] = $this->composer->getLastPrompt();

            // ── Step 13b: ConversationQualityGuard ───────────────────────
            // Pre-send severity-based safety net. CRITICAL violations block the
            // reply and force the conversation into HANDOFF so the customer
            // never sees hallucinated facts or unfulfilled promises.
            $verdict = $this->qualityGuard->evaluate($context, $decision, $reply);
            $guardVerdict = 'pass';

            foreach ($verdict->violations as $violation) {
                $issue = ConversationQualityIssue::create([
                    'tenant_id'         => $tenantId,
                    'conversation_id'   => $conversation->id,
                    'decision_trace_id' => null,
                    'code'              => $violation->code->value,
                    'severity'          => $violation->severity->value,
                    'source'            => 'guard',
                    'message'           => $violation->message,
                    'evidence'          => $violation->evidence,
                    'blocked'           => $verdict->should_block && $violation->severity === QualitySeverity::CRITICAL,
                ]);

                if ($violation->severity === QualitySeverity::HIGH) {
                    try {
                        $this->notificationService->notifyQualityIssue($conversation, $violation);
                    } catch (\Throwable $e) {
                        Log::warning('notifyQualityIssue failed', ['error' => $e->getMessage()]);
                    }
                }

                // Broadcast event (deferred to next pass — guarded by try-catch).
            }

            $llmData['quality_violations'] = array_map(fn ($v) => $v->toArray(), $verdict->violations);
            $llmData['reply_overridden']   = false;

            if ($verdict->should_block) {
                $guardVerdict = 'blocked';
                $reply = ComposedReplyDTO::from([
                    'reply_text'             => $verdict->override_reply,
                    'reply_type'             => 'text',
                    'attachments'             => [],
                    'grounding_refs'         => [],
                    'detected_hallucination' => true,
                ]);
                $decision = $decision->withForcedHandoff(
                    reason: 'QualityGuard blocked: ' . implode(', ', $verdict->criticalCodes()),
                );
                $llmData['reply_overridden'] = true;
            } elseif (count($verdict->violations) > 0) {
                $guardVerdict = 'warn';
            }
            $llmData['guard_verdict'] = $guardVerdict;

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

            // ── Step 15b: LLM Reply Grader (async, tenant-gated) ──────────
            // Fire-and-forget. The job is responsible for tenant flag + throttle.
            try {
                GradeReplyWithLlmJob::dispatch($trace->id);
            } catch (\Throwable $e) {
                Log::warning('GradeReplyWithLlmJob dispatch failed', ['error' => $e->getMessage()]);
            }

            // ── Step 16: Summarize long conversations ─────────────────────
            // When the conversation exceeds the configured threshold, queue a
            // background summary refresh so subsequent turns can read it from
            // conversation.context_summary. Dispatched after the reply is sent
            // so it never blocks the customer-facing latency path.
            $fresh = $this->conversations->findById($conversation->id) ?? $conversation;
            if ($this->summarizer->shouldSummarize($fresh)) {
                SummarizeConversationJob::dispatch($fresh->id);
            }

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
        $presetText  = match ($message->message_type) {
            'audio'                       => ResponseComposerService::VOICE_NOTE,
            'image', 'document', 'video'  => ResponseComposerService::IMAGE_ACK,
            default                       => ResponseComposerService::ERROR_FALLBACK,
        };
        $waAccountId = $conversation->wa_account_id ?? $message->wa_account_id;

        $this->dispatcher->sendTextDirect($waAccountId, $message->from_phone, $presetText);

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
        $body        = ResponseComposerService::ERROR_FALLBACK;
        $waAccountId = $conversation->wa_account_id ?? $message->wa_account_id;

        $this->dispatcher->sendTextDirect($waAccountId, $message->from_phone, $body);

        $conversation->addMessage([
            'direction'    => 'outbound',
            'message_type' => 'text',
            'body'         => $body,
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
        array $recentMessages = [],
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
            'recent_messages'    => $recentMessages,
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

    /**
     * Resolve a numeric context-window policy with a safe floor/ceiling.
     * Stored as string in tenant_policies; cast to int and clamp to [1, 100].
     */
    private function resolveContextWindow(string $tenantId, string $policyKey, int $default): int
    {
        $raw = $this->configResolver->get($tenantId, $policyKey, (string) $default);
        $value = (int) $raw;
        if ($value < 1) {
            return $default;
        }
        return min(100, $value);
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
