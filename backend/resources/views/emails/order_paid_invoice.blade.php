@php
    $branchName = $order->branch?->name ?? '';
    $currency = $order->branch?->shopOrderSetting?->currency_code ?? 'JPY';
    $formatPrice = fn ($n) => number_format((float) $n, $currency === 'JPY' ? 0 : 2);
    // plan-043 T4.5 — per-rate consumption-tax blocks (8%対象 / 10%対象).
    $taxGroups = $taxGroups ?? [];
    $pricesIncludeTax = $pricesIncludeTax ?? false;
    $rateLabel = fn ($r) => rtrim(rtrim(number_format((float) $r, 2, '.', ''), '0'), '.');
@endphp

# {{ __('emails.order_paid_invoice.greeting', ['name' => $order->customer_takeaway_name ?: ($order->customer?->first_name ?? '')]) }}

{{ __('emails.order_paid_invoice.intro', ['code' => $order->order_code, 'branch' => $branchName]) }}

## {{ $order->order_code }}

@foreach ($order->items as $item)
- **{{ $item->productSku?->product?->name ?? $item->product_name ?? '—' }}** × {{ $item->quantity }} — {{ $formatPrice($item->subtotal) }}
@endforeach

---

| | |
|---|---:|
| {{ __('emails.order_paid_invoice.subtotal') }} | {{ $formatPrice($order->subtotal) }} |
@forelse ($taxGroups as $group)
| {{ __('emails.order_paid_invoice.tax_at_rate', ['rate' => $rateLabel($group['rate'])]) }}@if ($pricesIncludeTax) {{ __('emails.order_paid_invoice.tax_included_note') }}@endif | {{ $formatPrice($group['tax']) }} |
@empty
| {{ __('emails.order_paid_invoice.tax') }} | {{ $formatPrice($order->tax_amount) }} |
@endforelse
| {{ __('emails.order_paid_invoice.service') }} | {{ $formatPrice($order->service_charge) }} |
| **{{ __('emails.order_paid_invoice.total') }}** | **{{ $formatPrice($order->total_amount) }}** |

@isset($order->checkout_at)
**{{ __('emails.order_paid_invoice.paid_at') }}:** {{ $order->checkout_at?->format('Y-m-d H:i') }}
@endisset

> {{ __('emails.order_paid_invoice.pdf_attached') }}

{{ __('emails.order_paid_invoice.outro') }}
