@php
    $branchName = $order->branch?->name ?? '';
    $currency = $order->branch?->shopOrderSetting?->currency_code ?? 'JPY';
    $formatPrice = fn ($n) => number_format((float) $n, $currency === 'JPY' ? 0 : 2);
    // plan-043 T4.5 — per-rate consumption-tax blocks (8%対象 / 10%対象).
    $taxGroups = $taxGroups ?? [];
    $pricesIncludeTax = $pricesIncludeTax ?? false;
    $rateLabel = fn ($r) => rtrim(rtrim(number_format((float) $r, 2, '.', ''), '0'), '.');
@endphp
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $order->order_code }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111827; }
        h1 { font-size: 18px; margin: 0; }
        .meta { color: #6b7280; font-size: 11px; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 6px 4px; border-bottom: 1px solid #e5e7eb; }
        th { text-align: left; color: #6b7280; font-weight: normal; }
        td.amount { text-align: right; }
        .totals { margin-top: 12px; width: 100%; }
        .totals td { border: 0; padding: 3px 0; }
        .totals td.label { text-align: right; padding-right: 12px; color: #4b5563; }
        .totals td.value { text-align: right; width: 90px; font-variant-numeric: tabular-nums; }
        .grand { border-top: 2px solid #111827; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $branchName }}</h1>
    <div class="meta">
        Order #{{ $order->order_code }} · {{ $order->checkout_at?->format('Y-m-d H:i') }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th style="width:40px; text-align:right;">Qty</th>
                <th style="width:90px; text-align:right;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>{{ $item->productSku?->product?->name ?? $item->product_name ?? '—' }}</td>
                    <td class="amount">{{ $item->quantity }}</td>
                    <td class="amount">{{ $formatPrice($item->subtotal) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">Subtotal</td>
            <td class="value">{{ $formatPrice($order->subtotal) }}</td>
        </tr>
        @forelse ($taxGroups as $group)
            <tr>
                <td class="label">{{ __('emails.order_paid_invoice.tax_at_rate', ['rate' => $rateLabel($group['rate'])]) }}@if ($pricesIncludeTax) {{ __('emails.order_paid_invoice.tax_included_note') }}@endif</td>
                <td class="value">{{ $formatPrice($group['tax']) }}</td>
            </tr>
        @empty
            <tr>
                <td class="label">Tax</td>
                <td class="value">{{ $formatPrice($order->tax_amount) }}</td>
            </tr>
        @endforelse
        <tr>
            <td class="label">Service</td>
            <td class="value">{{ $formatPrice($order->service_charge) }}</td>
        </tr>
        <tr class="grand">
            <td class="label">TOTAL ({{ $currency }})</td>
            <td class="value">{{ $formatPrice($order->total_amount) }}</td>
        </tr>
    </table>
</body>
</html>
