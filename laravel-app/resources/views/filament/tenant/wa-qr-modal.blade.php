<div
    id="wa-qr-modal-{{ $account->id }}"
    class="flex flex-col items-center gap-4 py-4"
    x-data="{
        status: '{{ $account->status->value }}',
        qrCode: {{ $account->qr_code ? '`' . $account->qr_code . '`' : 'null' }},
        isExpired: {{ $account->isQrExpired() ? 'true' : 'false' }},
        phone: null,
        polling: null,
        startPolling() {
            this.polling = setInterval(() => this.checkStatus(), 3000);
        },
        stopPolling() {
            if (this.polling) {
                clearInterval(this.polling);
                this.polling = null;
            }
        },
        async checkStatus() {
            try {
                const res = await fetch('{{ route('tenant.wa-accounts.qr-status', $account->id) }}', {
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '' }
                });
                if (!res.ok) return;
                const data = await res.json();
                this.status   = data.status;
                this.qrCode   = data.qr_code;
                this.isExpired = data.is_expired;
                this.phone    = data.phone;
                if (data.status === 'connected') {
                    this.stopPolling();
                }
            } catch (e) {
                console.warn('QR status poll error', e);
            }
        }
    }"
    x-init="startPolling()"
    x-on:close-modal.window="stopPolling()"
>
    {{-- Connected --}}
    <template x-if="status === 'connected'">
        <div class="flex flex-col items-center gap-3 text-center">
            <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center">
                <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <p class="text-lg font-semibold text-green-700">WA Berhasil Terhubung!</p>
            <p class="text-sm text-gray-500" x-text="phone ? 'Nomor: ' + phone : ''"></p>
            <p class="text-xs text-gray-400">Halaman akan diperbarui otomatis.</p>
        </div>
    </template>

    {{-- QR Expired --}}
    <template x-if="status !== 'connected' && isExpired">
        <div class="flex flex-col items-center gap-3 text-center">
            <p class="text-sm font-medium text-orange-600">QR Code sudah kedaluwarsa.</p>
            <p class="text-xs text-gray-500">Klik "Connect" lagi untuk generate QR baru.</p>
        </div>
    </template>

    {{-- QR Pending / Connecting --}}
    <template x-if="status !== 'connected' && !isExpired">
        <div class="flex flex-col items-center gap-4 text-center">
            <template x-if="qrCode">
                <div class="border-4 border-gray-200 rounded-xl p-2">
                    <img :src="qrCode.startsWith('data:') ? qrCode : 'data:image/png;base64,' + qrCode" alt="QR Code" class="w-56 h-56 object-contain" />
                </div>
            </template>
            <template x-if="!qrCode">
                <div class="flex flex-col items-center gap-2">
                    <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-blue-600"></div>
                    <p class="text-sm text-gray-500">Memuat QR Code...</p>
                </div>
            </template>
            <p class="text-sm text-gray-600">Buka WhatsApp → Perangkat Tertaut → Tautkan Perangkat</p>
            <p class="text-xs text-gray-400">QR Code diperbarui otomatis setiap 3 detik</p>
        </div>
    </template>
</div>
