<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #000; margin: 0; padding: 20px; }
        h1 { font-size: 20px; margin: 0 0 4px 0; }
        h2 { font-size: 14px; margin: 0 0 12px 0; font-weight: normal; }
        .header { border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 16px; }
        .section { margin-bottom: 16px; }
        .section-title { font-size: 11px; font-weight: bold; text-transform: uppercase;
                         letter-spacing: 1px; border-bottom: 1px solid #ccc;
                         padding-bottom: 4px; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 4px 6px; vertical-align: top; }
        td.label { width: 40%; color: #555; }
        .amount-box { background: #f0f0f0; border: 1px solid #ccc; padding: 10px 14px;
                      font-size: 18px; font-weight: bold; text-align: right; margin-bottom: 16px; }
        .status-badge { display: inline-block; padding: 2px 8px; border: 1px solid #000;
                        font-size: 10px; font-weight: bold; text-transform: uppercase; }
        .footer { border-top: 1px solid #ccc; padding-top: 10px; margin-top: 20px;
                  font-size: 10px; color: #666; text-align: center; }
    </style>
</head>
<body>

<div class="header">
    <h1>{{ $tenantName }}</h1>
    <h2>Invoice Pembayaran</h2>
    <table>
        <tr>
            <td class="label">No. Invoice</td>
            <td><strong>{{ $invoice->invoice_number }}</strong></td>
            <td class="label">Tanggal</td>
            <td>{{ $invoice->created_at->format('d M Y') }}</td>
        </tr>
        <tr>
            <td class="label">Jatuh Tempo</td>
            <td>{{ $invoice->due_date ? \Carbon\Carbon::parse($invoice->due_date)->format('d M Y') : '-' }}</td>
            <td class="label">Status</td>
            <td><span class="status-badge">{{ strtoupper(is_string($invoice->status) ? $invoice->status : $invoice->status->value) }}</span></td>
        </tr>
    </table>
</div>

<div class="section">
    <div class="section-title">Data Customer</div>
    <table>
        <tr>
            <td class="label">Nama</td>
            <td>{{ $customerName }}</td>
        </tr>
        <tr>
            <td class="label">No. HP</td>
            <td>{{ $customerPhone }}</td>
        </tr>
    </table>
</div>

<div class="section">
    <div class="section-title">Detail Acara</div>
    <table>
        <tr>
            <td class="label">Tanggal Acara</td>
            <td>{{ $eventDate }}</td>
        </tr>
        <tr>
            <td class="label">Jenis Acara</td>
            <td>{{ ucfirst($eventType) }}</td>
        </tr>
        @if($location)
        <tr>
            <td class="label">Lokasi</td>
            <td>{{ $location }}</td>
        </tr>
        @endif
        @if($packageName)
        <tr>
            <td class="label">Paket</td>
            <td>{{ $packageName }}</td>
        </tr>
        @endif
    </table>
</div>

<div class="amount-box">
    Jenis: {{ strtoupper(is_string($invoiceType) ? $invoiceType : $invoiceType->value) }} &nbsp;|&nbsp;
    Jumlah: Rp {{ number_format($invoice->amount, 0, ',', '.') }}
</div>

@if($bankName || $bankAccount)
<div class="section">
    <div class="section-title">Informasi Pembayaran</div>
    <table>
        @if($bankName)
        <tr>
            <td class="label">Bank</td>
            <td>{{ $bankName }}</td>
        </tr>
        @endif
        @if($bankAccount)
        <tr>
            <td class="label">No. Rekening</td>
            <td>{{ $bankAccount }}</td>
        </tr>
        @endif
        @if($bankAccountName)
        <tr>
            <td class="label">Atas Nama</td>
            <td>{{ $bankAccountName }}</td>
        </tr>
        @endif
    </table>
</div>
@endif

@if($invoice->notes)
<div class="section">
    <div class="section-title">Catatan</div>
    <p>{{ $invoice->notes }}</p>
</div>
@endif

<div class="footer">
    Terima kasih atas kepercayaan Anda. Harap konfirmasi pembayaran ke tim kami setelah transfer.
</div>

</body>
</html>
