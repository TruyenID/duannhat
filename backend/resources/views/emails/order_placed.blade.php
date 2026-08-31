@php
    $branchName = $order->branch?->name ?? '';
    $formatPrice = fn ($n) => number_format((float) $n, ($order->branch?->shopOrderSetting?->currency_code ?? null) === 'JPY' ? 0 : 2);
    $isPending = (string) ($order->status instanceof \BackedEnum ? $order->status->value : $order->status) === 'pending';
@endphp

# {{ __('emails.order_placed.greeting', ['name' => $order->customer_takeaway_name ?: '']) }}

{{ __($isPending ? 'emails.order_placed.intro_pending' : 'emails.order_placed.intro', ['code' => $order->order_code, 'branch' => $branchName]) }}

<table cellpadding="0" cellspacing="0" style="margin: 24px auto;">
    <tr>
        <td align="center">
            <img src="{{ $qrDataUri }}" alt="Order QR" width="240" height="240" style="display:block; border:1px solid #e5e7eb; padding:8px; background:#fff;" />
        </td>
    </tr>
    <tr>
        <td align="center" style="padding-top:12px; font-family:monospace; font-size:18px; letter-spacing:1px;">
            {{ $order->order_code }}
        </td>
    </tr>
</table>

## {{ __('emails.order_placed.items_title') }}

@foreach ($order->items as $item)
- **{{ $item->productSku?->product?->name ?? $item->product_name ?? '—' }}** × {{ $item->quantity }} — {{ $formatPrice($item->subtotal) }}
@endforeach

---

**{{ __('emails.order_placed.total') }}:** {{ $formatPrice($order->total_amount) }}

@if ($order->status === 'pending')
> {{ __('emails.order_placed.pending_notice') }}
@endif

{{ __($isPending ? 'emails.order_placed.outro_pending' : 'emails.order_placed.outro') }}

@isset($order->branch->phone)
{{ __('emails.order_placed.contact', ['phone' => $order->branch->phone]) }}
@endisset
