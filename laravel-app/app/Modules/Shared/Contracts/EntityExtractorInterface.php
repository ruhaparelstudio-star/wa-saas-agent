<?php

namespace App\Modules\Shared\Contracts;

use App\Modules\Shared\DTOs\EntityResultDTO;

interface EntityExtractorInterface
{
    /** Extract wedding entities from a message. */
    public function extract(string $message, string $tenantId, array $existingEntities = [], array $context = []): EntityResultDTO;
}
