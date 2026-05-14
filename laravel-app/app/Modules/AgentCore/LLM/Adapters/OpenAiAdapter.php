<?php

namespace App\Modules\AgentCore\LLM\Adapters;

use App\Modules\AgentCore\LLM\Exceptions\LlmException;
use App\Modules\AgentCore\LLM\Exceptions\LlmJsonParseException;
use App\Modules\AgentCore\LLM\JsonRepairGuard;
use App\Modules\Shared\Contracts\LlmClientInterface;
use App\Modules\Shared\DTOs\LlmEmbeddingDTO;
use App\Modules\Shared\DTOs\LlmResponseDTO;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiAdapter implements LlmClientInterface
{
    private string $apiKey;
    private string $classifierModel;
    private string $composerModel;
    private string $embeddingModel;
    private int $timeoutSeconds;
    private int $maxRetries;

    public function __construct()
    {
        $this->apiKey          = config('services.openai.key', '');
        $this->classifierModel = config('llm.classifier_model', 'gpt-4o-mini');
        $this->composerModel   = config('llm.composer_model', 'gpt-4o');
        $this->embeddingModel  = config('llm.embedding_model', 'text-embedding-3-small');
        $this->timeoutSeconds  = config('llm.timeout_seconds', 30);
        $this->maxRetries      = config('llm.max_retries', 2);
    }

    public function complete(string $prompt, array $options = []): LlmResponseDTO
    {
        $model       = $options['model'] ?? $this->classifierModel;
        $temperature = $options['temperature'] ?? 0.1;
        $maxTokens   = $options['max_tokens'] ?? 1024;

        $payload = [
            'model'       => $model,
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
            'messages'    => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        if (isset($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }

        $attempts = 0;
        $lastException = null;

        while ($attempts <= $this->maxRetries) {
            try {
                $response = Http::withToken($this->apiKey)
                    ->timeout($this->timeoutSeconds)
                    ->post('https://api.openai.com/v1/chat/completions', $payload);

                if ($response->failed()) {
                    $status = $response->status();
                    // Rate limit: retry
                    if ($status === 429) {
                        $attempts++;
                        if ($attempts <= $this->maxRetries) {
                            sleep((int) pow(2, $attempts - 1));
                            continue;
                        }
                    }
                    throw new LlmException("OpenAI API error {$status}: " . $response->body());
                }

                $body = $response->json();

                return LlmResponseDTO::from([
                    'content'           => $body['choices'][0]['message']['content'] ?? '',
                    'model'             => $body['model'] ?? $model,
                    'prompt_tokens'     => $body['usage']['prompt_tokens'] ?? 0,
                    'completion_tokens' => $body['usage']['completion_tokens'] ?? 0,
                    'total_tokens'      => $body['usage']['total_tokens'] ?? 0,
                    'finish_reason'     => $body['choices'][0]['finish_reason'] ?? 'stop',
                ]);
            } catch (ConnectionException $e) {
                $lastException = $e;
                $attempts++;
                if ($attempts <= $this->maxRetries) {
                    sleep((int) pow(2, $attempts - 1));
                }
            }
        }

        throw new LlmException(
            "OpenAI request failed after {$this->maxRetries} retries: " . ($lastException?->getMessage() ?? 'unknown error'),
            0,
            $lastException
        );
    }

    public function completeJson(string $prompt, array $options = []): array
    {
        $options['response_format'] = ['type' => 'json_object'];
        $response = $this->complete($prompt, $options);

        if (JsonRepairGuard::isValidJson($response->content)) {
            return json_decode($response->content, true);
        }

        return JsonRepairGuard::repair($response->content);
    }

    public function embed(string $text): LlmEmbeddingDTO
    {
        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeoutSeconds)
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => $this->embeddingModel,
                'input' => $text,
            ]);

        if ($response->failed()) {
            throw new LlmException("OpenAI embedding error {$response->status()}: " . $response->body());
        }

        $body = $response->json();

        return LlmEmbeddingDTO::from([
            'embedding'    => $body['data'][0]['embedding'] ?? [],
            'model'        => $body['model'] ?? $this->embeddingModel,
            'total_tokens' => $body['usage']['total_tokens'] ?? 0,
        ]);
    }
}
