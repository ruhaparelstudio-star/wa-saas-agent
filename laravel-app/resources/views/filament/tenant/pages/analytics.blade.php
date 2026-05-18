<x-filament-panels::page>
    @php
        $summary = $this->getSummary();
    @endphp

    {{-- Period Selector --}}
    <div class="flex items-center gap-2 mb-6">
        @foreach([
            'this_month'  => 'Bulan Ini',
            'last_30_days' => '30 Hari Terakhir',
            'last_7_days'  => '7 Hari Terakhir',
        ] as $key => $label)
            <button
                wire:click="setPeriod('{{ $key }}')"
                class="px-4 py-1.5 text-sm font-medium rounded-lg transition-colors
                    {{ $activePeriod === $key
                        ? 'bg-primary-600 text-white'
                        : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Stat Cards --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-6">
        @php
            $conv = $summary->conversion;
            $rev  = $summary->revenue;
        @endphp

        {{-- Total Leads --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Total Lead</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-1">{{ number_format($conv->total_leads) }}</p>
        </div>

        {{-- Converted --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Konversi ke Booking</p>
            <p class="text-2xl font-bold text-green-600 dark:text-green-400 mt-1">{{ number_format($conv->leads_to_booking) }}</p>
            <p class="text-xs text-gray-400 mt-0.5">{{ number_format($conv->conversion_rate, 1) }}% conversion rate</p>
        </div>

        {{-- Revenue --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Revenue</p>
            <p class="text-2xl font-bold text-yellow-600 dark:text-yellow-400 mt-1 truncate">
                Rp {{ number_format($rev->total_revenue, 0, ',', '.') }}
            </p>
            <p class="text-xs text-gray-400 mt-0.5">{{ $rev->paid_count }}/{{ $rev->invoice_count }} invoice lunas</p>
        </div>

        {{-- Avg days to booking --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Rata-rata ke Booking</p>
            <p class="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-1">
                {{ $conv->avg_days_to_booking > 0 ? $conv->avg_days_to_booking . ' hari' : '-' }}
            </p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

        {{-- Lead Funnel --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Funnel Lead</h3>

            @if(empty($summary->lead_funnel))
                <p class="text-sm text-gray-400 dark:text-gray-500 text-center py-6">Belum ada data funnel.</p>
            @else
                <div class="space-y-2">
                    @foreach($summary->lead_funnel as $funnel)
                        <div>
                            <div class="flex items-center justify-between mb-0.5">
                                <span class="text-xs text-gray-600 dark:text-gray-400 capitalize">{{ str_replace('_', ' ', $funnel->stage) }}</span>
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ $funnel->count }} ({{ $funnel->percentage }}%)</span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                                <div class="bg-primary-500 h-1.5 rounded-full" style="width: {{ min(100, $funnel->percentage) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Top Packages --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Paket Terpopuler</h3>

            @php $topPkgs = $summary->top_packages ?? []; @endphp
            @if(empty($topPkgs))
                <p class="text-sm text-gray-400 dark:text-gray-500 text-center py-6">Belum ada data paket.</p>
            @else
                <div class="space-y-2">
                    @foreach($topPkgs as $i => $pkg)
                        @php
                            // AnalyticsService::getTopPackages() returns an array of
                            // assoc arrays — support either shape defensively.
                            $pkgName  = is_array($pkg) ? ($pkg['package_name']  ?? '-') : ($pkg->package_name  ?? '-');
                            $pkgCount = is_array($pkg) ? ($pkg['booking_count'] ?? 0)   : ($pkg->booking_count ?? 0);
                        @endphp
                        <div class="flex items-center justify-between py-1.5 border-b border-gray-100 dark:border-gray-700 last:border-0">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-bold text-gray-400 w-4">{{ $i + 1 }}</span>
                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ $pkgName }}</span>
                            </div>
                            <span class="text-xs font-medium text-primary-600 dark:text-primary-400">{{ $pkgCount }} booking</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Revenue breakdown --}}
        @if($summary->is_advanced)
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 shadow-sm">
            <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-3">Breakdown Revenue</h3>
            <dl class="space-y-2">
                <div class="flex justify-between py-1.5 border-b border-gray-100 dark:border-gray-700">
                    <dt class="text-xs text-gray-500 dark:text-gray-400">Total Revenue</dt>
                    <dd class="text-sm font-semibold text-gray-800 dark:text-gray-100">Rp {{ number_format($rev->total_revenue, 0, ',', '.') }}</dd>
                </div>
                <div class="flex justify-between py-1.5 border-b border-gray-100 dark:border-gray-700">
                    <dt class="text-xs text-gray-500 dark:text-gray-400">DP</dt>
                    <dd class="text-sm font-medium text-gray-700 dark:text-gray-200">Rp {{ number_format($rev->dp_revenue, 0, ',', '.') }}</dd>
                </div>
                <div class="flex justify-between py-1.5">
                    <dt class="text-xs text-gray-500 dark:text-gray-400">Pelunasan</dt>
                    <dd class="text-sm font-medium text-gray-700 dark:text-gray-200">Rp {{ number_format($rev->pelunasan_revenue, 0, ',', '.') }}</dd>
                </div>
            </dl>
        </div>
        @endif

        {{-- Plan notice if basic --}}
        @if(!$summary->is_advanced)
        <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-xl border border-yellow-200 dark:border-yellow-700 p-4">
            <div class="flex items-start gap-3">
                <x-heroicon-o-lock-closed class="w-5 h-5 text-yellow-600 dark:text-yellow-400 shrink-0 mt-0.5"/>
                <div>
                    <p class="text-sm font-semibold text-yellow-800 dark:text-yellow-200">Analytics Lanjutan Terkunci</p>
                    <p class="text-xs text-yellow-700 dark:text-yellow-300 mt-1">
                        Upgrade ke plan Pro untuk akses funnel detail, response time, dan breakdown revenue lengkap.
                    </p>
                </div>
            </div>
        </div>
        @endif

    </div>
</x-filament-panels::page>
