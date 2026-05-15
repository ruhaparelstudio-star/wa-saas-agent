<?php

namespace App\Modules\Notification\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Handoff\Models\HandoffRecord;
use App\Modules\Notification\Jobs\SendHandoffEmailJob;
use App\Modules\Notification\Models\AdminNotification;
use App\Modules\Shared\Enums\NotificationType;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\WhatsApp\Models\WaAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function notifyHandoffRequired(Conversation $conversation, HandoffRecord $record): void
    {
        $admins = $this->getTenantAdmins($conversation->tenant_id);

        $maskedPhone = $this->maskPhone($conversation->customer_phone);
        $title = 'Handoff Required: ' . $maskedPhone;
        $body  = sprintf(
            'Percakapan dengan %s memerlukan penanganan manual. Prioritas: %s. Alasan: %s',
            $maskedPhone,
            strtoupper($record->priority->value),
            $record->reason ?? '-'
        );

        foreach ($admins as $admin) {
            AdminNotification::create([
                'tenant_id' => $conversation->tenant_id,
                'user_id'   => $admin->id,
                'type'      => NotificationType::HANDOFF_REQUIRED->value,
                'title'     => $title,
                'body'      => $body,
                'data'      => [
                    'conversation_id'  => $conversation->id,
                    'handoff_record_id'=> $record->id,
                    'priority'         => $record->priority->value,
                    'reason'           => $record->reason,
                ],
            ]);

            SendHandoffEmailJob::dispatch($admin->email, $admin->name, [
                'customer_phone' => $maskedPhone,
                'stage'          => $conversation->stage->value,
                'reason'         => $record->reason ?? '-',
                'priority'       => strtoupper($record->priority->value),
            ]);
        }

        Log::info('NotificationService: handoff_required notifications sent.', [
            'conversation_id'   => $conversation->id,
            'handoff_record_id' => $record->id,
            'admin_count'       => $admins->count(),
        ]);
    }

    public function notifyWaDisconnected(WaAccount $account): void
    {
        $admins = $this->getTenantAdmins($account->tenant_id);

        $title = 'WA Account Terputus: ' . ($account->display_name ?? $account->phone_number ?? '-');
        $body  = sprintf(
            'WA Account "%s" terputus. Silakan reconnect melalui panel admin.',
            $account->display_name ?? '-'
        );

        foreach ($admins as $admin) {
            AdminNotification::create([
                'tenant_id' => $account->tenant_id,
                'user_id'   => $admin->id,
                'type'      => NotificationType::WA_DISCONNECTED->value,
                'title'     => $title,
                'body'      => $body,
                'data'      => [
                    'wa_account_id'  => $account->id,
                    'display_name'   => $account->display_name,
                    'phone_number'   => $this->maskPhone($account->phone_number ?? ''),
                ],
            ]);
        }

        Log::info('NotificationService: wa_disconnected notifications sent.', [
            'wa_account_id' => $account->id,
            'admin_count'   => $admins->count(),
        ]);
    }

    public function notifyInjectionAttempt(string $tenantId, string $conversationId): void
    {
        $admins = $this->getTenantAdmins($tenantId);

        $title = 'Peringatan: Percobaan Injeksi Prompt Terdeteksi';
        $body  = 'Ada percobaan prompt injection pada conversation. Tim kami telah menangani otomatis.';

        foreach ($admins as $admin) {
            AdminNotification::create([
                'tenant_id' => $tenantId,
                'user_id'   => $admin->id,
                'type'      => NotificationType::INJECTION_ATTEMPT_DETECTED->value,
                'title'     => $title,
                'body'      => $body,
                'data'      => [
                    'conversation_id' => $conversationId,
                ],
            ]);
        }

        Log::warning('NotificationService: injection_attempt notifications sent.', [
            'tenant_id'       => $tenantId,
            'conversation_id' => $conversationId,
        ]);
    }

    public function markAllRead(string $userId): void
    {
        AdminNotification::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function getUnread(string $userId, int $limit = 20): Collection
    {
        return AdminNotification::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function countUnread(string $userId): int
    {
        return AdminNotification::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    private function getTenantAdmins(string $tenantId): Collection
    {
        return User::where('tenant_id', $tenantId)
            ->where('role', UserRole::TENANT_ADMIN->value)
            ->where('is_active', true)
            ->get();
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) {
            return $phone;
        }
        // +628123456789 → +628***6789
        $prefix = substr($phone, 0, 4);
        $suffix = substr($phone, -4);
        return $prefix . '***' . $suffix;
    }
}
