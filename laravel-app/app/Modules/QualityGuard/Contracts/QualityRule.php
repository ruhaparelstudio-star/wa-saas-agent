<?php

namespace App\Modules\QualityGuard\Contracts;

use App\Modules\QualityGuard\DTOs\QualityViolationDTO;
use App\Modules\QualityGuard\Enums\QualityIssueCode;
use App\Modules\Shared\DTOs\ComposedReplyDTO;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;

interface QualityRule
{
    public function code(): QualityIssueCode;

    /**
     * Synchronous evaluation during pipeline (full context available).
     */
    public function evaluate(
        TurnContextDTO $context,
        DecisionDTO $decision,
        ComposedReplyDTO $reply,
    ): ?QualityViolationDTO;

    /**
     * Post-hoc evaluation against a DecisionTrace row (DB-only, no live context).
     * Used by patrol and auto-resolve.
     *
     * @param  object  $trace  DecisionTrace model instance
     */
    public function evaluateAgainstTrace(object $trace): ?QualityViolationDTO;
}
