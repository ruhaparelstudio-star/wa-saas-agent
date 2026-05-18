<?php

namespace App\Modules\AgentCore\Summarization\Services;

use App\Modules\AgentCore\LLM\Services\TokenUsageLogger;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Shared\Contracts\LlmClientInterface;
use App\Modules\Shared\Enums\PolicyKey;
use App\Modules\TenantConfig\Services\TenantConfigResolver;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Summarize older conversation history into conversation.context_summary
 * so each turn's LLM calls retain awareness of context that no longer fits
 * in the recent-messages window.
 *
 * PRINSIP 1 — the summary is text only; it never produces business decisions.
 * PRINSIP 7 — all parameters (threshold, keep_recent) read from tenant policies.
 */
class ConversationSummarizerService
{
    private const PROMPT_TEMPLATE = <<<'PROMPT'
You are a conversation summarizer for a WhatsApp wedding-vendor sales agent.
Read the conversation transcript below (oldest first) and produce a CONDENSED
factual summary in Indonesian. The summary will be re-injected into future
turns so the agent stays aware of what was already discussed.

Rules:
- Maximum 200 words.
- Bullet-style, terse. No greetings, no preamble.
- Capture: customer's name (if shared), event date / type / guest count /
  budget / location / package interest, things already promised or sent
  (e.g. pricelist shared, availability checked), open questions, and any
  objections raised.
- Do NOT invent facts. If unsure, omit.
- Do NOT include emoji or honorifics in the summary.

%PREVIOUS_SUMMARY_BLOCK%

Transcript (oldest first):
%TRANSCRIPT%

Output the summary now (plain Indonesian text, no markdown):
PROMPT;

    public function __construct(
        private readonly LlmClientInterface   $llm,
        private readonly TokenUsageLogger     $tokenUsageLogger,
        private readonly TenantConfigResolver $configResolver,
    ) {}

    /**
     * Decide if the conversation is long enough to warrant summarization.
     */
    public function shouldSummarize(Conversation $conversation): bool
    {
        $threshold = $this->resolveInt(
            $conversation->tenant_id,
            PolicyKey::CONTEXT_SUMMARY_THRESHOLD->value,
            40,
        );

        $count = (int) ($conversation->message_count ?? 0);

        return $count >= $threshold;
    }

    /**
     * Build a fresh summary for the conversation and persist it to
     * conversation.context_summary. Messages newer than `keep_recent`
     * are excluded — they still live in the live recent-messages window.
     */
    public function summarize(Conversation $conversation): ?string
    {
        $tenantId   = $conversation->tenant_id;
        $keepRecent = $this->resolveInt(
            $tenantId,
            PolicyKey::CONTEXT_SUMMARY_KEEP_RECENT->value,
            20,
        );

        $allMessages = $conversation->messages()
            ->orderBy('created_at', 'asc')
            ->get(['direction', 'body', 'created_at']);

        if ($allMessages->count() <= $keepRecent) {
            return $conversation->context_summary;
        }

        $toSummarize = $allMessages->slice(0, $allMessages->count() - $keepRecent);

        $transcript = $toSummarize
            ->map(function ($msg) {
                $speaker = $msg->direction === 'outbound' ? 'agent' : 'customer';
                $body    = trim((string) $msg->body);
                return $body === '' ? null : "[{$speaker}] {$body}";
            })
            ->filter()
            ->implode("\n");

        if ($transcript === '') {
            return $conversation->context_summary;
        }

        $previousSummary = trim((string) ($conversation->context_summary ?? ''));
        $previousBlock   = $previousSummary === ''
            ? ''
            : "Previous summary (extend / refine; do not duplicate facts):\n{$previousSummary}\n";

        $prompt = str_replace(
            ['%PREVIOUS_SUMMARY_BLOCK%', '%TRANSCRIPT%'],
            [$previousBlock, $transcript],
            self::PROMPT_TEMPLATE,
        );

        try {
            $response = $this->llm->complete($prompt, [
                'model'       => config('llm.classifier_model'),
                'temperature' => 0.1,
            ]);

            $summary = trim($response->content);
            if ($summary === '') {
                return $conversation->context_summary;
            }

            $conversation->update(['context_summary' => $summary]);
            $this->tokenUsageLogger->log($tenantId, 'context_summary', $response);

            return $summary;
        } catch (Throwable $e) {
            Log::warning('ConversationSummarizerService: summarization failed', [
                'tenant_id'       => $tenantId,
                'conversation_id' => $conversation->id,
                'error'           => $e->getMessage(),
            ]);
            return $conversation->context_summary;
        }
    }

    private function resolveInt(string $tenantId, string $key, int $default): int
    {
        $raw = $this->configResolver->get($tenantId, $key, (string) $default);
        $value = (int) $raw;
        return $value > 0 ? $value : $default;
    }
}
