<?php

namespace App\Modules\Shared\Contracts;

use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\DTOs\DecisionDTO;

interface DecisionEngineInterface
{
    /** Apply PHP business rules to the turn context and return a decision. LLM must NOT be called here. */
    public function decide(TurnContextDTO $context): DecisionDTO;
}
