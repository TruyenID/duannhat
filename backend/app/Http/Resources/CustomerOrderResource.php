<?php

/**
 * CustomerOrder Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Omnify\Modules\CustomerOrder\Resources\CustomerOrderResourceBase;
use App\Services\Order\Internal\OrderHoldStamp;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * CustomerOrderResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class CustomerOrderResource extends CustomerOrderResourceBase
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = $this->schemaArray($request);

        // #2041 — these remain API properties, but are now projections of the
        // authoritative condition ledger rather than customer_orders columns.
        $base['discount_amount'] = number_format((float) $this->discount_amount, 2, '.', '');
        $base['service_charge'] = number_format((float) $this->service_charge, 2, '.', '');
        $base['tax_amount'] = number_format((float) $this->tax_amount, 2, '.', '');

        // #2063 — cờ ĐANG TREO, và nó CÓ MẶT CÓ ĐIỀU KIỆN.
        //
        // Chỉ các đường ĐỌC đóng dấu (qua `OrderHoldStamp::stamp()`); mọi
        // endpoint GHI trả order về mà KHÔNG kèm cờ. Vì thế khoá này vắng mặt
        // thay vì mang `false`.
        //
        // Người tiêu thụ PHẢI phân biệt `null` với `false`: gộp chúng thì sửa
        // một dòng món trên đơn treo là cờ tắt và hai nút in hiện lại. Đó là
        // bẫy số 2 của #2063, và nó chỉ tránh được nếu "chưa đóng dấu" trông
        // khác "không treo" ngay trên payload.
        if ($this->resource->getAttribute(OrderHoldStamp::ATTRIBUTE) !== null) {
            $base[OrderHoldStamp::ATTRIBUTE] = (bool) $this->resource->getAttribute(OrderHoldStamp::ATTRIBUTE);
        }

        // Remove deprecated fields
        unset($base['payment_method'], $base['paid_at']);

        // qr_token is an opaque kiosk-resolve handle — never expose it through
        // an order payload (the generated schemaArray includes it because it's
        // a real column; strip it here, mirroring the model's $hidden guard).
        unset($base['qr_token']);

        // Currency of the order, taken from its branch. Admin clients render
        // every money amount in THIS currency regardless of the UI language —
        // the locale only controls number formatting, never the currency
        // symbol (#431). `whenLoaded` + default keeps this lazy-load-safe for
        // any serialization path that didn't eager-load the branch.
        $base['currency'] = (string) $this->whenLoaded(
            'branch',
            fn () => $this->branch?->currency ?? 'JPY',
            'JPY',
        );

        // Add computed fields
        $base['remaining_amount'] = number_format(
            max(0, (float) $this->total_amount - (float) $this->paid_amount),
            2,
            '.',
            '',
        );

        // Locale the guest ordered in (stamped at create). The workstation
        // mirrors it so an auto-printed kitchen / hold slip renders in the
        // customer's language rather than the workstation's fallback.
        $base['customer_locale'] = $this->customer_locale;

        // Guest contact for takeaway orders placed without login
        $base['customer_takeaway_name'] = $this->customer_takeaway_name;
        $base['customer_takeaway_phone'] = $this->customer_takeaway_phone;

        // plan-031 — takeaway countdown fields
        $base['payment_due_at'] = $this->payment_due_at?->toISOString();
        // Signed Carbon diff FROM now TO the deadline: positive while the
        // window is still open (deadline in the future), negative once it has
        // elapsed. Clamp so an overdue order reports 0 rather than a negative
        // countdown. (#? — the previous `payment_due_at->diffInSeconds(now())`
        // had the operands reversed, so it returned 0 for every LIVE order and
        // a positive value for already-overdue ones — the inverse of intent.)
        $base['seconds_until_due'] = $this->payment_due_at
            ? max(0, now()->diffInSeconds($this->payment_due_at, false))
            : null;
        $base['is_payment_overdue'] = $this->payment_due_at && $this->payment_due_at->isPast();

        // plan-039 — share-bill propagation. Customer-web reads these two
        // fields to render the "Chia hóa đơn?" card on /order-confirm and
        // to disable re-edit once the kiosk has started paying. Mirrors
        // `KioskOrderResource`'s `split_mode` + `split_mode_locked` shape
        // so both surfaces see the same contract.
        $base['split_mode'] = $this->split_mode;
        $base['split_mode_locked'] = (float) $this->paid_amount > 0;
        // plan-039 follow-up — headcount pre-declared on customer-web so
        // a refreshed payment-view can restore the people-count UI from
        // BE state (e.g. user closed the tab and reopened the QR).
        $base['split_people_count'] = $this->split_people_count;

        // plan-039 — counter-pay detection for customer-web's conditional
        // card render. `payment_method` is request-only (not persisted as
        // a column), so derive from the absence of a Stripe intent: no
        // intent minted = customer hasn't chosen pay-online = counter-pay.
        $base['is_counter_pay'] = $this->stripe_payment_intent_id === null;

        // Add tables as nested array. `tables()` (reverse of Table.current_order_id)
        // only reflects who is CURRENTLY sitting at the table — once this order
        // closes and the table is released or reassigned, that relation goes
        // empty or points at a different order. For a non-open order, fall back
        // to the inherited `table()` relation — the `table_id` snapshot stamped
        // when the order was placed — so history views keep showing where the
        // order actually happened (#2531). Still requires `tables`/`table`
        // eager-loaded; unloaded stays an unresolved `whenLoaded` marker either way.
        $base['tables'] = $this->whenLoaded('tables', function () {
            $status = $this->status instanceof \BackedEnum ? $this->status->value : $this->status;

            if ($this->tables->isNotEmpty() || in_array($status, CustomerOrder::OPEN_STATUSES, true)) {
                return $this->tables->map(fn ($table) => [
                    'id' => $table->id,
                    'code' => $table->code,
                    'name' => $table->name,
                    'status' => $table->status,
                    'qr_token' => $table->qr_token,
                ])->values();
            }

            $snapshot = $this->table;

            return $snapshot === null ? collect() : collect([[
                'id' => $snapshot->id,
                'code' => $snapshot->code,
                'name' => $snapshot->name,
                'status' => $snapshot->status,
                'qr_token' => $snapshot->qr_token,
            ]]);
        });

        // plan-043 T1.16 — per-rate tax breakdown (8%対象 / 10%対象 blocks).
        // Derived from the loaded line snapshots (no extra query); only present
        // when items are eager-loaded. Additive — old consumers ignore it.
        // Per-line tax_amount is allocated once-per-group (largest remainder),
        // so summing it here reproduces the group tax — no per-line rounding.
        //
        // AUDIT FIX 1.3 (2026-07-14): `taxable` used to be the raw Σ line
        // subtotal — PRE-discount, and GROSS in included mode — while `tax`
        // is post-discount, so taxable × rate never matched tax on couponed
        // or 内税 orders, and the semantics disagreed with KioskOrderResource
        // (PricingResult-based, net). `taxable` is now the discounted net
        // base (net of the extracted tax in included mode) — the same 課税売上
        // definition the engine's TaxGroup carries. Additive companion field
        // `service_charge_tax` exposes the order-level sc tax residual
        // (which owns no line) so consumers can reconcile:
        // Σ breakdown.tax + service_charge_tax == order.tax_amount.
        if ($this->relationLoaded('items')) {
            $items = $this->items->filter(
                fn ($i) => (($i->status instanceof \BackedEnum ? $i->status->value : $i->status) !== 'voided'),
            );

            $subtotal = (float) $items->sum(
                fn ($i) => (float) $i->quantity * ((float) $i->unit_price + (float) ($i->topping_subtotal ?? 0)),
            );
            $discount = max(0.0, min((float) $this->discount_amount, $subtotal));
            $includeTax = (bool) $this->is_tax_included;

            $lineTaxTotal = 0.0;
            $base['tax_breakdown'] = $items
                ->groupBy(fn ($i) => (string) ((float) ($i->tax_rate ?? 0)))
                ->map(function ($group, $rate) use ($subtotal, $discount, $includeTax, &$lineTaxTotal) {
                    $gross = (float) $group->sum(
                        fn ($i) => (float) $i->quantity * ((float) $i->unit_price + (float) ($i->topping_subtotal ?? 0)),
                    );
                    $tax = (float) $group->sum('tax_amount');
                    $lineTaxTotal += $tax;
                    $net = max(0.0, $gross - ($subtotal > 0 ? $discount * $gross / $subtotal : 0.0));

                    return [
                        'rate' => (float) $rate,
                        'taxable' => $includeTax ? max(0.0, $net - $tax) : $net,
                        'tax' => $tax,
                    ];
                })
                // #2074 — bỏ nhóm mức KHÔNG mang gì cả, đúng luật mà sổ đang
                // dùng (`WritesCustomerOrders::writeConditions`: bỏ qua khi
                // `tax === 0 && taxable === 0`).
                //
                // Ca sinh ra nó: giảm giá phủ hết giỏ (coupon ≥ giỏ, hoặc mọi
                // món đã hoàn — #2114). Sổ bỏ nhóm ấy, payload thì `groupBy`
                // theo `tax_rate` nên vẫn phát một dòng `10%: nền 0 / thuế 0`.
                // Hai bảng khác nhau cho cùng một đơn.
                //
                // ⚠️ Điều kiện phải là CẢ HAI bằng 0. Nhóm 非課税 / zero-rated có
                // nền > 0 và thuế = 0 thì BẮT BUỘC phải có mặt — Peppol
                // BR-Z-08 / BR-E-08, và 非課税 ở Nhật phải phân biệt được trên
                // chứng từ. Bỏ nhầm nó chính là lỗi #2069.
                //
                // Đây là bản sao THỨ HAI của cùng một luật, và nó biến mất khi
                // resource gọi thẳng `OrderTaxBreakdownAggregator` (bước 2 của
                // #2074). Tới lúc đó, `TaxBreakdownTwoReadersAgreeTest` là thứ
                // giữ hai bản không rẽ nhau.
                ->reject(fn ($row) => $row['taxable'] === 0.0 && $row['tax'] === 0.0)
                ->sortBy('rate')
                ->values()
                ->all();

            // Only meaningful when every line carries a snapshot — a legacy
            // order's residual is missing line stamps, not sc tax.
            $fullyStamped = $items->every(fn ($i) => $i->tax_rate !== null);
            $base['service_charge_tax'] = $fullyStamped
                ? max(0.0, (float) $this->tax_amount - $lineTaxTotal)
                : null;
        }

        // plan-045 rev-B — normalize the snapshot rounding mode to one of the
        // three canonical directions (round/ceil/floor) so the admin UI renders
        // a known label even for orders stamped before the rename (half_up/
        // round_up/round_down). decimals coerced to 0 (the "auto"/null option
        // was dropped) — legacy null priced at the currency step, which is 0 for
        // the integer currencies these shops use.
        $base['tax_rounding_mode'] = match ($this->tax_rounding_mode) {
            'ceil', 'round_up' => 'ceil',
            'floor', 'round_down' => 'floor',
            default => 'round',
        };
        $base['tax_rounding_decimals'] = $this->tax_rounding_decimals ?? 0;

        // plan-045 — the order-level condition ledger (tax / discount / refund
        // value-copied snapshots). Lazy: only serialized when eager-loaded.
        $base['conditions'] = $this->whenLoaded('conditions', fn () => $this->conditions->map(fn ($c) => [
            'id' => $c->id,
            'type' => $c->type,
            'source' => $c->source,
            'label' => $c->label,
            'rate' => $c->rate !== null ? (float) $c->rate : null,
            'amount' => (float) $c->amount,
            'currency_code' => $c->currency_code,
            'meta' => $c->meta,
        ])->values(), []);

        // #1282/#2934 — read-only payment summary for receipts and reports. A
        // payment taken online (customer-web / Stripe / PayPay / konbini) is
        // confirmed in Cloud, so the workstation never records a local
        // `payments` row for it and its receipt printed no 支払方法 line at all.
        // This carries the method identity DOWN so the slip can name it; its
        // gross/net amounts also let revenue reports include online tenders.
        //
        // Deliberately NOT the full payment rows: the workstation must not
        // materialize these as local payments — that table feeds the Z-report /
        // 精算 and the plan-044 gap-reconciliation panel, so an online payment
        // landing there would move till money. Keep it a label source only.
        //
        // Only settled, non-reversal rows: a refund is its own negative row
        // (`refund_of_id` set) and would name no new method. `refunded` stays in
        // so a 赤伝 can still state how the original was paid.
        // A caller that wants the summary WITHOUT shipping the rows (the
        // workstation pull — see OrderController::index) precomputes it and
        // unsets the relation, so the default below picks it up.
        $base['payment_summary'] = $this->whenLoaded(
            'payments',
            fn () => self::buildPaymentSummary($this->payments),
            $this->getAttribute('payment_summary') ?? [],
        );

        return $base;
    }

    /**
     * Build the read-only payment summary from a loaded payments relation.
     *
     * Public so a caller can precompute it and then drop the relation, keeping
     * the raw rows out of its payload (a 60s sync tick has no use for ~35
     * columns per payment, gateway snapshots included).
     *
     * @param  Collection<int, OrderPayment>  $payments
     * @return array<int, array<string, mixed>>
     */
    public static function buildPaymentSummary($payments): array
    {
        // #2934 — reports need the amount still held after refunds, not only the
        // original charge. Refunds are signed rows pointing at the original; ship
        // the net without exposing the reversal rows themselves as fake methods.
        $refundsByOriginal = $payments
            ->filter(fn ($p) => $p->refund_of_id !== null
                && ($p->status instanceof \BackedEnum ? $p->status->value : $p->status) === 'succeeded')
            ->groupBy('refund_of_id')
            ->map(fn ($rows) => $rows->map(fn ($refund) => [
                'id' => $refund->id,
                'amount' => (string) $refund->amount,
                'status' => $refund->status instanceof \BackedEnum ? $refund->status->value : $refund->status,
                'paid_at' => $refund->paid_at?->toISOString() ?? $refund->created_at?->toISOString(),
            ])->values());

        return $payments
            ->filter(fn ($p) => in_array(
                $p->status instanceof \BackedEnum ? $p->status->value : $p->status,
                ['confirmed', 'succeeded', 'refunded'],
                true,
            ) && $p->refund_of_id === null && (float) $p->amount > 0)
            ->map(function ($p) use ($refundsByOriginal) {
                $refunds = $refundsByOriginal->get($p->id, collect());

                return [
                    'id' => $p->id,
                    'payment_method_id' => $p->payment_method_id,
                    // The workstation prefers its OWN synced payment_methods row
                    // (by id, then code) and only falls back to this name, so the
                    // slip stays in the operator's print locale.
                    'payment_method_code' => $p->paymentMethod?->code,
                    'payment_method_name' => self::translatedMethodName($p->paymentMethod),
                    'amount' => (string) $p->amount,
                    'net_amount' => (string) max(0, (float) $p->amount + (float) $refunds->sum('amount')),
                    // Keep signed refund instants so a daily report books the
                    // reversal on the day it happened rather than rewriting
                    // the original sale day. Receipt/history readers ignore it.
                    'refunds' => $refunds->all(),
                    'status' => $p->status instanceof \BackedEnum ? $p->status->value : $p->status,
                    'paid_at' => $p->paid_at?->toISOString(),
                ];
            })->values()->all();
    }

    /**
     * Payment-method display name, resilient to a method row that carries no
     * `translations` rows.
     *
     * Astrotomic resolves a translatable attribute ONLY through that relation
     * and returns null when it is empty — the same trap that once made the
     * workstation print "Store" for a branch named 新宿店 (see
     * PreservesTranslatableColumns, which patches serialization but not direct
     * property access). Fall back to the base column the way that trait does.
     */
    private static function translatedMethodName(?PaymentMethod $method): ?string
    {
        if ($method === null) {
            return null;
        }

        $name = $method->name;
        if (is_string($name) && trim($name) !== '') {
            return $name;
        }

        $base = $method->getRawOriginal('name');

        return is_string($base) && trim($base) !== '' ? $base : null;
    }
}
