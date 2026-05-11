<?php

namespace App\Modules\Shared\Contracts;

use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\ValidatorResultDTO;
use App\Modules\Shared\DTOs\ComposedReplyDTO;

interface ResponseComposerInterface
{
    /** Compose a natural language reply using grounded knowledge and validated decision. */
    public function compose(TurnContextDTO $context, DecisionDTO $decision, ValidatorResultDTO $validatorResult): ComposedReplyDTO;
}
