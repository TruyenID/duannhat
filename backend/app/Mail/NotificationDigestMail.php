<?php

namespace App\Mail;

use App\Services\Notification\DigestPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Plan-023 M5 T5.7 — periodic summary email assembled from a
 * DigestPayload. Renders the Markdown template at
 * `resources/views/emails/notification-digest.blade.php`.
 *
 * The Mailable carries the payload + nothing else. The subject
 * localises through Laravel translations; the body is built by the
 * template itself referencing $payload directly.
 */
class NotificationDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly DigestPayload $payload) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                'Notification digest — %s',
                $this->payload->windowEnd->isoFormat('YYYY-MM-DD'),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.notification-digest',
            with: ['payload' => $this->payload],
        );
    }
}
