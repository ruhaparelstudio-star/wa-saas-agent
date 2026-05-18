<?php

namespace App\Modules\AgentCore\Summarization\Jobs;

use App\Modules\AgentCore\Summarization\Services\ConversationSummarizerService;
use App\Modules\Conversation\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SummarizeConversationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int   $timeout = 60;
    public int   $tries   = 2;
    public array $backoff = [10, 60];

    public function __construct(public string $conversationId)
    {
        $this->onQueue('default');
    }

    public function handle(ConversationSummarizerService $summarizer): void
    {
        // De-dup: skip if another summarization just ran for this conversation.
        $lockKey = "summarize_lock:{$this->conversationId}";
        if (! Cache::add($lockKey, 1, 60)) {
            return;
        }

        try {
            $conversation = Conversation::withoutGlobalScopes()->find($this->conversationId);
            if (! $conversation) {
                return;
            }

            $summarizer->summarize($conversation);
        } catch (Throwable $e) {
            Log::warning('SummarizeConversationJob: failed', [
                'conversation_id' => $this->conversationId,
                'error'           => $e->getMessage(),
            ]);
        }
    }
}
