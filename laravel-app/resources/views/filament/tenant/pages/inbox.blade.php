<x-filament-panels::page>
    <div class="flex h-[calc(100vh-12rem)] gap-4 overflow-hidden" wire:poll.10000ms>

        {{-- ── LEFT PANEL: Conversation List ───────────────────────────── --}}
        <div class="w-1/3 flex flex-col bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden shadow-sm">

            {{-- Filters --}}
            <div class="p-3 border-b border-gray-200 dark:border-gray-700 space-y-2">
                <div class="flex gap-2">
                    <select
                        wire:model.live="filterStage"
                        class="flex-1 text-xs border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1.5 bg-white dark:bg-gray-700 dark:text-gray-100 focus:outline-none focus:ring-1 focus:ring-primary-500"
                    >
                        <option value="">Semua Stage</option>
                        @foreach(\App\Modules\Shared\Enums\ConversationStage::cases() as $s)
                            @if($s !== \App\Modules\Shared\Enums\ConversationStage::CLOSED)
                                <option value="{{ $s->value }}">{{ $s->label() }}</option>
                            @endif
                        @endforeach
                    </select>
                    <select
                        wire:model.live="filterMode"
                        class="flex-1 text-xs border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1.5 bg-white dark:bg-gray-700 dark:text-gray-100 focus:outline-none focus:ring-1 focus:ring-primary-500"
                    >
                        <option value="">Semua Mode</option>
                        @foreach(\App\Modules\Shared\Enums\AgentMode::cases() as $m)
                            <option value="{{ $m->value }}">{{ $m->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300 cursor-pointer">
                    <input type="checkbox" wire:model.live="filterHasHandoff" class="rounded border-gray-300 text-primary-600">
                    Hanya Handoff
                </label>
            </div>

            {{-- List --}}
            <div class="flex-1 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($this->getConversations() as $conv)
                    @php
                        $isSelected = $selectedConversationId === $conv->id;
                        $stageColor = $this->stageColor($conv->stage->value);
                        $modeColor  = $this->agentModeColor($conv->agent_mode->value);
                    @endphp
                    <button
                        wire:click="selectConversation('{{ $conv->id }}')"
                        class="w-full text-left p-3 hover:bg-gray-50 dark:hover:bg-gray-750 transition-colors {{ $isSelected ? 'bg-primary-50 dark:bg-primary-900/20 border-l-2 border-primary-500' : '' }}"
                    >
                        <div class="flex items-start justify-between gap-1">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">
                                    {{ $conv->customer_name ?: 'Unknown' }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                    {{ $this->maskPhone($conv->customer_phone) }}
                                </p>
                            </div>
                            <div class="flex flex-col items-end gap-1 shrink-0">
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium
                                    {{ $conv->agent_mode === \App\Modules\Shared\Enums\AgentMode::HANDOFF ? 'bg-orange-100 text-orange-700' : ($conv->agent_mode === \App\Modules\Shared\Enums\AgentMode::ACTIVE ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600') }}">
                                    {{ $conv->agent_mode->label() }}
                                </span>
                            </div>
                        </div>
                        <div class="mt-1 flex items-center gap-2">
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs
                                bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                {{ $conv->stage->label() }}
                            </span>
                            @if($conv->last_message_at)
                                <span class="text-xs text-gray-400 dark:text-gray-500 ml-auto">
                                    {{ $conv->last_message_at->diffForHumans() }}
                                </span>
                            @endif
                        </div>
                    </button>
                @empty
                    <div class="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
                        Tidak ada conversation aktif.
                    </div>
                @endforelse
            </div>
        </div>

        {{-- ── RIGHT PANEL: Context Panel ───────────────────────────────── --}}
        <div class="flex-1 flex flex-col bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden shadow-sm">

            @if($selectedConversationId && ($conv = $this->getSelectedConversation()))

                {{-- Header --}}
                <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                            {{ $conv->customer_name ?: 'Unknown' }}
                            <span class="ml-1 text-sm font-normal text-gray-500">
                                {{ $this->maskPhone($conv->customer_phone) }}
                            </span>
                        </h2>
                        <div class="mt-1 flex items-center gap-2">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                {{ $conv->stage->label() }}
                            </span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                {{ $conv->agent_mode === \App\Modules\Shared\Enums\AgentMode::HANDOFF ? 'bg-orange-100 text-orange-700' : ($conv->agent_mode === \App\Modules\Shared\Enums\AgentMode::ACTIVE ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600') }}">
                                {{ $conv->agent_mode->label() }}
                            </span>
                        </div>
                    </div>

                    {{-- Action Buttons --}}
                    <div class="flex items-center gap-2">
                        @if($conv->agent_mode === \App\Modules\Shared\Enums\AgentMode::ACTIVE)
                            <button
                                wire:click="takeoverConversation"
                                wire:confirm="Ambil alih conversation ini? AI akan berhenti membalas."
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-orange-100 text-orange-700 hover:bg-orange-200 dark:bg-orange-900/30 dark:text-orange-300 transition-colors"
                            >
                                <x-heroicon-o-hand-raised class="w-4 h-4"/>
                                Ambil Alih
                            </button>
                        @elseif($conv->agent_mode === \App\Modules\Shared\Enums\AgentMode::HANDOFF)
                            <button
                                wire:click="resumeAI"
                                wire:confirm="Lanjutkan AI Agent untuk conversation ini?"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-green-100 text-green-700 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-300 transition-colors"
                            >
                                <x-heroicon-o-play class="w-4 h-4"/>
                                Resume AI
                            </button>
                        @endif
                        <button
                            wire:click="closeConversation"
                            wire:confirm="Tutup conversation ini?"
                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 transition-colors"
                        >
                            <x-heroicon-o-archive-box class="w-4 h-4"/>
                            Tutup
                        </button>
                    </div>
                </div>

                {{-- Tabs --}}
                <div class="border-b border-gray-200 dark:border-gray-700 flex">
                    @foreach([['chat', 'Chat History'], ['lead', 'Lead Info'], ['trace', 'AI Trace']] as [$tab, $label])
                        <button
                            wire:click="setActiveTab('{{ $tab }}')"
                            class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors
                                {{ $activeTab === $tab
                                    ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                                    : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200' }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                {{-- Tab Content --}}
                <div class="flex-1 overflow-y-auto">

                    {{-- Chat History Tab --}}
                    @if($activeTab === 'chat')
                        <div class="p-4 space-y-3">
                            @forelse($this->getMessages() as $msg)
                                <div class="flex {{ $msg->direction === 'outbound' ? 'justify-end' : 'justify-start' }}">
                                    <div class="max-w-xs lg:max-w-md xl:max-w-lg">
                                        <div class="px-3 py-2 rounded-xl text-sm
                                            {{ $msg->direction === 'outbound'
                                                ? 'bg-primary-500 text-white rounded-br-none'
                                                : 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-bl-none' }}">
                                            {{ $msg->body ?: '[' . $msg->message_type->value . ']' }}
                                        </div>
                                        <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500
                                            {{ $msg->direction === 'outbound' ? 'text-right' : 'text-left' }}">
                                            {{ $msg->created_at->diffForHumans() }}
                                        </p>
                                    </div>
                                </div>
                            @empty
                                <p class="text-center text-sm text-gray-500 dark:text-gray-400 py-6">
                                    Belum ada pesan.
                                </p>
                            @endforelse
                        </div>

                        {{-- Admin Reply Form (only when HANDOFF) --}}
                        @if($conv->agent_mode === \App\Modules\Shared\Enums\AgentMode::HANDOFF)
                            <div class="p-4 border-t border-gray-200 dark:border-gray-700">
                                <p class="text-xs text-orange-600 dark:text-orange-400 mb-2 font-medium">
                                    Mode Handoff — Balas manual di bawah ini:
                                </p>
                                <div class="flex gap-2">
                                    <textarea
                                        wire:model="replyText"
                                        rows="2"
                                        placeholder="Ketik pesan balasan..."
                                        class="flex-1 text-sm border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-2 resize-none bg-white dark:bg-gray-700 dark:text-gray-100 focus:outline-none focus:ring-1 focus:ring-primary-500"
                                    ></textarea>
                                    <button
                                        wire:click="sendAdminReply"
                                        class="px-4 py-2 text-sm font-medium rounded-lg bg-primary-600 text-white hover:bg-primary-700 transition-colors self-end"
                                    >
                                        Kirim
                                    </button>
                                </div>
                            </div>
                        @endif
                    @endif

                    {{-- Lead Info Tab --}}
                    @if($activeTab === 'lead')
                        @php $lead = $this->getLead(); @endphp
                        <div class="p-4">
                            @if($lead)
                                <div class="mb-4">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Lead Score</p>
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                            <div
                                                class="bg-primary-500 h-2 rounded-full transition-all"
                                                style="width: {{ min(100, $lead->lead_score ?? 0) }}%"
                                            ></div>
                                        </div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                            {{ $lead->lead_score ?? 0 }}/100
                                        </span>
                                    </div>
                                </div>

                                <dl class="space-y-3">
                                    @foreach([
                                        ['Nama Customer', $lead->customer_name],
                                        ['Tanggal Event', $lead->event_date?->format('d M Y')],
                                        ['Tipe Event', $lead->event_type],
                                        ['Lokasi', $lead->location],
                                        ['Jumlah Tamu', $lead->guest_count ? number_format($lead->guest_count) . ' orang' : null],
                                        ['Budget', ($lead->budget_min || $lead->budget_max) ? 'Rp ' . number_format($lead->budget_min ?? 0) . ' - Rp ' . number_format($lead->budget_max ?? 0) : null],
                                        ['Paket Minat', $lead->package_interest],
                                        ['Suhu Lead', $lead->temperature?->label() ?? null],
                                    ] as [$label, $value])
                                        @if($value)
                                            <div class="flex gap-3">
                                                <dt class="w-32 text-xs text-gray-500 dark:text-gray-400 shrink-0">{{ $label }}</dt>
                                                <dd class="text-sm text-gray-900 dark:text-gray-100">{{ $value }}</dd>
                                            </div>
                                        @endif
                                    @endforeach
                                </dl>
                            @else
                                <p class="text-sm text-gray-500 dark:text-gray-400 py-4 text-center">
                                    Belum ada data lead.
                                </p>
                            @endif
                        </div>
                    @endif

                    {{-- AI Trace Tab --}}
                    @if($activeTab === 'trace')
                        <div class="p-4 space-y-4">
                            @forelse($this->getRecentTraces() as $trace)
                                <div class="bg-gray-50 dark:bg-gray-750 rounded-lg p-3 border border-gray-200 dark:border-gray-600 space-y-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">
                                            Intent: <span class="text-primary-600 dark:text-primary-400">{{ $trace->intent ?? '-' }}</span>
                                        </span>
                                        <span class="text-xs text-gray-400">{{ $trace->created_at->diffForHumans() }}</span>
                                    </div>
                                    <div class="text-xs text-gray-600 dark:text-gray-400">
                                        <span class="font-medium">Decision:</span> {{ $trace->decision ?? '-' }}
                                    </div>
                                    @if($trace->final_reply)
                                        <div class="text-xs text-gray-600 dark:text-gray-400">
                                            <span class="font-medium">Reply:</span>
                                            {{ Str::limit($trace->final_reply, 100) }}
                                        </div>
                                    @endif
                                    @if($trace->injection_detected)
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-red-100 text-red-700">
                                            Injection Detected
                                        </span>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-gray-500 dark:text-gray-400 py-4 text-center">
                                    Belum ada AI trace.
                                </p>
                            @endforelse
                        </div>
                    @endif

                </div>

            @else
                {{-- Empty state --}}
                <div class="flex-1 flex items-center justify-center">
                    <div class="text-center">
                        <x-heroicon-o-chat-bubble-left-right class="w-12 h-12 mx-auto text-gray-300 dark:text-gray-600 mb-3"/>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Pilih conversation dari daftar kiri untuk melihat detail.
                        </p>
                    </div>
                </div>
            @endif
        </div>

    </div>
</x-filament-panels::page>
