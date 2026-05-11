<?php

namespace App\Modules\Shared\Contracts;

use App\Modules\Shared\DTOs\LlmResponseDTO;
use App\Modules\Shared\DTOs\LlmEmbeddingDTO;

interface LlmClientInterface
{
    /** Send a prompt and return a text response. */
    public function complete(string $prompt, array $options = []): LlmResponseDTO;

    /** Send a prompt and return a decoded JSON array. */
    public function completeJson(string $prompt, array $options = []): array;

    /** Embed text into a vector. */
    public function embed(string $text): LlmEmbeddingDTO;
}
