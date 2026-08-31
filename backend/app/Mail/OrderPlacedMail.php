<?php

namespace App\Mail;

use App\Models\CustomerOrder;
use App\Services\Order\OrderQrService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;

/**
 * plan-035 — sent right after a takeaway order is created (status `open`
 * OR `pending`) and the customer provided an email. Contains a QR code
 * the customer shows at the counter to look up the order.
 *
 * Queued so the order POST returns immediately even when the SMTP/API
 * call is slow.
 */
class OrderPlacedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public CustomerOrder $order) {}

    public function envelope(): Envelope
    {
        App::setLocale($this->resolveLocale());

        $statusValue = $this->order->status instanceof \BackedEnum
            ? $this->order->status->value
            : $this->order->status;
        $key = $statusValue === 'pending'
            ? 'emails.order_placed.subject_pending'
            : 'emails.order_placed.subject';

        return new Envelope(
            subject: __($key, [
                'code' => $this->order->order_code,
            ]),
        );
    }

    public function content(): Content
    {
        App::setLocale($this->resolveLocale());

        return new Content(
            markdown: 'emails.order_placed',
            with: [
                'order' => $this->order,
                'qrDataUri' => app(OrderQrService::class)->generateDataUri($this->order),
            ],
        );
    }

    /**
     * Issue #365 — resolve in priority order so the mail honours the
     * customer's website language even when this Mailable is built
     * from a webhook handler that has no Accept-Language header.
     */
    private function resolveLocale(): string
    {
        $candidate = $this->order->customer_locale
            ?? $this->order->branch?->locale
            ?? 'vi';

        $base = explode('-', (string) $candidate)[0] ?: 'vi';

        return in_array($base, ['vi', 'en', 'ja'], true) ? $base : 'vi';
    }
}
