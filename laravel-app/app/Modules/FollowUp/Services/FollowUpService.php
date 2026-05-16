<?php

namespace App\Modules\FollowUp\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Repositories\BookingRepository;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\FollowUp\Models\FollowUpLog;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Repositories\InvoiceRepository;
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Shared\Contracts\ChannelGatewayInterface;
use App\Modules\Shared\DTOs\FollowUpCandidateDTO;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\FeatureKey;
use App\Modules\Shared\Enums\FollowUpReason;
use App\Modules\Shared\Enums\LeadTemperature;
use App\Modules\WhatsApp\Repositories\WaAccountRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FollowUpService
{
    public function __construct(
        private readonly FeatureGateService    $featureGateService,
        private readonly ChannelGatewayInterface $gateway,
        private readonly ConversationRepository  $conversationRepo,
        private readonly BookingRepository       $bookingRepo,
        private readonly InvoiceRepository       $invoiceRepo,
        private readonly WaAccountRepository     $waAccountRepo,
    ) {}

    /** @return FollowUpCandidateDTO[] */
    public function findCandidates(string $tenantId): array
    {
        $waAccount = $this->waAccountRepo->getActiveForTenant($tenantId);
        if (!$waAccount) {
            return [];
        }

        $candidates = [];

        foreach ($this->findStaleLeads($tenantId, $waAccount->id) as $c)     { $candidates[] = $c; }
        foreach ($this->findPendingDpBookings($tenantId, $waAccount->id) as $c) { $candidates[] = $c; }
        foreach ($this->findOverdueInvoices($tenantId, $waAccount->id) as $c)  { $candidates[] = $c; }
        foreach ($this->findH7Reminders($tenantId, $waAccount->id) as $c)     { $candidates[] = $c; }

        return $candidates;
    }

    public function sendFollowUp(FollowUpCandidateDTO $candidate): bool
    {
        $lockKey = $this->lockKey($candidate);

        if (Cache::has($lockKey)) {
            return false;
        }

        $message = $this->buildMessage($candidate);

        $sent = $this->gateway->sendText(
            $candidate->wa_account_id,
            $candidate->to_phone,
            $message,
        );

        FollowUpLog::create([
            'tenant_id'       => $candidate->tenant_id,
            'conversation_id' => $candidate->conversation_id,
            'booking_id'      => $candidate->booking_id,
            'invoice_id'      => $candidate->invoice_id,
            'reason'          => $candidate->reason,
            'sent_at'         => now(),
            'message_body'    => $message,
            'delivered'       => $sent,
            'metadata'        => $candidate->context_data,
        ]);

        // TTL 24h so same conversation+reason won't repeat today
        Cache::put($lockKey, true, 86400);

        return $sent;
    }

    // ─── Private query helpers ────────────────────────────────────────────────

    /** @return FollowUpCandidateDTO[] */
    private function findStaleLeads(string $tenantId, string $waAccountId): array
    {
        $cutoff = Carbon::now()->subHours(24);

        $conversations = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('lead_temperature', [LeadTemperature::WARM->value, LeadTemperature::HOT->value])
            ->where('last_message_at', '<', $cutoff)
            ->whereNotNull('customer_phone')
            ->get();

        $candidates = [];
        foreach ($conversations as $conv) {
            if ($this->alreadySentToday($conv->id, FollowUpReason::STALE_LEAD->value)) {
                continue;
            }
            $candidates[] = new FollowUpCandidateDTO(
                reason:          FollowUpReason::STALE_LEAD->value,
                tenant_id:       $tenantId,
                conversation_id: $conv->id,
                booking_id:      null,
                invoice_id:      null,
                to_phone:        $conv->customer_phone,
                wa_account_id:   $waAccountId,
                context_data:    ['temperature' => $conv->lead_temperature?->value],
            );
        }
        return $candidates;
    }

    /** @return FollowUpCandidateDTO[] */
    private function findPendingDpBookings(string $tenantId, string $waAccountId): array
    {
        $cutoff = Carbon::now()->subHours(48);

        $bookings = Booking::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', BookingStatus::AWAITING_DP->value)
            ->where('updated_at', '<', $cutoff)
            ->whereNotNull('customer_phone')
            ->get();

        $candidates = [];
        foreach ($bookings as $booking) {
            $convId = $booking->conversation_id;
            if ($convId && $this->alreadySentToday($convId, FollowUpReason::BOOKING_PENDING_DP->value)) {
                continue;
            }
            $candidates[] = new FollowUpCandidateDTO(
                reason:          FollowUpReason::BOOKING_PENDING_DP->value,
                tenant_id:       $tenantId,
                conversation_id: $convId,
                booking_id:      $booking->id,
                invoice_id:      null,
                to_phone:        $booking->customer_phone,
                wa_account_id:   $waAccountId,
                context_data:    ['booking_code' => $booking->booking_code],
            );
        }
        return $candidates;
    }

    /** @return FollowUpCandidateDTO[] */
    private function findOverdueInvoices(string $tenantId, string $waAccountId): array
    {
        $overdueInvoices = $this->invoiceRepo->findOverdueByTenant($tenantId);

        $candidates = [];
        foreach ($overdueInvoices as $invoice) {
            $booking = $invoice->booking;
            $phone   = $booking?->customer_phone;
            if (!$phone) {
                continue;
            }

            $convId = $booking?->conversation_id;
            if ($convId && $this->alreadySentToday($convId, FollowUpReason::INVOICE_OVERDUE->value)) {
                continue;
            }

            $candidates[] = new FollowUpCandidateDTO(
                reason:          FollowUpReason::INVOICE_OVERDUE->value,
                tenant_id:       $tenantId,
                conversation_id: $convId,
                booking_id:      $invoice->booking_id,
                invoice_id:      $invoice->id,
                to_phone:        $phone,
                wa_account_id:   $waAccountId,
                context_data:    [
                    'invoice_number' => $invoice->invoice_number,
                    'amount'         => $invoice->amount,
                    'due_date'       => $invoice->due_date?->toDateString(),
                ],
            );
        }
        return $candidates;
    }

    /** @return FollowUpCandidateDTO[] */
    private function findH7Reminders(string $tenantId, string $waAccountId): array
    {
        $targetDate = Carbon::now()->addDays(7)->toDateString();

        $bookings = Booking::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [BookingStatus::CONFIRMED->value, BookingStatus::PAID->value])
            ->where('event_date', $targetDate)
            ->whereNotNull('customer_phone')
            ->get();

        $candidates = [];
        foreach ($bookings as $booking) {
            $convId = $booking->conversation_id;
            if ($convId && $this->alreadySentToday($convId, FollowUpReason::EVENT_REMINDER_H7->value)) {
                continue;
            }
            $candidates[] = new FollowUpCandidateDTO(
                reason:          FollowUpReason::EVENT_REMINDER_H7->value,
                tenant_id:       $tenantId,
                conversation_id: $convId,
                booking_id:      $booking->id,
                invoice_id:      null,
                to_phone:        $booking->customer_phone,
                wa_account_id:   $waAccountId,
                context_data:    [
                    'booking_code' => $booking->booking_code,
                    'event_date'   => $booking->event_date?->toDateString(),
                    'event_type'   => $booking->event_type,
                    'location'     => $booking->location,
                ],
            );
        }
        return $candidates;
    }

    // ─── Message templates ────────────────────────────────────────────────────

    private function buildMessage(FollowUpCandidateDTO $candidate): string
    {
        return match($candidate->reason) {
            FollowUpReason::STALE_LEAD->value => $this->msgStaleLead($candidate),
            FollowUpReason::BOOKING_PENDING_DP->value => $this->msgPendingDp($candidate),
            FollowUpReason::INVOICE_OVERDUE->value => $this->msgInvoiceOverdue($candidate),
            FollowUpReason::EVENT_REMINDER_H7->value => $this->msgH7Reminder($candidate),
            default => 'Halo Kak, ada yang bisa kami bantu? Jangan ragu untuk menghubungi kami ya Kak.',
        };
    }

    private function msgStaleLead(FollowUpCandidateDTO $c): string
    {
        return "Halo Kak, kami ingin memastikan semua pertanyaan Kakak sudah terjawab.\n\n"
            . "Apakah ada yang bisa kami bantu lebih lanjut mengenai paket wedding kami? "
            . "Tim kami siap membantu Kakak merencanakan hari spesial tersebut.\n\n"
            . "Hubungi kami kapan saja ya Kak!";
    }

    private function msgPendingDp(FollowUpCandidateDTO $c): string
    {
        $code = $c->context_data['booking_code'] ?? '-';
        return "Halo Kak, kami ingin mengingatkan mengenai booking {$code} yang masih menunggu pembayaran DP.\n\n"
            . "Untuk memastikan tanggal event Kakak tetap terblokir, mohon segera lakukan pembayaran DP ya Kak.\n\n"
            . "Jika ada pertanyaan mengenai pembayaran, jangan ragu untuk menghubungi kami.";
    }

    private function msgInvoiceOverdue(FollowUpCandidateDTO $c): string
    {
        $inv    = $c->context_data['invoice_number'] ?? '-';
        $due    = $c->context_data['due_date'] ?? '-';
        $amount = number_format((int) ($c->context_data['amount'] ?? 0), 0, ',', '.');
        return "Halo Kak, kami ingin mengingatkan bahwa invoice {$inv} dengan nominal Rp {$amount} "
            . "telah melewati tanggal jatuh tempo ({$due}).\n\n"
            . "Mohon segera lakukan pembayaran atau hubungi kami untuk informasi lebih lanjut ya Kak.";
    }

    private function msgH7Reminder(FollowUpCandidateDTO $c): string
    {
        $code  = $c->context_data['booking_code'] ?? '-';
        $date  = $c->context_data['event_date'] ?? '-';
        $type  = $c->context_data['event_type'] ?? 'event';
        $loc   = $c->context_data['location'] ?? '-';
        return "Halo Kak! Tinggal 7 hari lagi menuju hari spesial Kakak.\n\n"
            . "Detail event:\n"
            . "- Booking: {$code}\n"
            . "- Tanggal: {$date}\n"
            . "- Tipe: {$type}\n"
            . "- Lokasi: {$loc}\n\n"
            . "Kami sudah siap memberikan pelayanan terbaik untuk Kakak. "
            . "Jika ada perubahan atau pertanyaan, segera hubungi kami ya Kak!";
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function lockKey(FollowUpCandidateDTO $candidate): string
    {
        $convId = $candidate->conversation_id ?? ('noconv_' . $candidate->booking_id . '_' . $candidate->invoice_id);
        $today  = Carbon::now()->toDateString();
        return "follow_up:{$convId}:{$candidate->reason}:{$today}";
    }

    private function alreadySentToday(string $conversationId, string $reason): bool
    {
        $today = Carbon::now()->toDateString();
        $key   = "follow_up:{$conversationId}:{$reason}:{$today}";
        if (Cache::has($key)) {
            return true;
        }

        // Also check DB as fallback (Redis may have been flushed)
        return FollowUpLog::withoutGlobalScopes()
            ->where('conversation_id', $conversationId)
            ->where('reason', $reason)
            ->where('sent_at', '>=', Carbon::today()->startOfDay())
            ->exists();
    }
}
