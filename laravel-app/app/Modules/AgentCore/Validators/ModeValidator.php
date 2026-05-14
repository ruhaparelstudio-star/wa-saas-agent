<?php

namespace App\Modules\AgentCore\Validators;

use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\Enums\AgentMode;

class ModeValidator
{
    private const LIMITED_ALLOWED_ACTIONS = [
        'send_general_reply',
        'send_price_info',
        'send_greeting',
        'clarify_request',
    ];

    /**
     * Returns: 'passed' | 'blocked'
     *
     * @return array{result: string, allowed_actions: array, message: string|null}
     */
    public function validate(TurnContextDTO $context, DecisionDTO $decision): array
    {
        $agentMode = $context->state->agent_mode;

        return match ($agentMode) {
            AgentMode::HANDOFF      => $this->handleHandoff($decision),
            AgentMode::PAUSED       => $this->handlePaused(),
            AgentMode::LIMITED      => $this->handleLimited($decision),
            AgentMode::ACTIVE       => [
                'result'          => 'passed',
                'allowed_actions' => $decision->desired_actions,
                'message'         => null,
            ],
        };
    }

    private function handleHandoff(DecisionDTO $decision): array
    {
        return [
            'result'          => 'blocked',
            'allowed_actions' => [],
            'message'         => 'sedang ditangani tim kami',
        ];
    }

    private function handlePaused(): array
    {
        return [
            'result'          => 'blocked',
            'allowed_actions' => [],
            'message'         => null,
        ];
    }

    private function handleLimited(DecisionDTO $decision): array
    {
        $allowed = array_filter(
            $decision->desired_actions,
            fn($a) => in_array($a, self::LIMITED_ALLOWED_ACTIONS, true)
        );

        return [
            'result'          => 'passed',
            'allowed_actions' => array_values($allowed),
            'message'         => null,
        ];
    }
}
