<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UnifiedNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, string>  $fields
     */
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $heading,
        public readonly string $intro,
        public readonly array $fields = [],
        public readonly ?string $actionLabel = null,
        public readonly ?string $actionUrl = null,
        public readonly ?string $statusBadge = null,
        public readonly string $statusTone = 'info',
        public readonly ?string $preheader = null,
        public readonly ?string $footerNote = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification',
            text: 'emails.notification_text',
        );
    }
}
