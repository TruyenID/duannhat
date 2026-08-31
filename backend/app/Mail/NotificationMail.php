<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

/**
 * Mailable wrapping a rendered notification (plan-012 T3.6). Subject
 * comes from the rendered title; body is the rendered body. We use
 * Laravel's blade-less text body by default (simple + safe); HTML
 * upgrade is a follow-up.
 *
 * Issue #173 part A — emits RFC 8058 / RFC 2369 unsubscribe headers so
 * Gmail / Yahoo / Apple Mail can show an inbox-level unsubscribe button
 * and post one-click POST unsubscribes back to our public endpoint. Token
 * is a Laravel signed URL (HMAC over the URL with the app key, no DB
 * token table).
 */
class NotificationMail extends Mailable
{
    /**
     * @param  string|null  $unsubscribeUserId  required for the unsubscribe
     *                                          header; pass null only for system mail with no notification
     *                                          preference (e.g. transactional auth flows where opting out is not
     *                                          a meaningful choice).
     * @param  string|null  $unsubscribeType  notification type / template
     *                                        key — controls which preference row gets flipped on unsubscribe.
     */
    public function __construct(
        public readonly string $renderedTitle,
        public readonly string $renderedBody,
        public readonly ?string $unsubscribeUserId = null,
        public readonly ?string $unsubscribeType = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->renderedTitle);
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.notification-text',
            with: [
                'body' => $this->renderedBody,
            ],
        );
    }

    /**
     * RFC 8058 one-click unsubscribe headers. Skipped when we don't have
     * a user/type pair to anchor the preference flip — caller must pass
     * both.
     */
    public function headers(): Headers
    {
        if ($this->unsubscribeUserId === null || $this->unsubscribeType === null) {
            return new Headers;
        }

        $url = URL::signedRoute(
            'api.v1.notifications.email.unsubscribe',
            ['user' => $this->unsubscribeUserId, 'type' => $this->unsubscribeType],
            now()->addDays(60),
        );

        return new Headers(
            text: [
                'List-Unsubscribe' => '<'.$url.'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        );
    }
}
