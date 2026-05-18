<?php

namespace App\Modules\QualityGuard\DTOs;

use App\Modules\QualityGuard\Enums\QualitySeverity;

readonly class QualityVerdictDTO
{
    /**
     * @param  array<int, QualityViolationDTO>  $violations
     */
    public function __construct(
        public array $violations,
        public bool $should_block,
        public ?string $override_reply,
    ) {}

    /**
     * @param  array<int, QualityViolationDTO>  $violations
     */
    public static function pass(array $violations = []): static
    {
        return new static(
            violations: $violations,
            should_block: false,
            override_reply: null,
        );
    }

    /**
     * @param  array<int, QualityViolationDTO>  $violations
     */
    public static function block(array $violations, string $override_reply): static
    {
        return new static(
            violations: $violations,
            should_block: true,
            override_reply: $override_reply,
        );
    }

    public function hasCritical(): bool
    {
        foreach ($this->violations as $v) {
            if ($v->severity === QualitySeverity::CRITICAL) {
                return true;
            }
        }
        return false;
    }

    public function criticalCodes(): array
    {
        $codes = [];
        foreach ($this->violations as $v) {
            if ($v->severity === QualitySeverity::CRITICAL) {
                $codes[] = $v->code->value;
            }
        }
        return $codes;
    }
}
