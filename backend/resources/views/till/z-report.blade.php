@php
    use Illuminate\Support\Carbon;
    /** @var array $header */
    /** @var array $period */
    /** @var array $operator */
    /** @var array $opening_float */
    /** @var array $sales_summary */
    /** @var array $tax_breakdown */
    /** @var array $cash_summary */
    /** @var array $cash_events */
    /** @var array $tender_sections */
    /** @var array $closing_count */
    /** @var ?array $manager_intervention */
    $currency = $session->default_currency_code ?? 'JPY';
    $money = static function ($amount) use ($currency): string {
        $amount = (float) ($amount ?? 0);
        $symbol = $currency === 'JPY' ? '¥' : '$';
        $formatted = $currency === 'JPY' ? number_format($amount, 0) : number_format($amount, 2);
        return ($amount < 0 ? '-' : '').$symbol.ltrim($formatted, '-');
    };
    $dt = static function ($value): string {
        if ($value === null) { return ''; }
        return ($value instanceof Carbon ? $value : Carbon::parse($value))
            ->copy()->setTimezone('Asia/Tokyo')
            ->format('Y-m-d H:i:s');
    };
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Z-Report {{ $z_number }}</title>
    <style>
        @page { margin: 18mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #111; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 14px 0 4px; padding-bottom: 2px; border-bottom: 1px solid #555; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 3px 4px; vertical-align: top; }
        .meta-row td { padding: 1px 4px; }
        .grid th { text-align: left; border-bottom: 1px solid #888; }
        .grid td { border-bottom: 1px dotted #bbb; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .total td { font-weight: bold; border-top: 1px solid #555; padding-top: 4px; }
        .variance-row td { font-weight: bold; font-size: 12px; padding-top: 6px; }
        .negative { color: #b30000; }
        .footer { margin-top: 20px; padding-top: 6px; border-top: 1px solid #555; font-size: 9px; color: #555; }
        .intervention { background: #fdf2e9; border: 1px solid #d35400; padding: 6px 8px; margin: 10px 0; }
    </style>
</head>
<body>

{{-- 1. Header --}}
<h1>レジ締めレポート / Z-Report</h1>
<table class="meta-row">
    <tr><td>店舗 / Branch:</td><td>{{ $header['brand_name'] }} — {{ $header['branch_name'] }}</td></tr>
    <tr><td>レジ / Till:</td><td>{{ $header['till_name'] }} ({{ $header['register_number'] }})</td></tr>
    <tr><td>Z番号 / Z-Number:</td><td><strong>{{ $z_number }}</strong></td></tr>
    <tr><td>発行日時 / Generated:</td><td>{{ $dt($generated_at) }} JST</td></tr>
    <tr><td>ステータス / Status:</td><td>{{ strtoupper($status) }}</td></tr>
</table>

{{-- 2. Period + 3. Operator --}}
<h2>期間 / Session Period</h2>
<table class="meta-row">
    <tr><td>開始 / Opened:</td><td>{{ $dt($period['opened_at']) }} — {{ $operator['opener_name'] }}</td></tr>
    <tr><td>終了 / Closed:</td><td>{{ $dt($period['closed_at']) }} — {{ $operator['closer_name'] }}</td></tr>
</table>

{{-- 4. Opening float --}}
<h2>釣銭準備金 / Opening Float</h2>
<table class="grid">
    <thead><tr><th>金種</th><th class="num">枚数</th><th class="num">小計</th></tr></thead>
    <tbody>
    @forelse ($opening_float['denominations'] as $row)
        <tr>
            <td>{{ $row['label'] ?? $money($row['value']) }}</td>
            <td class="num">{{ $row['quantity'] }}</td>
            <td class="num">{{ $money($row['subtotal']) }}</td>
        </tr>
    @empty
        <tr><td colspan="3">(no opening counts recorded)</td></tr>
    @endforelse
    <tr class="total"><td>合計 / Float total</td><td></td><td class="num">{{ $money($opening_float['amount']) }}</td></tr>
    </tbody>
</table>

{{-- 5. Sales summary --}}
<h2>売上 / Sales Summary</h2>
<table class="grid">
    <tr><td>総売上 / Gross</td><td class="num">{{ $money($sales_summary['gross']) }}</td></tr>
    <tr><td>値引 / Discount</td><td class="num">{{ $money($sales_summary['discount']) }}</td></tr>
    <tr><td>消費税 / Tax</td><td class="num">{{ $money($sales_summary['tax']) }}</td></tr>
    <tr class="total"><td>純売上 / Net</td><td class="num">{{ $money($sales_summary['net']) }}</td></tr>
</table>

{{-- 6. Consumption-tax breakdown per rate (plan-043 §7.6, インボイス).
     ALWAYS rendered — the Z-report is the audit document (Decision 8); the
     thermal report's close_report_tax_breakdown toggle does NOT gate it. --}}
@php
    $rate = static function ($r): string {
        $r = (float) $r;
        return rtrim(rtrim(number_format($r, 2, '.', ''), '0'), '.').'%';
    };
@endphp
<h2>消費税内訳 / Consumption Tax by Rate</h2>
<table class="grid">
    <thead>
        <tr>
            <th>税率 / Rate</th>
            <th class="num">課税売上 / Taxable</th>
            <th class="num">消費税 / Tax</th>
        </tr>
    </thead>
    <tbody>
    @forelse ($tax_breakdown['by_rate'] as $row)
        <tr>
            <td>{{ $rate($row['rate']) }}対象</td>
            <td class="num">{{ $money($row['taxable']) }}</td>
            <td class="num">{{ $money($row['tax']) }}</td>
        </tr>
    @empty
        <tr><td colspan="3">(no taxable sales recorded)</td></tr>
    @endforelse
        <tr class="total">
            <td>合計 / Total</td>
            <td class="num">{{ $money($tax_breakdown['net']) }}</td>
            <td class="num">{{ $money($tax_breakdown['tax']) }}</td>
        </tr>
    </tbody>
</table>

{{-- 7. Tender breakdown — workstation reconcileSession() order --}}
<h2>支払方法別 / By Tender</h2>
@foreach (['cash' => '現金 / Cash', 'card' => 'カード / Card', 'qr' => 'QR/送金 / QR & Transfer', 'emoney' => '電子マネー / E-money'] as $catKey => $catLabel)
    @if (! empty($tender_sections[$catKey]))
        <h3 style="font-size: 11px; margin: 8px 0 4px;">{{ $catLabel }}</h3>
        <table class="grid">
            <thead><tr><th>Tender</th><th class="num">Expected</th><th class="num">Declared</th><th class="num">Variance</th><th>Reason</th></tr></thead>
            <tbody>
            @foreach ($tender_sections[$catKey] as $row)
                @php $variance = (float) ($row['variance_amount'] ?? 0); @endphp
                <tr>
                    <td>{{ $row['tender_name'] }}</td>
                    <td class="num">{{ $money($row['expected_amount']) }}</td>
                    <td class="num">{{ $money($row['declared_amount']) }}</td>
                    <td class="num {{ $variance < 0 ? 'negative' : '' }}">{{ $money($variance) }}</td>
                    <td>{{ $row['variance_reason'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endforeach

{{-- 8. Cash event log --}}
@if (count($cash_events) > 0)
<h2>入出金記録 / Cash Event Log</h2>
<table class="grid">
    <thead><tr><th>時刻</th><th>Type</th><th class="num">Amount</th><th>Reason</th><th>Actor</th></tr></thead>
    <tbody>
    @foreach ($cash_events as $event)
        <tr>
            <td>{{ $dt($event['occurred_at']) }}</td>
            <td>{{ $event['event_type'] }}</td>
            <td class="num">{{ $money($event['amount']) }}</td>
            <td>{{ $event['reason'] }}</td>
            <td>{{ $event['actor_name'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif

{{-- 9. Expected cash --}}
<h2>引出金内訳 / Expected Cash</h2>
<table class="grid">
    <tr><td>釣銭準備金 / Opening float</td><td class="num">{{ $money($cash_summary['opening_float']) }}</td></tr>
    <tr><td>現金売上 / Cash sales</td><td class="num">{{ $money($cash_summary['cash_sales']) }}</td></tr>
    <tr><td>入金 / Paid in</td><td class="num">{{ $money($cash_summary['paid_in']) }}</td></tr>
    <tr><td>出金 / Paid out</td><td class="num">{{ $money($cash_summary['paid_out']) }}</td></tr>
    <tr class="total"><td>予定残高 / Expected</td><td class="num">{{ $money($cash_summary['expected']) }}</td></tr>
</table>

{{-- 10. Declared count --}}
<h2>実査金額 / Counted Cash</h2>
<table class="grid">
    <thead><tr><th>金種</th><th class="num">枚数</th><th class="num">小計</th></tr></thead>
    <tbody>
    @forelse ($closing_count['denominations'] as $row)
        <tr>
            <td>{{ $row['label'] ?? $money($row['value']) }}</td>
            <td class="num">{{ $row['quantity'] }}</td>
            <td class="num">{{ $money($row['subtotal']) }}</td>
        </tr>
    @empty
        <tr><td colspan="3">(no closing counts recorded — reconciliation not performed)</td></tr>
    @endforelse
    <tr class="total"><td>合計 / Counted</td><td></td><td class="num">{{ $money($closing_count['amount']) }}</td></tr>
    </tbody>
</table>

{{-- 11. Variance --}}
<table class="grid">
    @php $variance = (float) ($cash_summary['variance'] ?? 0); @endphp
    <tr class="variance-row">
        <td>過不足 / Cash Variance</td>
        <td class="num {{ $variance < 0 ? 'negative' : '' }}">{{ $money($variance) }}</td>
    </tr>
</table>

{{-- 12. Signature --}}
<h2>承認 / Manager Signature</h2>
<table class="meta-row">
    <tr><td style="width: 60%;">担当者 / Closer:</td><td>{{ $operator['closer_name'] ?? '___________________________' }}</td></tr>
    <tr><td>承認者印 / Manager seal:</td><td>____________________________________________</td></tr>
</table>

{{-- 13. Manager intervention block --}}
@if ($manager_intervention !== null)
<div class="intervention">
    <strong>{{ $manager_intervention['type'] === 'expired' ? 'システムによる失効 / System expiry' : 'マネージャー強制終了 / Manager force-abandon' }}</strong><br>
    Actor: {{ $manager_intervention['actor_name'] }}<br>
    Reason code: {{ $manager_intervention['reason_code'] }}<br>
    @if (! empty($manager_intervention['reason_detail']))
        Detail: {{ $manager_intervention['reason_detail'] }}<br>
    @endif
    @if ($manager_intervention['threshold_hours'])
        Threshold hours: {{ $manager_intervention['threshold_hours'] }}<br>
    @endif
    Recorded at: {{ $dt($manager_intervention['occurred_at']) }}
</div>
@endif

@if (! empty($closing_note))
<h2>備考 / Closing Note</h2>
<p>{{ $closing_note }}</p>
@endif

{{-- 14. Footer --}}
<div class="footer">
    Generated {{ $dt($generated_at) }} JST · Session {{ $session->session_code }} · {{ $z_number }}
</div>

</body>
</html>
