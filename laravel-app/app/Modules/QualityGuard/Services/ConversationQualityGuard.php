<?php

namespace App\Modules\QualityGuard\Services;

use App\Modules\QualityGuard\Contracts\QualityRule;
use App\Modules\QualityGuard\DTOs\QualityVerdictDTO;
use App\Modules\QualityGuard\Enums\QualityIssueCode;
use App\Modules\QualityGuard\Enums\QualitySeverity;
use App\Modules\Shared\DTOs\ComposedReplyDTO;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;

class ConversationQualityGuard
{
    public const OVERRIDE_REPLY = 'Mohon tunggu sebentar Kak, saya teruskan ke tim sales kami ya 🙏';

    /**
     * @param  array<int, QualityRule>  $rules
     */
    public function __construct(
        private readonly array $rules,
    ) {}

    public function evaluate(
        TurnContextDTO $context,
        DecisionDTO $decision,
        ComposedReplyDTO $reply,
    ): QualityVerdictDTO {
        $violations = [];
        foreach ($this->rules as $rule) {
            try {
                $v = $rule->evaluate($context, $decision, $reply);
                if ($v !== null) {
                    $violations[] = $v;
                }
            } catch (\Throwable $e) {
                // Rule must never break the pipeline. Log silently.
                \Illuminate\Support\Facades\Log::warning('QualityRule error', [
                    'code' => $rule->code()->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $hasCritical = false;
        foreach ($violations as $v) {
            if ($v->severity === QualitySeverity::CRITICAL) {
                $hasCritical = true;
                break;
            }
        }

        if ($hasCritical) {
            return QualityVerdictDTO::block(
                violations: $violations,
                override_reply: self::OVERRIDE_REPLY,
            );
        }

        return QualityVerdictDTO::pass(violations: $violations);
    }

    /**
     * @return array<string, QualityRule>
     */
    public function rulesByCode(): array
    {
        $map = [];
        foreach ($this->rules as $rule) {
            $map[$rule->code()->value] = $rule;
        }
        return $map;
    }

    public function getRule(QualityIssueCode $code): ?QualityRule
    {
        return $this->rulesByCode()[$code->value] ?? null;
    }
}
