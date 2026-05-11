<?php

namespace App\Modules\Shared\Contracts;

use App\Modules\Shared\DTOs\IntentResultDTO;

interface IntentClassifierInterface
{
    /** Classify the intent of an inbound message. */
    public function classify(string $message, string $tenantId, array $conversationContext = []): IntentResultDTO;
}
