<?php

namespace App\Modules\QualityGuard\DTOs;

use App\Modules\QualityGuard\Enums\QualityIssueCode;
use App\Modules\QualityGuard\Enums\QualitySeverity;

readonly class QualityViolationDTO
{
    public function __construct(
        public QualityIssueCode $code,
        public QualitySeverity $severity,
        public string $message,
        public array $evidence,
        public ?string $suggested_action = null,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            code: $data['code'] instanceof QualityIssueCode
                ? $data['code']
                : QualityIssueCode::from($data['code']),
            severity: $data['severity'] instanceof QualitySeverity
                ? $data['severity']
                : QualitySeverity::from($data['severity']),
            message: $data['message'] ?? '',
            evidence: $data['evidence'] ?? [],
            suggested_action: $data['suggested_action'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'evidence' => $this->evidence,
            'suggested_action' => $this->suggested_action,
        ];
    }
}
