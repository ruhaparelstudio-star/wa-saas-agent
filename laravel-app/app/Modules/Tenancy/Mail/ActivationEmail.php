<?php

namespace App\Modules\Tenancy\Mail;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ActivationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $rawToken,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: $this->tenant->contact_email,
            subject: 'Aktivasi Akun Anda di Platform Kami',
        );
    }

    public function content(): Content
    {
        $activationLink = config('app.url') . '/activate/' . $this->rawToken;

        return new Content(
            htmlString: sprintf(
                '<p>Halo,</p>
                <p>Akun <strong>%s</strong> telah dibuat. Klik link di bawah untuk mengaktifkan akun Anda:</p>
                <p><a href="%s">%s</a></p>
                <p>Link ini berlaku selama 48 jam.</p>
                <p>Salam,<br>Tim Platform</p>',
                htmlspecialchars($this->tenant->name),
                htmlspecialchars($activationLink),
                htmlspecialchars($activationLink)
            ),
        );
    }
}
