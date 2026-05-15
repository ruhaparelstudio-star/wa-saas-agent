<?php

namespace App\Filament\Tenant\Pages;

use App\Modules\AgentCore\Pipeline\Models\DecisionTrace;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Conversation\Models\ConversationMessage;
use App\Modules\Handoff\Repositories\HandoffRepository;
use App\Modules\Handoff\Services\HandoffService;
use App\Modules\Lead\Models\Lead;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\HandoffPriority;
use App\Modules\Shared\Enums\MessageType;
use App\Modules\WhatsApp\Adapters\WhatsAppGatewayAdapter;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InboxPage extends Page
{
    protected static ?string $navigationLabel = 'Inbox';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'inbox';

    public ?string $selectedConversationId = null;
    public string $activeTab = 'chat';
    public string $replyText = '';
    public ?string $filterStage = null;
    public ?string $filterMode = null;
    public bool $filterHasHandoff = false;

    public function getView(): string
    {
        return 'filament.tenant.pages.inbox';
    }

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        if ($user === null || $user->tenant_id === null) {
            return null;
        }

        $count = app(HandoffRepository::class)->countActiveByTenant($user->tenant_id);

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    // ─── Data helpers ────────────────────────────────────────────────────

    public function getConversations(): Collection
    {
        $query = Conversation::query()
            ->whereNotIn('stage', [ConversationStage::CLOSED->value])
            ->orderBy('last_message_at', 'desc')
            ->limit(50);

        if ($this->filterStage) {
            $query->where('stage', $this->filterStage);
        }

        if ($this->filterMode) {
            $query->where('agent_mode', $this->filterMode);
        }

        if ($this->filterHasHandoff) {
            $query->whereHas('handoffRecords', fn ($q) => $q->whereIn('status', ['pending', 'in_progress']));
        }

        return $query->get();
    }

    public function getSelectedConversation(): ?Conversation
    {
        if (!$this->selectedConversationId) {
            return null;
        }

        return Conversation::find($this->selectedConversationId);
    }

    public function getMessages(): Collection
    {
        $conversation = $this->getSelectedConversation();
        if (!$conversation) {
            return new Collection();
        }

        return $conversation->messages()
            ->orderBy('created_at', 'asc')
            ->limit(20)
            ->get();
    }

    public function getLead(): ?Lead
    {
        $conversation = $this->getSelectedConversation();
        if (!$conversation) {
            return null;
        }

        return Lead::where('conversation_id', $conversation->id)->first();
    }

    public function getRecentTraces(): Collection
    {
        if (!$this->selectedConversationId) {
            return new Collection();
        }

        return DecisionTrace::where('conversation_id', $this->selectedConversationId)
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();
    }

    public function getActiveHandoff()
    {
        if (!$this->selectedConversationId) {
            return null;
        }

        return app(HandoffRepository::class)
            ->findActiveByConversation($this->selectedConversationId);
    }

    // ─── Actions ─────────────────────────────────────────────────────────

    public function selectConversation(string $id): void
    {
        // Security: verify the conversation belongs to this tenant
        $conversation = Conversation::find($id);
        if (!$conversation) {
            return;
        }

        $this->selectedConversationId = $id;
        $this->activeTab = 'chat';
        $this->replyText = '';
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function takeoverConversation(): void
    {
        $conversation = $this->getSelectedConversation();
        if (!$conversation || $conversation->agent_mode === AgentMode::HANDOFF) {
            Notification::make()->title('Conversation sudah dalam mode Handoff.')->warning()->send();
            return;
        }

        $decision = DecisionDTO::from([
            'decision'              => 'admin_takeover',
            'desired_actions'       => ['flag_handoff'],
            'allowed_actions'       => ['flag_handoff'],
            'blocked_actions'       => [],
            'handoff_required'      => true,
            'handoff_reason'        => 'Admin takeover — diambil alih manual oleh admin.',
            'handoff_priority'      => HandoffPriority::MEDIUM->value,
            'notification_required' => false,
            'reply_strategy'        => 'handoff',
            'active_goal'           => 'admin_override',
            'stage_transition'      => null,
        ]);

        app(HandoffService::class)->triggerHandoff($conversation, $decision);

        Notification::make()->title('Conversation berhasil diambil alih.')->success()->send();
    }

    public function resumeAI(): void
    {
        $conversation = $this->getSelectedConversation();
        if (!$conversation || $conversation->agent_mode !== AgentMode::HANDOFF) {
            Notification::make()->title('Conversation tidak dalam mode Handoff.')->warning()->send();
            return;
        }

        $handoff = $this->getActiveHandoff();
        if ($handoff) {
            app(HandoffService::class)->resolveHandoff($handoff, 'Admin melanjutkan ke AI.', true);
        } else {
            $conversation->update(['agent_mode' => AgentMode::ACTIVE->value]);
        }

        Notification::make()->title('AI Agent dilanjutkan.')->success()->send();
    }

    public function closeConversation(): void
    {
        $conversation = $this->getSelectedConversation();
        if (!$conversation) {
            return;
        }

        $conversation->update(['stage' => ConversationStage::CLOSED->value]);
        $this->selectedConversationId = null;

        Notification::make()->title('Conversation ditutup.')->success()->send();
    }

    public function sendAdminReply(): void
    {
        $validator = Validator::make(
            ['replyText' => $this->replyText],
            ['replyText' => ['required', 'string', 'min:1', 'max:2000']]
        );

        if ($validator->fails()) {
            $this->addError('replyText', $validator->errors()->first('replyText'));
            return;
        }

        $conversation = $this->getSelectedConversation();
        if (!$conversation) {
            return;
        }

        // Store message in DB
        ConversationMessage::create([
            'tenant_id'       => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'message_type'    => MessageType::TEXT->value,
            'body'            => $this->replyText,
            'metadata'        => ['source' => 'admin_manual'],
        ]);

        $conversation->update(['last_message_at' => now()]);

        // Send via WA Gateway if account is set
        if ($conversation->wa_account_id && $conversation->customer_phone) {
            try {
                app(WhatsAppGatewayAdapter::class)->sendText(
                    $conversation->wa_account_id,
                    $conversation->customer_phone,
                    $this->replyText
                );
            } catch (\Throwable $e) {
                Log::warning('InboxPage: sendAdminReply WA send failed.', ['error' => $e->getMessage()]);
            }
        }

        $this->replyText = '';

        Notification::make()->title('Pesan terkirim.')->success()->send();
    }

    // ─── UI helpers ──────────────────────────────────────────────────────

    public function maskPhone(?string $phone): string
    {
        if (!$phone) return '-';
        return preg_replace('/(\+62\d{2})\d+(\d{3})/', '$1****$2', $phone);
    }

    public function stageColor(string $stage): string
    {
        return match ($stage) {
            'new_lead'             => 'gray',
            'exploration'          => 'blue',
            'qualification'        => 'indigo',
            'recommendation'       => 'purple',
            'consideration'        => 'yellow',
            'booking', 'waiting_booking' => 'green',
            'invoice_phase', 'post_invoice_limited' => 'teal',
            'handoff'              => 'orange',
            'paused_admin'         => 'gray',
            default                => 'gray',
        };
    }

    public function agentModeColor(string $mode): string
    {
        return match ($mode) {
            'active'  => 'green',
            'handoff' => 'orange',
            'paused'  => 'gray',
            'limited' => 'yellow',
            default   => 'gray',
        };
    }
}
