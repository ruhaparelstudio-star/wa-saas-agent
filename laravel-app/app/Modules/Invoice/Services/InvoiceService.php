<?php

namespace App\Modules\Invoice\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Handoff\Services\HandoffService;
use App\Modules\Invoice\Jobs\GenerateInvoicePdfJob;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Repositories\InvoiceRepository;
use App\Modules\Notification\Models\AdminNotification;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\Enums\HandoffPriority;
use App\Modules\Shared\Services\ChannelRegistry;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\InvoiceStatus;
use App\Modules\Shared\Enums\InvoiceType;
use App\Modules\Shared\Enums\NotificationType;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\TenantConfig\Models\TenantBankAccount;
use App\Modules\TenantConfig\Services\TenantPolicyService;
use App\Modules\Shared\Enums\PolicyKey;
use App\Modules\WhatsApp\Repositories\WaAccountRepository;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly TenantPolicyService $policyService,
        private readonly ChannelRegistry $channelRegistry,
        private readonly WaAccountRepository $waAccountRepository,
        private readonly ConversationRepository $conversationRepository,
        private readonly ?HandoffService $handoffService = null,
    ) {}

    /**
     * Issue a new invoice for a booking. Updates booking and conversation stage.
     */
    public function issue(Booking $booking, InvoiceType $type, int $amount, Carbon $dueDate): Invoice
    {
        $invoice = $this->invoiceRepository->create([
            'tenant_id'  => $booking->tenant_id,
            'booking_id' => $booking->id,
            'type'       => $type->value,
            'status'     => InvoiceStatus::ISSUED->value,
            'amount'     => $amount,
            'due_date'   => $dueDate->toDateString(),
        ]);

        if ($type === InvoiceType::DP) {
            $booking->update(['status' => BookingStatus::AWAITING_DP]);
        }

        if ($booking->conversation_id) {
            $this->conversationRepository->updateState($booking->conversation_id, [
                'stage' => ConversationStage::INVOICE_PHASE->value,
            ]);
        }

        $this->notifyAdmins(
            $booking->tenant_id,
            NotificationType::INVOICE_ACTION,
            'Invoice Diterbitkan: ' . $invoice->invoice_number,
            sprintf(
                'Invoice %s untuk booking %s diterbitkan. Tipe: %s, Nominal: Rp%s, Jatuh Tempo: %s.',
                $invoice->invoice_number,
                $booking->booking_code,
                $type->label(),
                number_format($amount, 0, ',', '.'),
                $dueDate->toDateString()
            ),
            ['invoice_id' => $invoice->id, 'booking_id' => $booking->id]
        );

        Log::info('InvoiceService: invoice issued.', [
            'invoice_id'     => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'booking_code'   => $booking->booking_code,
            'type'           => $type->value,
        ]);

        GenerateInvoicePdfJob::dispatch($invoice);

        return $invoice;
    }

    /**
     * Send invoice via WA. Returns false if resend limit exceeded.
     */
    public function send(Invoice $invoice): bool
    {
        $maxResend = (int) $this->policyService->getPolicy(
            $invoice->tenant_id,
            PolicyKey::INVOICE_MAX_RESEND
        );

        if (!$invoice->canResend($maxResend)) {
            Log::warning('InvoiceService: resend limit reached.', [
                'invoice_id'  => $invoice->id,
                'sent_count'  => $invoice->sent_count,
                'max_resend'  => $maxResend,
            ]);
            return false;
        }

        $booking      = $invoice->booking;
        $conversation = $booking?->conversation;
        $channel      = $conversation?->channel ?? 'whatsapp';
        $tenantId     = $invoice->tenant_id;

        $adapter  = $this->channelRegistry->getAdapter($channel, $tenantId);
        $accountId = '';

        if ($channel === 'email') {
            $toAddress = $conversation?->customer_email;
            if (!$toAddress) {
                Log::warning('InvoiceService: no customer email for email channel.', [
                    'invoice_id' => $invoice->id,
                ]);
                return false;
            }
            $to = $toAddress;
        } else {
            $waAccount = $this->waAccountRepository->getActiveForTenant($tenantId);
            if (!$waAccount) {
                Log::warning('InvoiceService: no active WA account for tenant.', [
                    'tenant_id' => $tenantId,
                ]);
                return false;
            }
            $toPhone = $booking?->customer_phone;
            if (!$toPhone) {
                Log::warning('InvoiceService: no customer phone for invoice.', [
                    'invoice_id' => $invoice->id,
                ]);
                return false;
            }
            $accountId = $waAccount->id;
            $to        = $toPhone;
        }

        $message = $this->formatInvoiceMessage($invoice, $booking);

        $sent = false;
        if ($invoice->pdf_url) {
            try {
                $adapter->sendFile($accountId, $to, $invoice->pdf_url, $message);
                $sent = true;
            } catch (\Throwable $e) {
                Log::warning('InvoiceService: sendFile failed, falling back to text.', [
                    'invoice_id' => $invoice->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
        if (!$sent) {
            $adapter->sendText($accountId, $to, $message);
        }

        $invoice->markSent();

        if ($booking?->conversation_id) {
            $this->conversationRepository->updateState($booking->conversation_id, [
                'stage' => ConversationStage::POST_INVOICE_LIMITED->value,
            ]);
        }

        Log::info('InvoiceService: invoice sent.', [
            'invoice_id' => $invoice->id,
            'sent_count' => $invoice->fresh()->sent_count,
            'channel'    => $channel,
            'to'         => substr($to, 0, 4) . '***',
        ]);

        return true;
    }

    /**
     * Mark invoice as paid and update related booking and conversation.
     */
    public function markPaid(Invoice $invoice, string $proofUrl = ''): void
    {
        $invoice->markPaid($proofUrl);

        $booking = $invoice->booking;
        if ($booking) {
            $booking->update(['status' => BookingStatus::PAID]);

            if ($booking->conversation_id) {
                $this->conversationRepository->updateState($booking->conversation_id, [
                    'stage' => ConversationStage::BOOKING->value,
                ]);
            }
        }

        $this->notifyAdmins(
            $invoice->tenant_id,
            NotificationType::INVOICE_ACTION,
            'Invoice Lunas: ' . $invoice->invoice_number,
            sprintf('Invoice %s telah ditandai lunas.', $invoice->invoice_number),
            ['invoice_id' => $invoice->id, 'proof_url' => $proofUrl]
        );

        // Escalate to sales — booking has now reached the "DP paid" milestone
        // which sales reps want to follow up on personally. HandoffService is
        // idempotent (reuses an existing active record) so this is safe to call
        // even when markPaid is invoked multiple times for the same invoice.
        if ($this->handoffService !== null && $booking !== null && $booking->conversation_id) {
            $conversation = Conversation::withoutGlobalScopes()->find($booking->conversation_id);
            if ($conversation !== null) {
                $decision = DecisionDTO::from([
                    'decision'             => 'handoff',
                    'desired_actions'      => ['flag_handoff'],
                    'allowed_actions'      => ['flag_handoff'],
                    'blocked_actions'      => [],
                    'handoff_required'     => true,
                    'handoff_reason'       => sprintf(
                        'Invoice %s paid — escalate to sales for follow-up',
                        $invoice->invoice_number,
                    ),
                    'handoff_priority'     => HandoffPriority::MEDIUM->value,
                    'notification_required' => true,
                    'reply_strategy'       => 'send_handoff_message',
                    'active_goal'          => 'sales_follow_up_on_paid_invoice',
                    'stage_transition'     => null,
                ]);

                try {
                    $this->handoffService->triggerHandoff($conversation, $decision);
                } catch (\Throwable $e) {
                    // Non-fatal — invoice payment must remain recorded even if
                    // handoff side-effect fails.
                    Log::warning('InvoiceService: handoff trigger failed after markPaid', [
                        'invoice_id' => $invoice->id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('InvoiceService: invoice marked paid.', ['invoice_id' => $invoice->id]);
    }

    /**
     * Cancel an invoice.
     */
    public function markCancelled(Invoice $invoice): void
    {
        $invoice->update(['status' => InvoiceStatus::CANCELLED]);
        Log::info('InvoiceService: invoice cancelled.', ['invoice_id' => $invoice->id]);
    }

    /**
     * Return overdue invoices for the tenant, auto-marking SENT invoices as OVERDUE.
     */
    public function getOverdueByTenant(string $tenantId): Collection
    {
        $invoices = $this->invoiceRepository->findOverdueByTenant($tenantId);

        foreach ($invoices as $invoice) {
            if ($invoice->isOverdue() && $invoice->status === InvoiceStatus::SENT) {
                $invoice->update(['status' => InvoiceStatus::OVERDUE]);
            }
        }

        return $invoices->fresh();
    }

    private function formatInvoiceMessage(Invoice $invoice, ?Booking $booking): string
    {
        $bookingCode = $booking?->booking_code ?? '-';
        $dueDate     = $invoice->due_date?->format('d M Y') ?? '-';
        $amount      = 'Rp' . number_format($invoice->amount, 0, ',', '.');

        $lines = [
            '🧾 *INVOICE ' . $invoice->invoice_number . '*',
            '',
            'Booking   : ' . $bookingCode,
            'Tipe      : ' . $invoice->type->label(),
            'Nominal   : ' . $amount,
            'Jatuh Tempo: ' . $dueDate,
        ];

        $bankAccounts = TenantBankAccount::withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)
            ->active()
            ->ordered()
            ->get();

        if ($bankAccounts->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '💳 *Pilihan Pembayaran:*';
            $i = 1;
            foreach ($bankAccounts as $acc) {
                $star = $acc->is_default ? ' ⭐' : '';
                $lines[] = sprintf(
                    '%d. %s — %s (a.n. %s)%s',
                    $i++,
                    $acc->bank_name,
                    $acc->account_number,
                    $acc->account_holder,
                    $star,
                );
            }
            $lines[] = '';
            $lines[] = 'Setelah transfer, kirim bukti pembayaran ke chat ini. Terima kasih 🙏';
        } else {
            $lines[] = '';
            $lines[] = 'Mohon segera lakukan pembayaran sebelum jatuh tempo.';
            $lines[] = 'Tim kami akan kirim detail rekening segera. Terima kasih 🙏';

            // Nudge admin to configure bank accounts so future invoices include them.
            $this->notifyAdmins(
                $invoice->tenant_id,
                NotificationType::INVOICE_ACTION,
                'Rekening Pembayaran Belum Diatur',
                'Invoice ' . $invoice->invoice_number . ' dikirim tanpa info rekening — silakan tambahkan rekening di Pengaturan → Rekening Pembayaran agar invoice berikutnya otomatis memuat instruksi transfer.',
                ['invoice_id' => $invoice->id, 'reason' => 'no_bank_account_configured']
            );
        }

        return implode("\n", $lines);
    }

    private function notifyAdmins(
        string $tenantId,
        NotificationType $type,
        string $title,
        string $body,
        array $data = []
    ): void {
        $admins = User::where('tenant_id', $tenantId)
            ->where('role', UserRole::TENANT_ADMIN->value)
            ->where('is_active', true)
            ->get();

        foreach ($admins as $admin) {
            AdminNotification::create([
                'tenant_id' => $tenantId,
                'user_id'   => $admin->id,
                'type'      => $type->value,
                'title'     => $title,
                'body'      => $body,
                'data'      => $data,
            ]);
        }
    }
}
