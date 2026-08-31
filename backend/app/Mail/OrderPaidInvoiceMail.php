<?php

namespace App\Mail;

use App\Models\CustomerOrder;
use App\Models\ShopOrderSetting;
use App\Services\Customer\OrderPricingCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;

/**
 * plan-035 — sent after a takeaway / dine-in order is fully paid. Body
 * recaps the bill in the customer's locale; a generated DOMPDF copy is
 * attached so the customer has a durable receipt.
 *
 * Triggered from the OrderClosed event listener (SendOrderPaidInvoice).
 * Queued so listener handler returns instantly.
 */
class OrderPaidInvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /** Lazily-resolved branch order settings (SerializesModels ignores nulls). */
    private ?ShopOrderSetting $orderSetting = null;

    public function __construct(public CustomerOrder $order) {}

    public function envelope(): Envelope
    {
        App::setLocale($this->resolveLocale());

        return new Envelope(
            subject: __('emails.order_paid_invoice.subject', [
                'code' => $this->order->order_code,
                'total' => number_format((float) $this->order->total_amount),
            ]),
        );
    }

    public function content(): Content
    {
        App::setLocale($this->resolveLocale());

        return new Content(
            markdown: 'emails.order_paid_invoice',
            with: [
                'order' => $this->order,
                'taxGroups' => $this->taxGroups(),
                'pricesIncludeTax' => $this->pricesIncludeTax(),
            ],
        );
    }

    /**
     * plan-043 T4.5 — per-rate consumption-tax blocks (8%対象 / 10%対象) from the
     * frozen per-line snapshots. Reuses the single pricing engine rather than
     * recomputing tax by hand.
     *
     * @return array<int, array{rate: float, taxable: float, tax: float}>
     */
    private function taxGroups(): array
    {
        return app(OrderPricingCalculator::class)
            ->forOrder($this->order, $this->setting())
            ->groupsToArray();
    }

    /**
     * #2108 — the ORDER's frozen snapshot only, never the live branch flag.
     * The old `?? $this->setting()?->prices_include_tax` fallback meant an
     * admin flipping 総額表示 after the sale changed how this mail labelled a
     * historical order. The column is NOT NULL (default false) so the fallback
     * was also dead code on any persisted order.
     */
    private function pricesIncludeTax(): bool
    {
        return (bool) $this->order->is_tax_included;
    }

    private function setting(): ?ShopOrderSetting
    {
        return $this->orderSetting ??= ShopOrderSetting::query()
            ->where('branch_id', $this->order->branch_id)
            ->first();
    }

    /**
     * Issue #365 — prefer the locale stamped onto the order at create
     * time so the invoice mail (often triggered from a Stripe webhook
     * thread with no Accept-Language) renders in the customer's chosen
     * language. Falls back to branch.locale and then `vi`.
     */
    private function resolveLocale(): string
    {
        $candidate = $this->order->customer_locale
            ?? $this->order->branch?->locale
            ?? 'vi';

        $base = explode('-', (string) $candidate)[0] ?: 'vi';

        return in_array($base, ['vi', 'en', 'ja'], true) ? $base : 'vi';
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        App::setLocale($this->resolveLocale());

        $pdf = Pdf::loadView('emails.invoice_pdf', [
            'order' => $this->order,
            'taxGroups' => $this->taxGroups(),
            'pricesIncludeTax' => $this->pricesIncludeTax(),
        ])
            ->setPaper('a5')
            ->output();

        return [
            Attachment::fromData(fn () => $pdf, "invoice-{$this->order->order_code}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
