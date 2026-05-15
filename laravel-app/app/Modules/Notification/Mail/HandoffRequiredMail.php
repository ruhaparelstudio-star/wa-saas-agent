<?php

namespace App\Modules\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HandoffRequiredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $adminName,
        public readonly array $context,
    ) {}

    public function envelope(): Envelope
    {
        $priority = strtoupper($this->context['priority'] ?? 'MEDIUM');
        return new Envelope(
            subject: "[{$priority}] Handoff Required — Tindakan Diperlukan",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.handoff-required',
            with: [
                'adminName'     => $this->adminName,
                'customerPhone' => $this->context['customer_phone'] ?? '-',
                'stage'         => $this->context['stage'] ?? '-',
                'reason'        => $this->context['reason'] ?? '-',
                'priority'      => $this->context['priority'] ?? 'MEDIUM',
            ],
        );
    }
}
