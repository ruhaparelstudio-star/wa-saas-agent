<?php

namespace App\Modules\AgentCore\Validators;

use App\Modules\Shared\DTOs\BlockedActionDTO;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\Enums\ConversationStage;

class ActionPermissionValidator
{
    /**
     * Maps actions to the stages that allow them.
     * Empty array = allowed in all stages.
     * Listed stages = the ONLY stages where the action is allowed.
     */
    private const ACTION_STAGE_WHITELIST = [
        'initiate_booking' => [
            ConversationStage::CONSIDERATION,
            ConversationStage::BOOKING,
        ],
        'retrieve_invoice' => [
            ConversationStage::INVOICE_PHASE,
            ConversationStage::POST_INVOICE_LIMITED,
        ],
    ];

    /**
     * Actions blocked in these stages regardless of anything else.
     */
    private const CLOSED_STAGES = [
        ConversationStage::CLOSED,
        ConversationStage::HANDOFF,
    ];

    /**
     * @return array{allowed: array, blocked: BlockedActionDTO[]}
     */
    public function validate(TurnContextDTO $context, DecisionDTO $decision): array
    {
        $currentStage   = $context->state->stage;
        $desiredActions = $decision->desired_actions;
        $allowed        = [];
        $blocked        = [];

        foreach ($desiredActions as $action) {
            $blockReason = $this->getBlockReason($action, $currentStage);

            if ($blockReason !== null) {
                $blocked[] = BlockedActionDTO::from([
                    'action'          => $action,
                    'reason'          => $blockReason,
                    'can_fallback'    => true,
                    'fallback_action' => 'send_general_reply',
                ]);
            } else {
                $allowed[] = $action;
            }
        }

        return ['allowed' => $allowed, 'blocked' => $blocked];
    }

    private function getBlockReason(string $action, ConversationStage $stage): ?string
    {
        // Stage-based blocks for general actions
        if (in_array($stage, self::CLOSED_STAGES, true)) {
            if ($action !== 'flag_handoff' && $action !== 'send_handoff_message') {
                return "Action '{$action}' not allowed in stage {$stage->value}";
            }
        }

        // Specific action stage restrictions
        if (isset(self::ACTION_STAGE_WHITELIST[$action])) {
            $allowedStages = self::ACTION_STAGE_WHITELIST[$action];

            if (!in_array($stage, $allowedStages, true)) {
                $stageNames = implode(', ', array_map(fn($s) => $s->value, $allowedStages));

                return "Action '{$action}' only allowed in stages: {$stageNames}. Current: {$stage->value}";
            }
        }

        return null;
    }
}
