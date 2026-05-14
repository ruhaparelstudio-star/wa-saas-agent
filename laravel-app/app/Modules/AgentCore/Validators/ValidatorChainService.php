<?php

namespace App\Modules\AgentCore\Validators;

use App\Modules\Shared\DTOs\BlockedActionDTO;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\DTOs\ValidatorResultDTO;

class ValidatorChainService
{
    public function __construct(
        private readonly PolicyValidator           $policyValidator,
        private readonly GroundingValidator        $groundingValidator,
        private readonly ActionPermissionValidator $actionPermissionValidator,
        private readonly ModeValidator             $modeValidator,
    ) {}

    public function runAll(TurnContextDTO $context, DecisionDTO $decision): ValidatorResultDTO
    {
        $allWarnings       = [];
        $policyBlocked     = [];

        // ── 1. PolicyValidator ────────────────────────────────────────────
        [$policyBlockedRaw, $policyWarnings] = $this->policyValidator->validate($context, $decision);
        $allWarnings   = array_merge($allWarnings, $policyWarnings);
        $policyResult  = empty($policyBlockedRaw) ? 'passed' : 'failed';
        $policyBlocked = $policyBlockedRaw;

        // Rebuild decision with policy-blocked actions removed
        $afterPolicyAllowed = $this->removeBlockedActions(
            $decision->desired_actions,
            $policyBlocked
        );

        // ── 2. GroundingValidator ─────────────────────────────────────────
        $groundingData   = $this->groundingValidator->validate($context, $decision);
        $groundingResult = $groundingData['result'];
        $detectedHallucination = $groundingData['detected_hallucination'];

        // ── 3. ActionPermissionValidator ──────────────────────────────────
        $permissionData     = $this->actionPermissionValidator->validate($context, $decision);
        $permissionAllowed  = $permissionData['allowed'];
        $permissionBlocked  = $permissionData['blocked'];

        $permissionResult = match (true) {
            empty($permissionBlocked)                       => 'passed',
            empty($permissionAllowed) && !empty($permissionBlocked) => 'blocked_all',
            default                                         => 'blocked_partial',
        };

        // ── 4. ModeValidator ──────────────────────────────────────────────
        $modeData    = $this->modeValidator->validate($context, $decision);
        $modeResult  = $modeData['result'];
        $modeAllowed = $modeData['allowed_actions'];

        // ── Final allowed = intersection of all validators ─────────────────
        $finalAllowed = $modeResult === 'blocked'
            ? []
            : $this->intersect($afterPolicyAllowed, $permissionAllowed);

        // Collect all blocked actions as BlockedActionDTO
        $finalBlocked = $this->mergeBlocked($policyBlocked, $permissionBlocked);

        return ValidatorResultDTO::from([
            'policy_result'         => $policyResult,
            'grounding_result'      => $groundingResult,
            'permission_result'     => $permissionResult,
            'mode_result'           => $modeResult,
            'final_allowed_actions' => $finalAllowed,
            'final_blocked_actions' => array_map(fn($b) => $b->toArray(), $finalBlocked),
            'warnings'              => $allWarnings,
        ]);
    }

    private function removeBlockedActions(array $desired, array $policyBlocked): array
    {
        $blockedActions = array_column($policyBlocked, 'action');

        // Wildcard block ('*') blocks everything
        if (in_array('*', $blockedActions, true)) {
            return [];
        }

        return array_values(array_filter(
            $desired,
            fn($a) => !in_array($a, $blockedActions, true)
        ));
    }

    private function intersect(array $policyAllowed, array $permissionAllowed): array
    {
        if (empty($policyAllowed)) {
            return [];
        }

        // permissionAllowed may be empty if no actions were explicitly blocked — return policyAllowed
        if (empty($permissionAllowed)) {
            return $policyAllowed;
        }

        return array_values(array_intersect($policyAllowed, $permissionAllowed));
    }

    /**
     * @param  array<BlockedActionDTO>  $permissionBlocked
     */
    private function mergeBlocked(array $policyBlockedRaw, array $permissionBlocked): array
    {
        $result = [];

        foreach ($policyBlockedRaw as $raw) {
            $result[] = BlockedActionDTO::from($raw);
        }

        foreach ($permissionBlocked as $blocked) {
            $result[] = $blocked;
        }

        return $result;
    }
}
