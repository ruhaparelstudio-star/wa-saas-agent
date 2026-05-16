<x-filament-panels::page>
    <div class="flex h-[calc(100vh-10rem)] gap-4 overflow-hidden" wire:poll.8000ms>

        {{-- ── LEFT PANEL: Conversation List ───────────────────────────── --}}
        <div class="w-80 shrink-0 flex flex-col bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden shadow-sm">

            {{-- Header --}}
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Percakapan Aktif</h2>
            </div>

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

            {{-- Conversation List --}}
            <div class="flex-1 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($this->getConversations() as $conv)
                    @php $isSelected = $selectedConversationId === $conv->id; @endphp
                    <button
                        wire:click="selectConversation('{{ $conv->id }}')"
                        class="w-full text-left p-3 transition-colors {{ $isSelected ? 'bg-primary-50 dark:bg-primary-900/20 border-l-2 border-primary-500' : 'hover:bg-gray-50 dark:hover:bg-gray-750' }}"
                    >
                        <div class="flex items-start justify-between gap-1">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">
                                    {{ $conv->customer_name ?: 'Unknown' }}
                                </p>
                                <p class="text-xs text-gray-400 dark:text-gray-500 truncate">
                                    {{ $this->maskPhone($conv->customer_phone) }}
                                </p>
                            </div>
                            <span @class([
                                'shrink-0 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium',
                                'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300' => $conv->agent_mode === \App\Modules\Shared\Enums\AgentMode::HANDOFF,
                                'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' => $conv->agent_mode === \App\Modules\Shared\Enums\AgentMode::ACTIVE,
                                'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => !in_array($conv->agent_mode, [\App\Modules\Shared\Enums\AgentMode::HANDOFF, \App\Modules\Shared\Enums\AgentMode::ACTIVE]),
                            ])>
                                {{ $conv->agent_mode->label() }}
                            </span>
                        </div>
                        <div class="mt-1.5 flex items-center gap-2">
                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400">
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
                    <div class="p-8 text-center">
                        <x-heroicon-o-chat-bubble-left-right class="w-8 h-8 mx-auto text-gray-300 dark:text-gray-600 mb-2"/>
                        <p class="text-sm text-gray-400 dark:text-gray-500">Tidak ada conversation aktif.</p>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- ── RIGHT PANEL ─────────────────────────────────────────────── --}}
        <div class="flex-1 min-w-0 flex flex-col bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden shadow-sm">

            @if($selectedConversationId && ($conv = $this->getSelectedConversation()))

                {{-- Header --}}
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between gap-3 shrink-0">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 truncate">
                            {{ $conv->customer_name ?: 'Unknown' }}
                            <span class="ml-1 text-sm font-normal text-gray-400">
                                {{ $this->maskPhone($conv->customer_phone) }}
                            </span>
                        </h2>
                        <div class="mt-1 flex items-center gap-2 flex-wrap">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                {{ $conv->stage->label() }}
                            </span>
                            <span @class([
                                'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                                'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300' => $conv->agent_mode === \App\Modules\Shared\Enums\AgentMode::HANDOFF,
                                'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' => $conv->agent_mode === \App\Modules\Shared\Enums\AgentMode::ACTIVE,
                                'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => !in_array($conv->agent_mode, [\App\Modules\Shared\Enums\AgentMode::HANDOFF, \App\Modules\Shared\Enums\AgentMode::ACTIVE]),
                            ])>
                                {{ $conv->agent_mode->label() }}
                            </span>
                        </div>
                    </div>

                    {{-- Action Buttons --}}
                    <div class="flex items-center gap-2 shrink-0">
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
                                wire:confirm="Kembalikan ke AI Agent?"
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
                <div class="border-b border-gray-200 dark:border-gray-700 flex shrink-0">
                    @foreach([['chat', 'Chat'], ['lead', 'Lead Info'], ['trace', 'AI Trace']] as [$tab, $label])
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
                @if($activeTab === 'chat')
                    {{-- Chat messages scrollable area --}}
                    <div class="flex-1 overflow-y-auto p-4 space-y-3">
                        @forelse($this->getMessages() as $msg)
                            <div class="flex {{ $msg->direction === 'outbound' ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-[70%]">
                                    <div class="px-3 py-2 rounded-2xl text-sm leading-relaxed
                                        {{ $msg->direction === 'outbound'
                                            ? 'bg-primary-500 text-white rounded-br-sm'
                                            : 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-bl-sm' }}">
                                        {{ $msg->body ?: '[' . $msg->message_type->value . ']' }}
                                        @if(!empty($msg->metadata['source']) && $msg->metadata['source'] === 'admin_manual')
                                            <span class="ml-1 text-xs opacity-60">[Admin]</span>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500
                                        {{ $msg->direction === 'outbound' ? 'text-right' : 'text-left' }}">
                                        {{ $msg->created_at->diffForHumans() }}
                                    </p>
                                </div>
                            </div>
                        @empty
                            <div class="flex items-center justify-center h-32">
                                <p class="text-sm text-gray-400 dark:text-gray-500">Belum ada pesan.</p>
                            </div>
                        @endforelse
                    </div>

                    {{-- Reply Form — always visible, context-aware --}}
                    <div class="shrink-0 border-t border-gray-200 dark:border-gray-700 px-4 py-3">
                        @if($conv->agent_mode === \App\Modules\Shared\Enums\AgentMode::ACTIVE)
                            <p class="text-xs text-blue-600 dark:text-blue-400 mb-2">
                                <x-heroicon-o-information-circle class="w-3.5 h-3.5 inline mr-1"/>
                                AI sedang aktif. Klik <strong>Ambil Alih</strong> untuk membalas manual, atau kirim pesan di bawah (AI tetap aktif).
                            </p>
                        @elseif($conv->agent_mode === \App\Modules\Shared\Enums\AgentMode::HANDOFF)
                            <p class="text-xs text-orange-600 dark:text-orange-400 mb-2">
                                <x-heroicon-o-hand-raised class="w-3.5 h-3.5 inline mr-1"/>
                                Mode Handoff — AI tidak akan membalas. Balas di bawah ini.
                            </p>
                        @endif
                        <div class="flex gap-2">
                            <textarea
                                wire:model="replyText"
                                rows="2"
                                placeholder="Ketik pesan balasan..."
                                class="flex-1 text-sm border border-gray-300 dark:border-gray-600 rounded-xl px-3 py-2 resize-none bg-white dark:bg-gray-700 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-500"
                            ></textarea>
                            <button
                                wire:click="sendAdminReply"
                                wire:loading.attr="disabled"
                                class="px-4 py-2 text-sm font-medium rounded-xl bg-primary-600 text-white hover:bg-primary-700 active:bg-primary-800 transition-colors self-end disabled:opacity-60"
                            >
                                <span wire:loading.remove wire:target="sendAdminReply">Kirim</span>
                                <span wire:loading wire:target="sendAdminReply">...</span>
                            </button>
                        </div>
                        @error('replyText')
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                @elseif($activeTab === 'lead')
                    @php $lead = $this->getLead(); @endphp
                    <div class="flex-1 overflow-y-auto p-4">
                        @if($lead)
                            {{-- Lead Score --}}
                            <div class="mb-5">
                                <div class="flex items-center justify-between mb-1">
                                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Lead Score</p>
                                    <span class="text-sm font-bold text-gray-700 dark:text-gray-300">{{ $lead->lead_score ?? 0 }}/100</span>
                                </div>
                                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                    <div
                                        class="h-2 rounded-full transition-all {{ ($lead->lead_score ?? 0) >= 70 ? 'bg-green-500' : (($lead->lead_score ?? 0) >= 40 ? 'bg-yellow-500' : 'bg-red-400') }}"
                                        style="width: {{ min(100, $lead->lead_score ?? 0) }}%"
                                    ></div>
                                </div>
                            </div>

                            <dl class="space-y-3">
                                @foreach([
                                    ['Nama Customer', $lead->customer_name],
                                    ['Tanggal Event', $lead->event_date?->format('d M Y')],
                                    ['Tipe Event', $lead->event_type],
                                    ['Lokasi', $lead->location],
                                    ['Jumlah Tamu', $lead->guest_count ? number_format($lead->guest_count) . ' orang' : null],
                                    ['Budget', ($lead->budget_min || $lead->budget_max) ? 'Rp ' . number_format($lead->budget_min ?? 0) . ' – Rp ' . number_format($lead->budget_max ?? 0) : null],
                                    ['Paket Minat', $lead->package_interest],
                                    ['Suhu Lead', $lead->temperature?->label() ?? null],
                                ] as [$label, $value])
                                    @if($value)
                                        <div class="flex gap-3 py-2 border-b border-gray-100 dark:border-gray-700 last:border-0">
                                            <dt class="w-32 text-xs text-gray-500 dark:text-gray-400 shrink-0 pt-0.5">{{ $label }}</dt>
                                            <dd class="text-sm text-gray-900 dark:text-gray-100 font-medium">{{ $value }}</dd>
                                        </div>
                                    @endif
                                @endforeach
                            </dl>
                        @else
                            <div class="flex items-center justify-center h-32">
                                <p class="text-sm text-gray-400 dark:text-gray-500">Belum ada data lead.</p>
                            </div>
                        @endif
                    </div>

                @elseif($activeTab === 'trace')
                    <div class="flex-1 overflow-y-auto p-4 space-y-4">
                        @forelse($this->getRecentTraces() as $trace)
                            <div class="bg-gray-50 dark:bg-gray-750 rounded-xl p-3 border border-gray-200 dark:border-gray-600 space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">
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
                                        {{ Str::limit($trace->final_reply, 120) }}
                                    </div>
                                @endif
                                @if($trace->injection_detected)
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300">
                                        ⚠ Injection Detected
                                    </span>
                                @endif
                            </div>
                        @empty
                            <div class="flex items-center justify-center h-32">
                                <p class="text-sm text-gray-400 dark:text-gray-500">Belum ada AI trace.</p>
                            </div>
                        @endforelse
                    </div>
                @endif

            @else
                {{-- Empty state --}}
                <div class="flex-1 flex items-center justify-center">
                    <div class="text-center">
                        <x-heroicon-o-chat-bubble-left-right class="w-14 h-14 mx-auto text-gray-200 dark:text-gray-700 mb-4"/>
                        <p class="text-base font-medium text-gray-500 dark:text-gray-400">Pilih percakapan</p>
                        <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">Pilih conversation dari daftar kiri untuk melihat detail.</p>
                    </div>
                </div>
            @endif
        </div>

    </div>
</x-filament-panels::page>
