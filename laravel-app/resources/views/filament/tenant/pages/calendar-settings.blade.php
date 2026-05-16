<x-filament-panels::page>
    @php
        $status = $this->getConnectionStatus();

        $wrapClass  = match($status['color']) {
            'success' => 'bg-green-50 border-green-200 dark:bg-green-900/20 dark:border-green-800',
            'warning' => 'bg-yellow-50 border-yellow-200 dark:bg-yellow-900/20 dark:border-yellow-800',
            default   => 'bg-gray-50 border-gray-200 dark:bg-gray-800 dark:border-gray-700',
        };
        $iconClass  = match($status['color']) {
            'success' => 'w-6 h-6 text-green-600',
            'warning' => 'w-6 h-6 text-yellow-600',
            default   => 'w-6 h-6 text-gray-400',
        };
        $labelClass = match($status['color']) {
            'success' => 'text-xs font-semibold uppercase tracking-wide text-green-700 dark:text-green-400',
            'warning' => 'text-xs font-semibold uppercase tracking-wide text-yellow-700 dark:text-yellow-400',
            default   => 'text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400',
        };
        $textClass  = match($status['color']) {
            'success' => 'text-sm font-medium text-green-800 dark:text-green-300',
            'warning' => 'text-sm font-medium text-yellow-800 dark:text-yellow-300',
            default   => 'text-sm font-medium text-gray-600 dark:text-gray-300',
        };
    @endphp

    <div class="mb-6">
        <div class="flex items-center gap-3 px-4 py-3 rounded-xl border {{ $wrapClass }}">
            <x-heroicon-o-calendar-days :class="$iconClass" />
            <div>
                <p class="{{ $labelClass }}">Status Google Calendar</p>
                <p class="{{ $textClass }}">{{ $status['label'] }}</p>
                @if(!empty($status['expires_at']))
                    <p class="text-xs text-gray-400 mt-0.5">Token berlaku hingga: {{ $status['expires_at'] }}</p>
                @endif
            </div>
        </div>
    </div>

    {{ $this->form }}
</x-filament-panels::page>
