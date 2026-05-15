<x-mail::message>
# Handoff Required — Tindakan Diperlukan

Halo **{{ $adminName }}**,

Ada percakapan yang memerlukan penanganan manual segera.

<x-mail::panel>
**Prioritas:** {{ $priority }}

**Nomor Customer:** {{ $customerPhone }}

**Stage Saat Ini:** {{ $stage }}

**Alasan Handoff:** {{ $reason }}
</x-mail::panel>

Silakan login ke panel admin untuk menangani percakapan ini.

<x-mail::button :url="config('app.url') . '/tenant'">
Buka Panel Admin
</x-mail::button>

Terima kasih,
{{ config('app.name') }}
</x-mail::message>
