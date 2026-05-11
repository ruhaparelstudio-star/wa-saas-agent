<?php

namespace App\Modules\Shared\Contracts;

use App\Modules\Shared\DTOs\GroundedKnowledgeDTO;

interface KnowledgeRetrieverInterface
{
    /** Retrieve grounded knowledge relevant to the current intent and entities. */
    public function retrieve(string $intent, array $entities, string $tenantId): GroundedKnowledgeDTO;
}
