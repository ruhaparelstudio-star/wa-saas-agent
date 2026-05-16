<x-filament-panels::page>
    {{-- Period Selector --}}
    <div class="flex items-center gap-2 mb-6">
        @foreach([
            'this_month'   => 'Bulan Ini',
            'last_30_days' => '30 Hari Terakhir',
            'last_7_days'  => '7 Hari Terakhir',
        ] as $key => $label)
            <button
                wire:click="setPeriod('{{ $key }}')"
                class="px-4 py-1.5 text-sm font-medium rounded-lg transition-colors
                    {{ $activePeriod === $key
                        ? 'bg-primary-600 text-white'
                        : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 hover:bg-gray-50' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Tenant Analytics Table --}}
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide">Tenant</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide">Active Leads</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide">Bookings</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide">Revenue (IDR)</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 dark:text-gray-300 uppercase tracking-wide">Conversion %</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($this->getRows() as $row)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{{ $row['tenant_name'] }}</td>
                        <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ number_format($row['active_leads']) }}</td>
                        <td class="px-4 py-3 text-right text-gray-700 dark:text-gray-300">{{ number_format($row['booking_count']) }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-yellow-600 dark:text-yellow-400">Rp {{ number_format($row['revenue'], 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right text-green-600 dark:text-green-400">{{ number_format($row['conversion_rate'], 1) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-gray-400">Belum ada tenant aktif.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
