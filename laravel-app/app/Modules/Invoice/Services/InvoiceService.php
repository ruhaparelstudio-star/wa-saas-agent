<?php

namespace App\Modules\Invoice\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Repositories\InvoiceRepository;
use App\Modules\Notification\Models\AdminNotification;
use App\Modules\Shared\Contracts\ChannelGatewayInterface;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\InvoiceStatus;
use App\Modules\Shared\Enums\InvoiceType;
use App\Modules\Shared\Enums\NotificationType;
use App\Modules\Shared\Enums\UserRole;
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
        private readonly ChannelGatewayInterface $gateway,
        private readonly WaAccountRepository $waAccountRepository,
        private readonly ConversationRepository $conversationRepository,
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

        $waAccount = $this->waAccountRepository->getActiveForTenant($invoice->tenant_id);
        if (!$waAccount) {
            Log::warning('InvoiceService: no active WA account for tenant.', [
                'tenant_id' => $invoice->tenant_id,
            ]);
            return false;
        }

        $booking = $invoice->booking;
        $toPhone = $booking?->customer_phone;
        if (!$toPhone) {
            Log::warning('InvoiceService: no customer phone for invoice.', [
                'invoice_id' => $invoice->id,
            ]);
            return false;
        }

        $message = $this->formatInvoiceMessage($invoice, $booking);

        $this->gateway->sendText($waAccount->id, $toPhone, $message);

        $invoice->markSent();

        // Lock AI after invoice sent
        if ($booking?->conversation_id) {
            $this->conversationRepository->updateState($booking->conversation_id, [
                'stage' => ConversationStage::POST_INVOICE_LIMITED->value,
            ]);
        }

        Log::info('InvoiceService: invoice sent.', [
            'invoice_id'  => $invoice->id,
            'sent_count'  => $invoice->fresh()->sent_count,
            'to_phone'    => substr($toPhone, 0, 4) . '***',
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

        return implode("\n", [
            '🧾 *INVOICE ' . $invoice->invoice_number . '*',
            '',
            'Booking   : ' . $bookingCode,
            'Tipe      : ' . $invoice->type->label(),
            'Nominal   : ' . $amount,
            'Jatuh Tempo: ' . $dueDate,
            '',
            'Mohon segera lakukan pembayaran sebelum jatuh tempo.',
            'Konfirmasi pembayaran ke admin kami. Terima kasih 🙏',
        ]);
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
