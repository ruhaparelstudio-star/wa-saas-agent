<?php

namespace App\Modules\AgentCore\Validators;

use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;

class GroundingValidator
{
    /**
     * Returns: 'passed' | 'partial' | 'failed'
     * Also sets detected_hallucination flag via returned array context.
     *
     * @return array{result: string, detected_hallucination: bool}
     */
    public function validate(TurnContextDTO $context, DecisionDTO $decision): array
    {
        $structuredData  = $context->knowledge->structured_data;
        $groundingRefs   = $context->knowledge->grounding_refs;
        $allowedActions  = $decision->allowed_actions;
        $desiredActions  = $decision->desired_actions;

        $effectiveActions = !empty($allowedActions) ? $allowedActions : $desiredActions;

        $detectedHallucination = false;
        $result                = 'passed';

        foreach ($effectiveActions as $action) {
            $actionResult = $this->checkAction($action, $structuredData, $groundingRefs);

            if ($actionResult === 'failed') {
                // A single failed action degrades overall to 'partial' at minimum
                $result                = 'failed';
                $detectedHallucination = true;
            } elseif ($actionResult === 'partial' && $result === 'passed') {
                $result = 'partial';
            }
        }

        return [
            'result'                 => $result,
            'detected_hallucination' => $detectedHallucination,
        ];
    }

    private function checkAction(string $action, array $structuredData, array $groundingRefs): string
    {
        return match ($action) {
            'send_price_info', 'send_price_breakdown' => $this->checkPrices($structuredData),
            'send_package_detail'                      => $this->checkPackages($structuredData),
            'send_package_list'                        => $this->checkPackages($structuredData),
            'retrieve_invoice'                         => $this->checkInvoices($structuredData),
            'check_availability', 'send_availability'  => $this->checkAvailability($structuredData),
            'send_general_reply', 'send_greeting',
            'send_handoff_message', 'send_after_hours_reply',
            'clarify_request', 'send_booking_flow',
            'send_process_info', 'send_location_info',
            'send_payment_info', 'send_booking_info',
            'handle_objection', 'ask_package_clarification',
            'flag_handoff', 'cancel_booking_flow'       => 'passed',
            default                                    => 'passed',
        };
    }

    private function checkAvailability(array $structuredData): string
    {
        if (empty($structuredData['availability'])) {
            return 'failed';
        }
        return 'passed';
    }

    private function checkPrices(array $structuredData): string
    {
        $prices = $structuredData['prices'] ?? $structuredData['packages'] ?? [];

        if (empty($prices)) {
            // Can still reply asking customer to request price
            return 'partial';
        }

        return 'passed';
    }

    private function checkPackages(array $structuredData): string
    {
        $packages = $structuredData['packages'] ?? [];

        if (empty($packages)) {
            return 'failed';
        }

        return 'passed';
    }

    private function checkInvoices(array $structuredData): string
    {
        $invoices = $structuredData['invoices'] ?? [];

        if (empty($invoices)) {
            return 'partial';
        }

        return 'passed';
    }
}
