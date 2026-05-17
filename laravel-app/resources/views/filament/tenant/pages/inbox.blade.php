<x-filament-panels::page>
    <div class="flex h-[calc(100vh-9rem)] gap-0 overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm" wire:poll.10000ms>

        {{-- ══ LEFT PANEL: Conversation List ══════════════════════════════════ --}}
        <div class="w-72 shrink-0 flex flex-col bg-gray-50 dark:bg-gray-900 border-r border-gray-200 dark:border-gray-700">

            {{-- Header --}}
            <div class="px-4 py-4 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 shrink-0">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Percakapan Aktif</h2>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">WhatsApp Inbox</p>
            </div>

            {{-- Filters --}}
            <div class="p-3 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 space-y-2 shrink-0">
                <select
                    wire:model.live="filterStage"
                    class="w-full text-xs border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 bg-gray-50 dark:bg-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                >
                    <option value="">Semua Stage</option>
                    @foreach(\App\Modules\Shared\Enums\ConversationStage::cases() as $s)
                        @if($s !== \App\Modules\Shared\Enums\ConversationStage::CLOSED)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endif
                    @endforeach
                </select>
                <div class="flex gap-2">
                    <select
                        wire:model.live="filterMode"
                        class="flex-1 text-xs border border-gray-200 dark:border-gray-600 rounded-lg px-3 py-2 bg-gray-50 dark:bg-gray-700 dark:text-gray-200 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                    >
                        <option value="">Semua Mode</option>
                        @foreach(\App\Modules\Shared\Enums\AgentMode::cases() as $m)
                            <option value="{{ $m->value }}">{{ $m->label() }}</option>
                        @endforeach
                    </select>
                    <label class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium text-gray-600 dark:text-gray-300 cursor-pointer border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors whitespace-nowrap">
                        <input type="checkbox" wire:model.live="filterHasHandoff" class="rounded border-gray-300 text-primary-600 w-3.5 h-3.5">
                        Handoff
                    </label>
                </div>
            </div>

            {{-- Conversation List --}}
            <div class="flex-1 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($this->getConversations() as $conv)
                    @php
                        $isSelected  = $selectedConversationId === $conv->id;
                        $isHandoff   = $conv->agent_mode === \App\Modules\Shared\Enums\AgentMode::HANDOFF;
                        $nameParts   = array_filter(explode(' ', $conv->customer_name ?: 'UN'));
                        $initials    = strtoupper(substr($nameParts[0] ?? 'U', 0, 1) . substr(array_values($nameParts)[1] ?? '', 0, 1));
                        $avatarClass = $isHandoff ? 'bg-orange-400' : 'bg-primary-500';
                    @endphp
                    <button
                        wire:click="selectConversation('{{ $conv->id }}')"
                        class="w-full text-left px-4 py-3 transition-all {{ $isSelected ? 'bg-primary-50 dark:bg-primary-900/20 border-l-[3px] border-l-primary-500' : 'border-l-[3px] border-l-transparent hover:bg-white dark:hover:bg-gray-800/60' }}"
                    >
                        <div class="flex items-center gap-3">
                            {{-- Avatar --}}
                            <div class="w-9 h-9 rounded-full shrink-0 flex items-center justify-center text-xs font-bold text-white {{ $avatarClass }}">
                                {{ $initials }}
                            </div>
                            {{-- Info --}}
                            <div class="flex-1 min-w-0">
                                <div class="flex items-baseline justify-between gap-1">
                                    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">
                                        {{ $conv->customer_name ?: 'Unknown' }}
                                    </p>
                                    @if($conv->last_message_at)
                                        <span class="text-[10px] text-gray-400 dark:text-gray-500 shrink-0">
                                            {{ $conv->last_message_at->diffForHumans(null, true, true) }}
                                        </span>
                                    @endif
                                </div>
                                <p class="text-xs text-gray-400 dark:text-gray-500 truncate mt-0.5">
                                    {{ $this->maskPhone($conv->customer_phone) }}
                                </p>
                                <div class="flex items-center gap-1.5 mt-1.5">
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400">
                                        {{ $conv->stage->label() }}
                                    </span>
                                    @if($isHandoff)
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400">
                                            Handoff
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </button>
                @empty
                    <div class="flex flex-col items-center justify-center h-44 px-6 text-center">
                        <x-heroicon-o-chat-bubble-left-right class="w-10 h-10 text-gray-200 dark:text-gray-700 mb-3"/>
                        <p class="text-sm font-medium text-gray-400 dark:text-gray-500">Tidak ada percakapan aktif</p>
                        <p class="text-xs text-gray-300 dark:text-gray-600 mt-1">Percakapan baru akan muncul di sini</p>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- ══ RIGHT PANEL ═════════════════════════════════════════════════════ --}}
        <div class="flex-1 min-w-0 flex flex-col bg-white dark:bg-gray-800">

            @if($selectedConversationId && ($conv = $this->getSelectedConversation()))
                @php
                    $isHandoff   = $conv->agent_mode === \App\Modules\Shared\Enums\AgentMode::HANDOFF;
                    $isActive    = $conv->agent_mode === \App\Modules\Shared\Enums\AgentMode::ACTIVE;
                    $nameParts   = array_filter(explode(' ', $conv->customer_name ?: 'UN'));
                    $initials    = strtoupper(substr($nameParts[0] ?? 'U', 0, 1) . substr(array_values($nameParts)[1] ?? '', 0, 1));
                    $avatarClass = $isHandoff ? 'bg-orange-400' : 'bg-primary-500';
                @endphp

                {{-- ── Conversation Header ──────────────────────────────────── --}}
                <div class="px-5 py-3.5 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-4 shrink-0">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-10 h-10 rounded-full shrink-0 flex items-center justify-center text-sm font-bold text-white {{ $avatarClass }}">
                            {{ $initials }}
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                                    {{ $conv->customer_name ?: 'Unknown' }}
                                </h2>
                                <span class="text-xs text-gray-400 dark:text-gray-500">
                                    {{ $this->maskPhone($conv->customer_phone) }}
                                </span>
                            </div>
                            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                    {{ $conv->stage->label() }}
                                </span>
                                <span @class([
                                    'inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium',
                                    'bg-orange-50 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400 ring-1 ring-orange-200 dark:ring-orange-800/50' => $isHandoff,
                                    'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400 ring-1 ring-green-200 dark:ring-green-800/50' => $isActive,
                                    'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => !$isHandoff && !$isActive,
                                ])>
                                    <span class="w-1.5 h-1.5 rounded-full inline-block {{ $isHandoff ? 'bg-orange-400' : ($isActive ? 'bg-green-400' : 'bg-gray-400') }}"></span>
                                    {{ $conv->agent_mode->label() }}
                                </span>
                            </div>
                        </div>
                    </div>

                    {{-- Actions --}}
                    <div class="flex items-center gap-2 shrink-0">
                        @if($isActive)
                            <button
                                wire:click="takeoverConversation"
                                wire:confirm="Ambil alih conversation ini? AI akan berhenti membalas."
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-orange-50 text-orange-700 hover:bg-orange-100 dark:bg-orange-900/20 dark:text-orange-400 dark:hover:bg-orange-900/30 border border-orange-200 dark:border-orange-800/50 transition-colors"
                            >
                                <x-heroicon-o-hand-raised class="w-3.5 h-3.5"/>
                                Ambil Alih
                            </button>
                        @elseif($isHandoff)
                            <button
                                wire:click="resumeAI"
                                wire:confirm="Kembalikan ke AI Agent?"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-green-50 text-green-700 hover:bg-green-100 dark:bg-green-900/20 dark:text-green-400 dark:hover:bg-green-900/30 border border-green-200 dark:border-green-800/50 transition-colors"
                            >
                                <x-heroicon-o-play class="w-3.5 h-3.5"/>
                                Resume AI
                            </button>
                        @endif
                        <button
                            wire:click="closeConversation"
                            wire:confirm="Tutup conversation ini?"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-gray-50 text-gray-600 hover:bg-gray-100 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 border border-gray-200 dark:border-gray-600 transition-colors"
                        >
                            <x-heroicon-o-archive-box class="w-3.5 h-3.5"/>
                            Tutup
                        </button>
                    </div>
                </div>

                {{-- ── Tabs ──────────────────────────────────────────────────── --}}
                <div class="border-b border-gray-100 dark:border-gray-700 flex shrink-0 px-2">
                    @foreach([
                        ['chat',  'Chat'],
                        ['lead',  'Info Lead'],
                        ['trace', 'AI Trace'],
                    ] as [$tab, $label])
                        <button
                            wire:click="setActiveTab('{{ $tab }}')"
                            class="px-4 py-3 text-xs font-medium border-b-2 transition-all
                                {{ $activeTab === $tab
                                    ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                                    : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 hover:border-gray-200 dark:hover:border-gray-600' }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                {{-- ── TAB: Chat ─────────────────────────────────────────────── --}}
                @if($activeTab === 'chat')
                    <div class="flex-1 overflow-y-auto p-4 space-y-2.5 bg-gray-50 dark:bg-gray-900/30">
                        @forelse($this->getMessages() as $msg)
                            @php
                                $isOut   = $msg->direction === 'outbound';
                                $isAdmin = !empty($msg->metadata['source']) && $msg->metadata['source'] === 'admin_manual';
                            @endphp
                            <div class="flex {{ $isOut ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-[70%] space-y-1">
                                    @if($isAdmin)
                                        <p class="text-[10px] font-medium text-orange-500 dark:text-orange-400 px-1 {{ $isOut ? 'text-right' : 'text-left' }}">Admin</p>
                                    @endif
                                    <div class="px-4 py-2.5 text-sm leading-relaxed shadow-sm
                                        {{ $isOut
                                            ? 'bg-primary-500 text-white rounded-2xl rounded-tr-sm'
                                            : 'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 border border-gray-100 dark:border-gray-600 rounded-2xl rounded-tl-sm' }}">
                                        {{ $msg->body ?: '[' . $msg->message_type->value . ']' }}
                                    </div>
                                    <p class="text-[10px] text-gray-400 dark:text-gray-500 px-1 {{ $isOut ? 'text-right' : 'text-left' }}">
                                        {{ $msg->created_at->format('H:i') }} · {{ $msg->created_at->diffForHumans() }}
                                    </p>
                                </div>
                            </div>
                        @empty
                            <div class="flex flex-col items-center justify-center h-40 text-center">
                                <x-heroicon-o-chat-bubble-left-right class="w-10 h-10 text-gray-200 dark:text-gray-700 mb-2"/>
                                <p class="text-sm text-gray-400 dark:text-gray-500">Belum ada pesan</p>
                            </div>
                        @endforelse
                    </div>

                    {{-- Reply Input --}}
                    <div class="shrink-0 border-t border-gray-100 dark:border-gray-700 px-4 py-3 bg-white dark:bg-gray-800">
                        @if($isActive)
                            <div class="flex items-center gap-2 mb-2.5 px-3 py-2 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800/50">
                                <x-heroicon-o-information-circle class="w-4 h-4 text-blue-500 shrink-0"/>
                                <p class="text-xs text-blue-600 dark:text-blue-400">
                                    AI aktif — klik <strong>Ambil Alih</strong> untuk mode manual, atau kirim pesan di bawah (AI tetap aktif).
                                </p>
                            </div>
                        @elseif($isHandoff)
                            <div class="flex items-center gap-2 mb-2.5 px-3 py-2 rounded-lg bg-orange-50 dark:bg-orange-900/20 border border-orange-100 dark:border-orange-800/50">
                                <x-heroicon-o-hand-raised class="w-4 h-4 text-orange-500 shrink-0"/>
                                <p class="text-xs text-orange-600 dark:text-orange-400">
                                    Mode Handoff — AI tidak aktif. Balas manual di bawah ini.
                                </p>
                            </div>
                        @endif
                        <div class="flex items-end gap-2">
                            <textarea
                                wire:model="replyText"
                                rows="2"
                                placeholder="Ketik pesan balasan..."
                                class="flex-1 text-sm border border-gray-200 dark:border-gray-600 rounded-xl px-3.5 py-2.5 resize-none bg-gray-50 dark:bg-gray-700 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent transition-all placeholder-gray-400 dark:placeholder-gray-500"
                            ></textarea>
                            <button
                                wire:click="sendAdminReply"
                                wire:loading.attr="disabled"
                                class="shrink-0 p-2.5 rounded-xl bg-primary-600 text-white hover:bg-primary-700 active:bg-primary-800 transition-colors disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="sendAdminReply">
                                    <x-heroicon-o-paper-airplane class="w-5 h-5"/>
                                </span>
                                <span wire:loading wire:target="sendAdminReply" class="block w-5 h-5">
                                    <x-heroicon-o-arrow-path class="w-5 h-5 animate-spin"/>
                                </span>
                            </button>
                        </div>
                        @error('replyText')
                            <p class="mt-1.5 text-xs text-red-500 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                {{-- ── TAB: Lead Info ────────────────────────────────────────── --}}
                @elseif($activeTab === 'lead')
                    @php $lead = $this->getLead(); @endphp
                    <div class="flex-1 overflow-y-auto p-5 space-y-4 bg-gray-50 dark:bg-gray-900/30">
                        @if($lead)
                            {{-- Score Card --}}
                            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-4 shadow-sm">
                                <div class="flex items-center justify-between mb-3">
                                    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Lead Score</p>
                                    <div class="flex items-center gap-2">
                                        @if($lead->temperature)
                                            <span @class([
                                                'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium',
                                                'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'    => $lead->temperature->value === 'hot',
                                                'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400' => $lead->temperature->value === 'warm',
                                                'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-400'    => $lead->temperature->value === 'cold',
                                            ])>
                                                {{ $lead->temperature->label() }}
                                            </span>
                                        @endif
                                        <span class="text-2xl font-bold {{ ($lead->lead_score ?? 0) >= 70 ? 'text-green-600' : (($lead->lead_score ?? 0) >= 40 ? 'text-yellow-500' : 'text-red-500') }}">
                                            {{ $lead->lead_score ?? 0 }}<span class="text-sm font-normal text-gray-400 dark:text-gray-500">/100</span>
                                        </span>
                                    </div>
                                </div>
                                <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-2">
                                    <div
                                        class="h-2 rounded-full transition-all duration-500 {{ ($lead->lead_score ?? 0) >= 70 ? 'bg-green-500' : (($lead->lead_score ?? 0) >= 40 ? 'bg-yellow-400' : 'bg-red-400') }}"
                                        style="width: {{ min(100, $lead->lead_score ?? 0) }}%"
                                    ></div>
                                </div>
                            </div>

                            {{-- Detail Fields --}}
                            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm overflow-hidden">
                                @php
                                    $fields = [
                                        ['heroicon-o-user',       'Nama Customer', $lead->customer_name],
                                        ['heroicon-o-calendar-days', 'Tanggal Event', $lead->event_date?->format('d M Y')],
                                        ['heroicon-o-sparkles',   'Tipe Event',    $lead->event_type],
                                        ['heroicon-o-map-pin',    'Lokasi',         $lead->location],
                                        ['heroicon-o-user-group', 'Jumlah Tamu',   $lead->guest_count ? number_format($lead->guest_count).' orang' : null],
                                        ['heroicon-o-banknotes',  'Budget',         ($lead->budget_min || $lead->budget_max) ? 'Rp '.number_format($lead->budget_min ?? 0).' – Rp '.number_format($lead->budget_max ?? 0) : null],
                                        ['heroicon-o-gift',       'Paket Minat',   $lead->package_interest],
                                    ];
                                    $visibleFields = array_filter($fields, fn($f) => !empty($f[2]));
                                @endphp
                                @foreach(array_values($visibleFields) as $i => [$iconName, $labelText, $value])
                                    <div class="flex items-center gap-3 px-4 py-3 {{ $i > 0 ? 'border-t border-gray-50 dark:border-gray-700/50' : '' }}">
                                        <x-dynamic-component :component="$iconName" class="w-4 h-4 text-gray-300 dark:text-gray-600 shrink-0"/>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-[10px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wide">{{ $labelText }}</p>
                                            <p class="text-sm text-gray-800 dark:text-gray-200 font-medium mt-0.5 truncate">{{ $value }}</p>
                                        </div>
                                    </div>
                                @endforeach
                                @if(empty($visibleFields))
                                    <div class="px-4 py-6 text-center">
                                        <p class="text-xs text-gray-400 dark:text-gray-500">Belum ada data detail lead.</p>
                                    </div>
                                @endif
                            </div>
                        @else
                            <div class="flex flex-col items-center justify-center h-44 text-center">
                                <x-heroicon-o-user-circle class="w-12 h-12 text-gray-200 dark:text-gray-700 mb-3"/>
                                <p class="text-sm font-medium text-gray-400 dark:text-gray-500">Belum ada data lead</p>
                                <p class="text-xs text-gray-300 dark:text-gray-600 mt-1">Data akan muncul setelah AI memproses percakapan</p>
                            </div>
                        @endif
                    </div>

                {{-- ── TAB: AI Trace ─────────────────────────────────────────── --}}
                @elseif($activeTab === 'trace')
                    <div class="flex-1 overflow-y-auto p-4 space-y-3 bg-gray-50 dark:bg-gray-900/30">
                        @forelse($this->getRecentTraces() as $i => $trace)
                            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm overflow-hidden">
                                <div class="flex items-center justify-between px-4 py-2.5 bg-gray-50 dark:bg-gray-700/40 border-b border-gray-100 dark:border-gray-700">
                                    <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">Trace #{{ $i + 1 }}</span>
                                    <div class="flex items-center gap-2">
                                        @if($trace->injection_detected)
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400">
                                                <x-heroicon-o-shield-exclamation class="w-3 h-3"/>
                                                Injection
                                            </span>
                                        @endif
                                        <span class="text-[10px] text-gray-400 dark:text-gray-500">{{ $trace->created_at->diffForHumans() }}</span>
                                    </div>
                                </div>
                                <div class="divide-y divide-gray-50 dark:divide-gray-700/50">
                                    @if($trace->intent)
                                        <div class="flex items-center gap-3 px-4 py-2.5">
                                            <span class="text-[10px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wide w-16 shrink-0">Intent</span>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-400">
                                                {{ $trace->intent }}
                                            </span>
                                        </div>
                                    @endif
                                    @if($trace->decision)
                                        <div class="flex items-center gap-3 px-4 py-2.5">
                                            <span class="text-[10px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wide w-16 shrink-0">Decision</span>
                                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ $trace->decision }}</span>
                                        </div>
                                    @endif
                                    @if($trace->final_reply)
                                        <div class="flex items-start gap-3 px-4 py-2.5">
                                            <span class="text-[10px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wide w-16 shrink-0 mt-0.5">Reply</span>
                                            <p class="text-xs text-gray-600 dark:text-gray-400 leading-relaxed">{{ Str::limit($trace->final_reply, 160) }}</p>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="flex flex-col items-center justify-center h-44 text-center">
                                <x-heroicon-o-beaker class="w-12 h-12 text-gray-200 dark:text-gray-700 mb-3"/>
                                <p class="text-sm font-medium text-gray-400 dark:text-gray-500">Belum ada AI trace</p>
                                <p class="text-xs text-gray-300 dark:text-gray-600 mt-1">Trace muncul setelah AI memproses pesan</p>
                            </div>
                        @endforelse
                    </div>
                @endif

            @else
                {{-- ── Empty State ───────────────────────────────────────────── --}}
                <div class="flex-1 flex items-center justify-center bg-gray-50 dark:bg-gray-900/20">
                    <div class="text-center max-w-xs">
                        <div class="w-16 h-16 rounded-2xl bg-gray-100 dark:bg-gray-700 flex items-center justify-center mx-auto mb-4">
                            <x-heroicon-o-chat-bubble-left-right class="w-8 h-8 text-gray-400 dark:text-gray-500"/>
                        </div>
                        <p class="text-sm font-semibold text-gray-600 dark:text-gray-300">Pilih percakapan</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1.5 leading-relaxed">
                            Klik percakapan dari daftar di sebelah kiri untuk melihat chat dan info lead.
                        </p>
                    </div>
                </div>
            @endif

        </div>
    </div>
</x-filament-panels::page>
