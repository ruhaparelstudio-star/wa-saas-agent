<?php

namespace App\Modules\Shared\DTOs;

readonly class ValidatorResultDTO
{
    public function __construct(
        public string $policy_result,
        public string $grounding_result,
        public string $permission_result,
        public string $mode_result,
        public array $final_allowed_actions,
        public array $final_blocked_actions,
        public array $warnings,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            policy_result: $data['policy_result'] ?? 'passed',
            grounding_result: $data['grounding_result'] ?? 'passed',
            permission_result: $data['permission_result'] ?? 'passed',
            mode_result: $data['mode_result'] ?? 'passed',
            final_allowed_actions: $data['final_allowed_actions'] ?? [],
            final_blocked_actions: $data['final_blocked_actions'] ?? [],
            warnings: $data['warnings'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'policy_result' => $this->policy_result,
            'grounding_result' => $this->grounding_result,
            'permission_result' => $this->permission_result,
            'mode_result' => $this->mode_result,
            'final_allowed_actions' => $this->final_allowed_actions,
            'final_blocked_actions' => $this->final_blocked_actions,
            'warnings' => $this->warnings,
        ];
    }
}
