<?php

namespace App\Modules\AgentCore\LLM\Adapters;

use App\Modules\Shared\Contracts\LlmClientInterface;
use App\Modules\Shared\DTOs\LlmEmbeddingDTO;
use App\Modules\Shared\DTOs\LlmResponseDTO;
use RuntimeException;

/**
 * PRINSIP 9 — Unit test TIDAK BOLEH call real OpenAI API.
 * Inject adapter ini via service container in tests.
 *
 * Usage in test setUp():
 *   $this->mock = new MockLlmAdapter();
 *   $this->mock->setNextResponse(json_encode(['intent' => 'greeting']));
 *   app()->instance(LlmClientInterface::class, $this->mock);
 */
class MockLlmAdapter implements LlmClientInterface
{
    private array $responseQueue = [];
    private int $callCount = 0;
    private string $lastPrompt = '';

    public function setNextResponse(string $json): void
    {
        $this->responseQueue[] = $json;
    }

    public function setNextResponses(array $jsons): void
    {
        foreach ($jsons as $json) {
            $this->responseQueue[] = $json;
        }
    }

    public function getCallCount(): int
    {
        return $this->callCount;
    }

    public function getLastPrompt(): string
    {
        return $this->lastPrompt;
    }

    public function reset(): void
    {
        $this->responseQueue = [];
        $this->callCount     = 0;
        $this->lastPrompt    = '';
    }

    public function complete(string $prompt, array $options = []): LlmResponseDTO
    {
        $this->lastPrompt = $prompt;
        $this->callCount++;

        $content = $this->dequeue();

        return LlmResponseDTO::from([
            'content'           => $content,
            'model'             => 'mock',
            'prompt_tokens'     => str_word_count($prompt),
            'completion_tokens' => str_word_count($content),
            'total_tokens'      => str_word_count($prompt) + str_word_count($content),
            'finish_reason'     => 'stop',
        ]);
    }

    public function completeJson(string $prompt, array $options = []): array
    {
        $response = $this->complete($prompt, $options);
        $decoded  = json_decode($response->content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("MockLlmAdapter: response is not valid JSON: {$response->content}");
        }

        return $decoded;
    }

    public function embed(string $text): LlmEmbeddingDTO
    {
        $this->lastPrompt = $text;
        $this->callCount++;

        return LlmEmbeddingDTO::from([
            'embedding'    => array_fill(0, 1536, 0.0),
            'model'        => 'mock',
            'total_tokens' => str_word_count($text),
        ]);
    }

    private function dequeue(): string
    {
        if (empty($this->responseQueue)) {
            throw new RuntimeException(
                'MockLlmAdapter: no response queued. Call setNextResponse() before complete().'
            );
        }

        return array_shift($this->responseQueue);
    }
}
