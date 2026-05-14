<?php

namespace App\Modules\AgentCore\Decision\Services;

use App\Modules\Shared\Contracts\DecisionEngineInterface;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\HandoffPriority;
use App\Modules\TenantConfig\Services\BusinessHoursService;

/**
 * PRINSIP 1 — PHP rule engine ONLY. Zero LLM calls.
 * All business decisions happen here, not in the LLM.
 */
class DecisionEngineService implements DecisionEngineInterface
{
    private const KATA_KASAR = [
        'bajingan', 'brengsek', 'sial', 'bodoh', 'idiot',
    ];

    private const KATA_ANCAMAN = [
        'ancam', 'lapor', 'somasi', 'pengacara', 'viralkan',
    ];

    public function __construct(
        private readonly BusinessHoursService $businessHoursService,
    ) {}

    public function decide(TurnContextDTO $context): DecisionDTO
    {
        // a. Check handoff triggers first — highest priority
        $handoffResult = $this->checkHandoffTriggers($context);

        if ($handoffResult !== null) {
            return DecisionDTO::from([
                'decision'             => 'handoff',
                'desired_actions'      => ['flag_handoff'],
                'allowed_actions'      => ['flag_handoff'],
                'blocked_actions'      => [],
                'handoff_required'     => true,
                'handoff_reason'       => $handoffResult['reason'],
                'handoff_priority'     => $handoffResult['priority'],
                'notification_required' => true,
                'reply_strategy'       => 'send_handoff_message',
                'active_goal'          => 'handoff_to_human',
                'stage_transition'     => ConversationStage::HANDOFF->value,
            ]);
        }

        // e. Check after-hours before normal flow
        if ($this->checkAfterHours($context)) {
            return DecisionDTO::from([
                'decision'             => 'after_hours',
                'desired_actions'      => [],
                'allowed_actions'      => [],
                'blocked_actions'      => [],
                'handoff_required'     => false,
                'handoff_reason'       => null,
                'handoff_priority'     => HandoffPriority::LOW->value,
                'notification_required' => false,
                'reply_strategy'       => 'send_after_hours_reply',
                'active_goal'          => 'inform_after_hours',
                'stage_transition'     => null,
            ]);
        }

        // b. Map intent → desired actions
        $desiredActions = $this->determineDesiredActions($context);

        // c. Determine stage machine transition
        $stageTransition = $this->determineStageTransition($context, $desiredActions);

        // d. Reply strategy based on new stage (or current)
        $effectiveStage = $stageTransition
            ? ConversationStage::from($stageTransition)
            : $context->state->stage;

        $replyStrategy = $this->determineReplyStrategy($context, $effectiveStage);

        return DecisionDTO::from([
            'decision'             => 'proceed',
            'desired_actions'      => $desiredActions,
            'allowed_actions'      => $desiredActions, // validators will filter further
            'blocked_actions'      => [],
            'handoff_required'     => false,
            'handoff_reason'       => null,
            'handoff_priority'     => HandoffPriority::LOW->value,
            'notification_required' => false,
            'reply_strategy'       => $replyStrategy,
            'active_goal'          => $this->buildActiveGoal($context->intent->intent, $desiredActions),
            'stage_transition'     => $stageTransition,
        ]);
    }

    private function checkHandoffTriggers(TurnContextDTO $context): ?array
    {
        $intent      = $context->intent->intent;
        $messageBody = mb_strtolower($context->inbound_message->body);

        // Explicit handoff request
        if ($intent === 'handoff_request') {
            return [
                'reason'   => 'Customer requested human agent',
                'priority' => HandoffPriority::MEDIUM->value,
            ];
        }

        // Threat keywords — immediate URGENT handoff
        foreach (self::KATA_ANCAMAN as $word) {
            if (str_contains($messageBody, $word)) {
                return [
                    'reason'   => 'Threat or legal language detected in message',
                    'priority' => HandoffPriority::URGENT->value,
                ];
            }
        }

        // Abusive language — URGENT on 2nd occurrence
        $priorAbuseCount    = (int) ($context->state->entities['abusive_count'] ?? 0);
        $currentHasAbuse    = false;
        foreach (self::KATA_KASAR as $word) {
            if (str_contains($messageBody, $word)) {
                $currentHasAbuse = true;
                break;
            }
        }

        if ($currentHasAbuse && $priorAbuseCount >= 1) {
            return [
                'reason'   => 'Repeated abusive language detected',
                'priority' => HandoffPriority::URGENT->value,
            ];
        }

        // out_of_scope 3× consecutive — use accumulated counter from entity cache
        $outOfScopeCount = (int) ($context->state->entities['out_of_scope_count'] ?? 0);
        if ($intent === 'out_of_scope' && $outOfScopeCount >= 2) {
            return [
                'reason'   => 'Out of scope 3 times consecutively',
                'priority' => HandoffPriority::LOW->value,
            ];
        }

        return null;
    }

    private function determineDesiredActions(TurnContextDTO $context): array
    {
        $intent   = $context->intent->intent;
        $entities = $context->entities->entities;

        return match ($intent) {
            'greeting'                                      => ['send_greeting'],
            'ask_price'                                     => ['send_price_info'],
            'ask_package_list'                              => ['send_package_list'],
            'ask_package_detail'                            => isset($entities['package_slug'])
                                                                ? ['send_package_detail']
                                                                : ['ask_package_clarification'],
            'ask_availability'                              => ['check_availability', 'send_availability'],
            'ask_process'                                   => ['send_process_info'],
            'ask_location'                                  => ['send_location_info'],
            'ask_payment', 'payment_topic'                  => ['send_payment_info'],
            'ask_booking', 'provide_budget'                 => ['send_booking_info'],
            'confirm_booking'                               => ['initiate_booking'],
            'cancel_booking'                                => ['cancel_booking_flow'],
            'objection_price', 'objection_trust',
            'objection_timing'                              => ['handle_objection', 'send_grounded_reply'],
            'invoice_inquiry'                               => ['retrieve_invoice'],
            'handoff_request'                               => ['flag_handoff'],
            default                                         => ['send_general_reply'],
        };
    }

    private function determineStageTransition(TurnContextDTO $context, array $desiredActions): ?string
    {
        $currentStage = $context->state->stage;
        $intent       = $context->intent->intent;
        $entities     = $context->entities->entities;

        return match ($currentStage) {
            ConversationStage::NEW_LEAD     => $this->transitionFromNewLead($intent),
            ConversationStage::EXPLORATION  => $this->transitionFromExploration($entities),
            ConversationStage::QUALIFICATION => $this->transitionFromQualification($entities),
            ConversationStage::RECOMMENDATION => $this->transitionFromRecommendation($entities),
            ConversationStage::CONSIDERATION => $this->transitionFromConsideration($intent),
            ConversationStage::BOOKING      => ConversationStage::WAITING_BOOKING->value,
            default                         => null,
        };
    }

    private function transitionFromNewLead(string $intent): ?string
    {
        $explorationIntents = [
            'ask_price', 'ask_package_list', 'ask_package_detail', 'ask_availability',
        ];

        if (in_array($intent, $explorationIntents, true)) {
            return ConversationStage::EXPLORATION->value;
        }

        return null;
    }

    private function transitionFromExploration(array $entities): ?string
    {
        if (!empty($entities['guest_count']) && !empty($entities['event_date'])) {
            return ConversationStage::QUALIFICATION->value;
        }

        return null;
    }

    private function transitionFromQualification(array $entities): ?string
    {
        if (!empty($entities['package_slug'])) {
            return ConversationStage::RECOMMENDATION->value;
        }

        return null;
    }

    private function transitionFromRecommendation(array $entities): ?string
    {
        if (!empty($entities['booking_intent_signal'])) {
            return ConversationStage::CONSIDERATION->value;
        }

        return null;
    }

    private function transitionFromConsideration(string $intent): ?string
    {
        if ($intent === 'confirm_booking') {
            return ConversationStage::BOOKING->value;
        }

        return null;
    }

    private function determineReplyStrategy(TurnContextDTO $context, ConversationStage $stage): string
    {
        $intent = $context->intent->intent;

        return match (true) {
            $intent === 'ask_price'                                       => 'send_price_breakdown',
            in_array($intent, ['confirm_booking', 'ask_booking'], true)   => 'send_booking_flow',
            $intent === 'handoff_request'                                  => 'send_handoff_message',
            in_array($intent, ['unclear_message', 'out_of_scope'], true)  => 'clarify_request',
            !empty($context->entities->needs_clarification)               => 'clarify_request',
            default                                                        => 'send_grounded_reply',
        };
    }

    private function checkAfterHours(TurnContextDTO $context): bool
    {
        if ($this->businessHoursService->isOpen($context->tenant->id)) {
            return false;
        }

        $behavior = $this->businessHoursService->getAfterHoursBehavior($context->tenant->id);

        return $behavior !== 'ignore';
    }

    private function buildActiveGoal(string $intent, array $desiredActions): string
    {
        return sprintf('Handle %s via %s', $intent, implode(', ', $desiredActions));
    }
}
