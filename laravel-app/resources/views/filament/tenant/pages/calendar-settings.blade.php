<x-filament-panels::page>
    @php
        $status = $this->getConnectionStatus();
    @endphp

    {{-- Connection status indicator --}}
    <div class="mb-6">
        <div class="flex items-center gap-3 px-4 py-3 rounded-xl border
            @if($status['color'] === 'success') bg-green-50 border-green-200 dark:bg-green-900/20 dark:border-green-800
            @elseif($status['color'] === 'warning') bg-yellow-50 border-yellow-200 dark:bg-yellow-900/20 dark:border-yellow-800
            @else bg-gray-50 border-gray-200 dark:bg-gray-800 dark:border-gray-700
            @endif
        ">
            <x-heroicon-o-calendar-days class="w-6 h-6
                @if($status['color'] === 'success') text-green-600
                @elseif($status['color'] === 'warning') text-yellow-600
                @else text-gray-400
                @endif
            "/>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide
                    @if($status['color'] === 'success') text-green-700 dark:text-green-400
                    @elseif($status['color'] === 'warning') text-yellow-700 dark:text-yellow-400
                    @else text-gray-500 dark:text-gray-400
                    @endif
                ">Status Google Calendar</p>
                <p class="text-sm font-medium
                    @if($status['color'] === 'success') text-green-800 dark:text-green-300
                    @elseif($status['color'] === 'warning') text-yellow-800 dark:text-yellow-300
                    @else text-gray-600 dark:text-gray-300
                    @endif
                ">{{ $status['label'] }}</p>
                @if(!empty($status['expires_at']))
                    <p class="text-xs text-gray-400 mt-0.5">Token berlaku hingga: {{ $status['expires_at'] }}</p>
                @endif
            </div>
        </div>
    </div>

    {{ $this->form }}

    <x-filament-panels::actions :actions="$this->getHeaderActions()" />
</x-filament-panels::page>
