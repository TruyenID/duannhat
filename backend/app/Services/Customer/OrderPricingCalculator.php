<?php

namespace App\Services\Customer;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Support\RoundingMode;
use Illuminate\Support\Facades\Log;

/**
 * plan-043 §8 — the single pricing engine for consumption tax.
 *
 * Replaces the old single-rate calculator. Groups the order's lines by their
 * snapshot tax_rate, allocates the coupon discount pro-rata per group, taxes
 * the service charge with its own rate, and rounds tax ONCE per rate group
 * (端数処理は税率ごとに1回, インボイス) with a single half-up-to-currency-step
 * rule. Handles both tax-excluded (add-on) and tax-included (総額表示,
 * extraction) modes.
 *
 * The identical algorithm is ported to Go (workstation) and TS (pos/customer
 * web) — a shared fixture table asserts they agree to the currency step.
 */
final class OrderPricingCalculator
{
    /**
     * Statuses where pricing is finalized + persisted (stamped at checkout) —
     * frozen against later branch rate changes. The per-rate breakdown is
     * still recomputed from the IMMUTABLE line snapshots (deterministic → same
     * numbers as checkout time).
     */
    private const FINALIZED_STATUSES = [
        // Submitted-but-pre-checkout: a takeaway / counter-pay order the customer
        // already committed to. Its total is LOCKED at submission — the kiosk and
        // customer-web must show the stored figure, never a live recompute with
        // the current branch rates (#501). Only OPEN / DINING dine-in bills price
        // live.
        CustomerOrderStatusEnum::AwaitingConfirmation,
        CustomerOrderStatusEnum::Confirmed,
        CustomerOrderStatusEnum::Checkout,
        CustomerOrderStatusEnum::Paying,
        CustomerOrderStatusEnum::Closed,
        CustomerOrderStatusEnum::Voided,
    ];

    /**
     * Core engine (§8). Prices a set of per-rate taxable subtotals.
     *
     * @param  array<string, float>  $rateSubtotals  rate (as string key) → Σ line subtotal for that rate
     */
    public function priceGroups(
        array $rateSubtotals,
        float $discount,
        float $serviceChargeRate,
        float $serviceChargeTaxRate,
        bool $pricesIncludeTax,
        float $step,
        ?float $taxStep = null,
        string $taxMode = 'round',
        ?array $discountWeights = null,
    ): PricingResult {
        // plan-045 — TAX figures round with the order's snapshot rule (taxStep +
        // taxMode); the service-charge BASE and the grand TOTAL still round to the
        // currency minor unit ($step). Default taxStep = $step + round keeps the
        // pre-plan-045 behaviour byte-for-byte (round ≡ the old half_up).
        $taxStep ??= $step;
        $subtotal = 0.0;
        foreach ($rateSubtotals as $groupSubtotal) {
            $subtotal += $groupSubtotal;
        }
        $subtotal = max(0.0, $subtotal);
        $discount = max(0.0, min($discount, $subtotal));
        $taxableBase = max(0.0, $subtotal - $discount);

        // Step 3 — service charge on (subtotal − discount). In included mode
        // that base is gross (Q12). sc tax is its own rate: added on top when
        // excluded, extracted when included.
        $serviceCharge = RoundingMode::roundHalfUpToStep($taxableBase * $serviceChargeRate / 100.0, $step);
        // #2480 — cùng hình dạng với {@see groupTaxFor}: làm tròn THUẾ, không
        // làm tròn nền rồi lấy phần dư. Xem docblock ở đó.
        $serviceChargeTax = $pricesIncludeTax
            ? RoundingMode::roundToStep($serviceCharge * $serviceChargeTaxRate / (100.0 + $serviceChargeTaxRate), $taxStep, $taxMode)
            : RoundingMode::roundToStep($serviceCharge * $serviceChargeTaxRate / 100.0, $taxStep, $taxMode);

        // Steps 1+2+4 — per-rate groups with pro-rata discount + once-per-group tax.
        $groups = [];
        $totalGroupTax = 0.0;
        $sumNet = 0.0; // Σ taxable (excluded) or Σ gross (included)

        // #2240 — mẫu số pro-rata của khoản giảm là tuỳ chọn: mặc định là gross
        // của chính nhóm (hành vi cũ), nhưng đơn có dòng HOÀN truyền trọng số
        // gross CÒN SỐNG (`survivingGrossByRate`) để phần giảm từng ngồi trên
        // nhóm bị hoàn DI CƯ sang nhóm còn giữ — mô hình đánh-giá-lại của repo
        // (#2079/#550/#2114): coupon không đi theo món được trả, nó dồn sang
        // phần hàng khách giữ. Không có trọng số, đơn A ¥1.000 @10% + B ¥1.000
        // @8% + coupon 500, hoàn A: share 250 kẹt lại trên nhóm 10% đã rỗng ⇒
        // tax 35 thay vì 40, và nhóm 10% xuống ÂM trên tax_breakdown của khách.
        $weightSum = 0.0;
        if ($discountWeights !== null) {
            foreach ($discountWeights as $w) {
                $weightSum += max(0.0, (float) $w);
            }
        }

        foreach ($rateSubtotals as $rateKey => $groupSubtotal) {
            $rate = (float) $rateKey;
            if ($discountWeights !== null) {
                $w = max(0.0, (float) ($discountWeights[(string) $rateKey] ?? 0.0));
                $discountGroup = $weightSum > 0 ? $discount * $w / $weightSum : 0.0;
            } else {
                $discountGroup = $subtotal > 0 ? $discount * $groupSubtotal / $subtotal : 0.0;
            }
            $netGroup = max(0.0, $groupSubtotal - $discountGroup);

            // Round tax ONCE per rate group (端数処理は税率ごとに1回). The SAME
            // {@see groupTaxFor} figure is re-used by CustomerOrderService when
            // it allocates the per-line tax_amount snapshots, so Σ line == this
            // group tax and the two never drift.
            $groupTax = $this->groupTaxFor($netGroup, $rate, $pricesIncludeTax, $taxStep, $taxMode);
            $taxable = $pricesIncludeTax ? $netGroup - $groupTax : $netGroup;

            $groups[(string) $rate] = new TaxGroup($rate, $taxable, $groupTax);
            $totalGroupTax += $groupTax;
            $sumNet += $netGroup;
        }

        // gap #7 — service-charge tax joins the breakdown group of the SAME
        // rate (or forms one if none exists). Its taxable share is added too.
        if ($serviceCharge > 0 && $serviceChargeTaxRate > 0) {
            $scRateKey = (string) $serviceChargeTaxRate;
            $scNet = $pricesIncludeTax ? $serviceCharge - $serviceChargeTax : $serviceCharge;
            $existing = $groups[$scRateKey] ?? null;
            $groups[$scRateKey] = new TaxGroup(
                $serviceChargeTaxRate,
                ($existing?->taxable ?? 0.0) + $scNet,
                ($existing?->tax ?? 0.0) + $serviceChargeTax,
            );
        }

        // Stable display order: by rate ascending.
        ksort($groups, SORT_NUMERIC);
        $groups = array_values($groups);

        $taxAmount = $totalGroupTax + $serviceChargeTax;

        if ($pricesIncludeTax) {
            // Prices already include tax → do NOT add group taxes again.
            // total = Σ gross groups + service charge (gross, its tax inside).
            $totalAmount = RoundingMode::roundHalfUpToStep($sumNet + $serviceCharge, $step);
        } else {
            $totalAmount = RoundingMode::roundHalfUpToStep(
                $taxableBase + $totalGroupTax + $serviceCharge + $serviceChargeTax,
                $step,
            );
        }

        return new PricingResult(
            subtotal: $subtotal,
            discount: $discount,
            taxableBase: $taxableBase,
            groups: $groups,
            serviceCharge: $serviceCharge,
            serviceChargeTax: $serviceChargeTax,
            taxAmount: $taxAmount,
            totalAmount: $totalAmount,
            pricesIncludeTax: $pricesIncludeTax,
        );
    }

    /**
     * The once-per-group consumption tax for one rate group's net base
     * (端数処理は税率ごとに1回, インボイス). Excluded mode adds tax on top;
     * included mode (総額表示) extracts the 内税 from the gross. This is the
     * single formula behind BOTH the order-level `tax_amount` (via
     * {@see priceGroups}) and the per-line snapshot allocation (via
     * {@see allocateGroupTax}), so the two are numerically identical.
     *
     * ## #2480 — hướng làm tròn áp lên THUẾ, ở cả hai chế độ
     *
     * Bản trước tính 内税 là `gross − round(gross / (1 + r))`: hướng làm tròn
     * áp lên phần NỀN, còn thuế là phần dư. Hệ quả là nó **đảo ngược** ở đúng
     * chế độ mà mọi chi nhánh đang dùng — `floor` ("làm tròn xuống") cho ra
     * thuế CAO hơn, `ceil` cho ra thuế THẤP hơn.
     *
     * Nhãn cài đặt nói thẳng nó làm gì: *"Which direction **tax amounts** are
     * rounded on each order"* (`settings.order.tax_rounding_mode_hint`). Và
     * 消費税 của Nhật cũng làm tròn **số thuế** — 税込価格 × 10/110 rồi 端数処理,
     * không phải làm tròn giá chưa thuế.
     *
     * Không chỉ ngược nhãn mà còn **sai ở biên**: món ¥1 ở 8% 内税, công thức cũ
     * với `floor` cho `1 − floor(0,926) = 1`, tức khai TOÀN BỘ giá là thuế.
     *
     * Quét 40.000 tổ hợp (8% và 10% × gross 1…20.000): `half_up` lệch **0 ca**
     * giữa hai công thức, nên mọi quán ở chế độ mặc định không đổi một đồng
     * nào. Chỉ `floor`/`ceil` đổi — và đó đúng là hai chế độ đang bị đảo.
     *
     * Tổng tiền khách trả KHÔNG đổi ở cả hai chế độ: ở 内税
     * `totalAmount = round(Σ gross + phí phục vụ)` không dùng tới con số thuế.
     * Cái đổi là phần được ghi nhận LÀ thuế bên trong tổng đó.
     */
    public function groupTaxFor(float $netGroup, float $rate, bool $pricesIncludeTax, float $taxStep, string $taxMode = 'round'): float
    {
        return $pricesIncludeTax
            ? RoundingMode::roundToStep($netGroup * $rate / (100.0 + $rate), $taxStep, $taxMode)
            : RoundingMode::roundToStep($netGroup * $rate / 100.0, $taxStep, $taxMode);
    }

    /**
     * One line's EXACT (unrounded) tax share for the largest-remainder
     * allocation. Same formula as {@see groupTaxFor} without the rounding — the
     * fractional part is what the allocator distributes to keep Σ line ==
     * group tax.
     */
    public function lineTaxIdeal(float $netLine, float $rate, bool $pricesIncludeTax): float
    {
        return $pricesIncludeTax
            ? $netLine - $netLine / (1 + $rate / 100.0)
            : $netLine * $rate / 100.0;
    }

    /**
     * Allocate a rate group's once-rounded tax ({@see groupTaxFor}) across its
     * lines so the parts sum EXACTLY to $groupTax (largest-remainder / Hamilton
     * apportionment). Each line lands within one currency step of its exact
     * {@see lineTaxIdeal} share; the whole steps go to the lines with the
     * largest fractional remainders (deterministic tie-break by input order).
     *
     * This is what lets reports that SUM the per-line `tax_amount` snapshots
     * (Z-report, revenue dashboards, tax_breakdown) reconcile to
     * `order.tax_amount` — the per-line values are no longer independently
     * rounded (which summed to a different, インボイス-forbidden figure).
     *
     * A sub-step residual only appears in 内税 (included) mode when the group
     * tax itself is not a whole step; it lands on a single line and the parts
     * still sum exactly. Values are guaranteed non-negative.
     *
     * @param  float[]  $ideals  each line's exact unrounded tax share, in input order
     * @return float[] per-line tax, same order/keys 0..n-1, Σ == $groupTax
     */
    public function allocateGroupTax(array $ideals, float $groupTax, float $step): array
    {
        $n = count($ideals);
        if ($n === 0) {
            return [];
        }

        // mode `none` (step 0) — exact division, the last line absorbs the
        // residual so the sum still lands on $groupTax.
        if ($step <= 0.0) {
            $out = array_map(fn ($v) => max(0.0, (float) $v), array_values($ideals));
            $out[$n - 1] += $groupTax - array_sum($out);

            return $out;
        }

        $eps = $step * 1e-9;
        $out = [];
        $frac = [];
        foreach (array_values($ideals) as $i => $v) {
            $v = max(0.0, (float) $v);
            $base = floor($v / $step + 1e-9) * $step; // step-multiple ≤ ideal
            $out[$i] = $base;
            $frac[$i] = $v - $base;                   // remainder in [0, step)
        }

        // Line indices ordered by fractional remainder, largest first; ties
        // keep input order so re-runs are deterministic.
        $byFracDesc = range(0, $n - 1);
        usort($byFracDesc, fn ($a, $b) => ($frac[$b] <=> $frac[$a]) ?: ($a <=> $b));

        $deficit = $groupTax - array_sum($out);

        if ($deficit >= -$eps) {
            // Hand out whole steps to the most-deserving lines, then drop any
            // sub-step remainder on the next one. Only additions → stays ≥ 0.
            $wholeSteps = max(0, min((int) floor($deficit / $step + 1e-9), $n));
            for ($k = 0; $k < $wholeSteps; $k++) {
                $out[$byFracDesc[$k]] += $step;
            }
            $residual = $groupTax - array_sum($out);
            if (abs($residual) > $eps) {
                $out[$byFracDesc[min($wholeSteps, $n - 1)]] += $residual;
            }
        } else {
            // Rare 内税 case: exact group tax sits just below the floored sum.
            // Remove the tiny (< step) shortfall from the line with the most
            // room so no line can go negative (Σ base > |deficit| here).
            $byBaseDesc = range(0, $n - 1);
            usort($byBaseDesc, fn ($a, $b) => ($out[$b] <=> $out[$a]) ?: ($a <=> $b));
            $out[$byBaseDesc[0]] += $deficit; // deficit < 0
        }

        foreach ($out as $i => $v) {
            if ($v < 0 && $v > -$eps) {
                $out[$i] = 0.0; // scrub fp dust / -0.0
            }
        }

        return $out;
    }

    /**
     * plan-045 — fold appended REFUND lines (negative qty, refund_of_item_id set,
     * copied+negated tax snapshot) into a base result computed from the POSITIVE
     * lines. Refund lines never go through group-once rounding; their stored
     * (negated) subtotal + tax_amount are added DIRECTLY so the reversal exactly
     * cancels the original line's tax (Stripe reversal semantics):
     *   subtotal   += Σ refund.subtotal      (negative)
     *   tax_amount += Σ refund.tax_amount     (negative)
     *   total      += excluded ? Σ(sub+tax) : Σ sub   (gross already inside sub)
     * and each matching rate group's tax + taxable is reduced by the refund share.
     *
     * @param  iterable<CustomerOrderItem>  $items
     */
    public function applyRefundLines(PricingResult $base, iterable $items): PricingResult
    {
        $refundSubtotal = 0.0;
        $refundTax = 0.0;
        $hasRefund = false;
        $dropped = 0;
        $groupTaxDelta = [];   // rate(string) → Σ refund tax (negative)
        $groupNetDelta = [];   // rate(string) → Σ refund taxable (negative)

        foreach ($items as $item) {
            if ($item->refund_of_item_id === null) {
                continue;
            }
            if ($this->itemStatus($item) === OrderItemStatusEnum::Voided->value) {
                continue;
            }
            // #2188 / #2257 — refund lines inherit a stamped snapshot from the
            // source line; NULL rate here is broken input and is DROPPED (matches
            // Go refundLinesFromDB + the positive path in rateSubtotalsForOrder).
            if ($item->tax_rate === null) {
                $dropped++;

                continue;
            }
            $hasRefund = true;
            $sub = (float) $item->subtotal;    // negative
            $tax = (float) $item->tax_amount;  // negative
            $refundSubtotal += $sub;
            $refundTax += $tax;
            $rate = (float) $item->tax_rate;
            $key = (string) $rate;
            $groupTaxDelta[$key] = ($groupTaxDelta[$key] ?? 0.0) + $tax;
            // taxable base of the refund: excluded → net = subtotal; included →
            // net = gross − internal tax = subtotal − tax.
            $net = $base->pricesIncludeTax ? $sub - $tax : $sub;
            $groupNetDelta[$key] = ($groupNetDelta[$key] ?? 0.0) + $net;
        }

        if ($dropped > 0) {
            $orderId = null;
            foreach ($items as $item) {
                if (isset($item->customer_order_id)) {
                    $orderId = (string) $item->customer_order_id;
                    break;
                }
            }
            Log::warning('[pricing] dropped refund lines with no tax_rate snapshot (#2188 — bỏ dòng, không bịa)', [
                'order_id' => $orderId,
                'dropped_lines' => $dropped,
            ]);
        }

        if (! $hasRefund) {
            return $base;
        }

        $groups = [];
        $seen = [];
        foreach ($base->groups as $g) {
            $key = (string) $g->rate;
            $seen[$key] = true;
            $groups[] = new TaxGroup(
                $g->rate,
                $g->taxable + ($groupNetDelta[$key] ?? 0.0),
                $g->tax + ($groupTaxDelta[$key] ?? 0.0),
            );
        }
        // A refund at a rate whose positive group is already gone (fully refunded)
        // still needs its own row so Σ groups.tax == tax_amount.
        foreach ($groupTaxDelta as $key => $taxDelta) {
            if (! isset($seen[$key])) {
                $groups[] = new TaxGroup((float) $key, $groupNetDelta[$key] ?? 0.0, $taxDelta);
            }
        }
        usort($groups, fn (TaxGroup $a, TaxGroup $b) => $a->rate <=> $b->rate);

        $totalDelta = $base->pricesIncludeTax ? $refundSubtotal : ($refundSubtotal + $refundTax);

        return new PricingResult(
            subtotal: $base->subtotal + $refundSubtotal,
            discount: $base->discount,
            taxableBase: $base->taxableBase,
            groups: $groups,
            serviceCharge: $base->serviceCharge,
            serviceChargeTax: $base->serviceChargeTax,
            taxAmount: $base->taxAmount + $refundTax,
            totalAmount: $base->totalAmount + $totalDelta,
            pricesIncludeTax: $base->pricesIncludeTax,
        );
    }

    /**
     * Σ subtotal của các dòng HOÀN TIỀN (âm) — dùng cho phép tính lại coupon.
     *
     * `rateSubtotalsForOrder()` cố ý BỎ QUA dòng hoàn: chúng mang ảnh chụp thuế
     * đã âm sẵn và không được đi qua bộ phân bổ ≥0. Đúng cho việc tính thuế.
     *
     * Nhưng cùng con số ấy từng bị dùng lại làm nền TÍNH LẠI COUPON, và ở vai đó
     * nó sai: hoàn nửa đơn xong coupon vẫn tính trên giỏ NGUYÊN (#2079). Hàm này
     * cho người gọi phần bù, với đúng bộ lọc mà {@see applyRefundLines()} dùng —
     * một chỗ định nghĩa "dòng hoàn nào được tính", không phải hai.
     */
    public function refundedSubtotalFor(iterable $items): float
    {
        $sum = 0.0;

        foreach ($items as $item) {
            if ($item->refund_of_item_id === null) {
                continue;
            }
            if ($this->itemStatus($item) === OrderItemStatusEnum::Voided->value) {
                continue;
            }
            // #2257 — cùng bộ lọc với applyRefundLines(): dòng hoàn NULL rate
            // đã bị DROP khỏi thuế thì cũng không được co giỏ sống (parity Go
            // liveGrossSubtotal lọc NULL cả hai vế).
            if ($item->tax_rate === null) {
                continue;
            }
            $sum += (float) $item->subtotal;   // âm
        }

        return $sum;
    }

    /**
     * Effective pricing for an order. Builds per-rate groups from the line
     * snapshots; finalized orders keep their frozen scalar totals while the
     * breakdown is recomputed from the immutable snapshots.
     */
    public function forOrder(CustomerOrder $order, ?ShopOrderSetting $setting): PricingResult
    {
        // #815 — default JPY (khớp charge currency của Stripe); JPY/VND đều zero-decimal
        // nên step giống nhau, đổi default chỉ để thống nhất nguồn currency toàn hệ.
        $step = RoundingMode::step('auto', $setting?->currency_code ?? 'JPY');
        // #2108 — the order's frozen 総額表示 snapshot only. The old
        // `?? $setting?->prices_include_tax` fallback re-read the LIVE branch
        // flag for a historical order (the exact reinterpretation the ruling
        // forbids); it was also dead code — the column is NOT NULL.
        $includeTax = (bool) $order->is_tax_included;
        // plan-045 — read the order's SNAPSHOT rounding rule (never the live
        // setting). Legacy/blank orders default round + 0 decimals (round ≡ the
        // old half_up; null decimals still falls back to the currency step).
        $taxMode = $order->tax_rounding_mode ?: 'round';
        $taxDecimals = $order->tax_rounding_decimals !== null ? (int) $order->tax_rounding_decimals : null;
        $taxStep = RoundingMode::taxStep($taxDecimals, $setting?->currency_code ?? 'VND');

        $rateSubtotals = $this->rateSubtotalsForOrder($order);

        // #2188 — the stored-subtotal-only fallback (a single group priced off
        // $order->subtotal when no line carried a snapshot) was REMOVED with
        // the legacy ruling. An order with money but no groupable lines is
        // broken input; it prices to zero groups and leaves a warning instead
        // of a fabricated single-rate breakdown (#2067 pattern).
        if ($rateSubtotals === [] && (float) $order->subtotal > 0) {
            Log::warning('[pricing] order has a stored subtotal but no tax-stamped lines — pricing zero groups, not inventing one (#2188)', [
                'order_id' => (string) $order->id,
                'subtotal' => (float) $order->subtotal,
            ]);
        }

        $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();

        // #2182 — khoản giảm ÁP DỤNG ĐƯỢC bị kẹp bởi giỏ SỐNG (gộp − đã hoàn),
        // không phải bởi tổng GỘP. `$rateSubtotals` không co lại khi hàng được
        // trả, nên phép kẹp `min($discount, $subtotal)` bên trong `priceGroups`
        // vẫn để nguyên khoản giảm trên một giỏ đã hoàn hết. Cùng phép kẹp mà
        // `applyPricing` dùng lúc GHI — hai đường phải ra một con số, nếu không
        // bảng hiển thị và cột đã lưu nói hai chuyện khác nhau.
        $appliedDiscount = min(
            (float) $order->discount_amount,
            max(0.0, array_sum($rateSubtotals) + $this->refundedSubtotalFor($items)),
        );

        $result = $this->priceGroups(
            $rateSubtotals,
            $appliedDiscount,
            (float) ($setting?->service_charge_rate ?? 0),
            (float) ($setting?->service_charge_tax_rate ?? 0),
            $includeTax,
            $step,
            $taxStep,
            $taxMode,
        );

        // plan-045 — fold any appended refund lines' negated snapshot into the
        // live result (exact reversal; excluded from the group-once above).
        $result = $this->applyRefundLines($result, $items);

        if (in_array($this->resolveStatus($order), self::FINALIZED_STATUSES, true)) {
            // AUDIT FIX 1.4 (2026-07-14): the frozen scalars used to ride with
            // groups recomputed from the CURRENT branch service-charge settings
            // — editing service_charge_rate / service_charge_tax_rate after
            // checkout made the displayed breakdown (invoice, email, kiosk)
            // disagree with the stored tax_amount. Rebuild the groups from the
            // immutable line snapshots ONLY, then reconcile the service-charge
            // tax as the RESIDUAL against the stored figure — Σ groups.tax now
            // always equals the frozen order.tax_amount regardless of later
            // setting edits. (Placement of the residual still uses the current
            // sc tax rate — only the label rate, never the amount.)
            $lineOnly = $this->priceGroups(
                $rateSubtotals,
                $appliedDiscount,
                0.0,
                0.0,
                $includeTax,
                $step,
                $taxStep,
                $taxMode,
            );
            // plan-045 — refund lines belong to the line-derived breakdown, so
            // the residual below isolates the service-charge tax only (never the
            // refund tax, which would otherwise be mislabelled as sc tax).
            $lineOnly = $this->applyRefundLines($lineOnly, $items);

            $groups = $lineOnly->groups;
            $serviceCharge = (float) $order->service_charge;
            $residual = (float) $order->tax_amount - $lineOnly->taxAmount;
            $serviceChargeTax = 0.0;

            // Attribute the residual to the service charge only when the order
            // actually carried one — a legacy order with unstamped (NULL-rate)
            // lines also leaves a residual, but that gap is missing line
            // snapshots, not sc tax, and must not be mislabelled.
            if ($residual > 0 && $serviceCharge > 0) {
                $serviceChargeTax = $residual;
                $scRate = (float) ($setting?->service_charge_tax_rate ?? 0);
                if ($scRate > 0) {
                    // Round-3 audit B1 (2026-07-14): clamp — corrupted/legacy
                    // data can leave residual > serviceCharge in included
                    // mode, which would push the merged group's taxable
                    // NEGATIVE on the invoice. The tax amount stays the exact
                    // residual either way; only the taxable share is floored.
                    $scNet = $includeTax ? max(0.0, $serviceCharge - $residual) : $serviceCharge;
                    $merged = false;
                    foreach ($groups as $i => $group) {
                        if (abs($group->rate - $scRate) < 1e-9) {
                            $groups[$i] = new TaxGroup($group->rate, $group->taxable + $scNet, $group->tax + $residual);
                            $merged = true;
                            break;
                        }
                    }
                    if (! $merged) {
                        $groups[] = new TaxGroup($scRate, $scNet, $residual);
                        usort($groups, fn (TaxGroup $a, TaxGroup $b) => $a->rate <=> $b->rate);
                    }
                }
            }

            return new PricingResult(
                subtotal: (float) $order->subtotal,
                discount: (float) $order->discount_amount,
                taxableBase: max(0.0, (float) $order->subtotal - (float) $order->discount_amount),
                groups: $groups,
                serviceCharge: $serviceCharge,
                serviceChargeTax: $serviceChargeTax,
                taxAmount: (float) $order->tax_amount,
                totalAmount: (float) $order->total_amount,
                pricesIncludeTax: $includeTax,
            );
        }

        return $result;
    }

    /**
     * Build the rate → Σ line-subtotal map from an order's non-voided items.
     *
     * #2188 — a line with no snapshot rate is DROPPED with a warning, never
     * priced at an invented rate: creation always stamps, and old data was
     * reseeded/backfilled once, so an unstamped line here is broken input.
     * Skipping it makes the damage visible (the total misses the line) instead
     * of silently mis-taxing it (#2067 pattern).
     *
     * @return array<string, float>
     */
    public function rateSubtotalsForOrder(CustomerOrder $order): array
    {
        $rateSubtotals = [];
        $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();
        $dropped = 0;

        foreach ($items as $item) {
            if ($this->itemStatus($item) === OrderItemStatusEnum::Voided->value) {
                continue;
            }
            // plan-045 — refund lines (negative qty, refund_of_item_id set) are
            // EXCLUDED from the positive group-once tax. Their copied+negated
            // snapshot tax is folded in directly by applyRefundLines() so the
            // reversal is exact (no re-round) — matches the Stripe reversal model.
            if ($item->refund_of_item_id !== null) {
                continue;
            }
            if ($item->tax_rate === null) {
                $dropped++;

                continue;
            }
            $rate = (float) $item->tax_rate;
            $lineSubtotal = (float) $item->quantity * ((float) $item->unit_price + (float) ($item->topping_subtotal ?? 0));
            $rateSubtotals[(string) $rate] = ($rateSubtotals[(string) $rate] ?? 0.0) + $lineSubtotal;
        }

        if ($dropped > 0) {
            Log::warning('[pricing] dropped order lines with no tax_rate snapshot from the rate groups (#2188 — bỏ dòng, không bịa)', [
                'order_id' => (string) $order->id,
                'dropped_lines' => $dropped,
            ]);
        }

        return $rateSubtotals;
    }

    /**
     * #2240 — gross CÒN SỐNG theo nhóm rate: `(quantity − refunded_quantity) ×
     * đơn giá gộp` của các dòng dương, cùng bộ lọc + keying với
     * {@see rateSubtotalsForOrder}. Đây là MẪU SỐ phân bổ khoản giảm khi đơn có
     * dòng hoàn (truyền vào `priceGroups(discountWeights:)` và dùng lại y hệt ở
     * mức dòng trong `allocateLineTaxes`) — một nguồn duy nhất để
     * "Σ thuế từng dòng == tax_amount của đơn" giữ nguyên theo cấu trúc.
     *
     * @return array<string, float> rate(string) → gross còn sống (≥ 0)
     */
    public function survivingGrossByRate(CustomerOrder $order): array
    {
        $weights = [];
        $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();

        foreach ($items as $item) {
            if ($this->itemStatus($item) === OrderItemStatusEnum::Voided->value) {
                continue;
            }
            if ($item->refund_of_item_id !== null) {
                continue;
            }
            if ($item->tax_rate === null) {
                continue;
            }
            $rate = (float) $item->tax_rate;
            $weights[(string) $rate] = ($weights[(string) $rate] ?? 0.0) + $this->survivingLineGross($item);
        }

        return $weights;
    }

    /**
     * #2240 — gross còn sống của MỘT dòng dương. Dùng `refunded_quantity` (ghi
     * bởi `refundItem` cùng transaction với dòng hoàn) thay vì đi cộng các dòng
     * hoàn con — một nguồn, không lệch.
     */
    public function survivingLineGross(CustomerOrderItem $item): float
    {
        $unitGross = (float) $item->unit_price + (float) ($item->topping_subtotal ?? 0);
        $surviving = (float) $item->quantity - (float) ($item->refunded_quantity ?? 0);

        return max(0.0, $surviving * $unitGross);
    }

    private function itemStatus(object $item): ?string
    {
        $status = $item->status;

        return $status instanceof OrderItemStatusEnum ? $status->value : (string) $status;
    }

    private function resolveStatus(CustomerOrder $order): ?CustomerOrderStatusEnum
    {
        $status = $order->status;

        if ($status instanceof CustomerOrderStatusEnum) {
            return $status;
        }

        return CustomerOrderStatusEnum::tryFrom((string) $order->getRawOriginal('status'));
    }

    /**
     * Single-rate pricing (dev / issue #524) — kept alongside the per-rate
     * {@see priceGroups} for the legacy single-rate path and the canonical
     * money-rounding regression tests. Half-up to the currency step; the #550
     * negative-total clamp lives in max(0, subtotal − discount).
     *
     * @return array{subtotal: float, discount: float, taxable_base: float, service_charge: float, tax_amount: float, total_amount: float}
     */
    public function price(float $subtotal, float $discount, float $taxRate, float $serviceChargeRate, ?string $currencyCode = null): array
    {
        $subtotal = max(0.0, $subtotal);
        $discount = max(0.0, $discount);
        $taxableBase = max(0.0, $subtotal - $discount);
        $step = RoundingMode::step('auto', $currencyCode);
        $serviceCharge = RoundingMode::roundHalfUpToStep($taxableBase * $serviceChargeRate / 100.0, $step);
        $taxAmount = RoundingMode::roundHalfUpToStep($taxableBase * $taxRate / 100.0, $step);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'taxable_base' => $taxableBase,
            'service_charge' => $serviceCharge,
            'tax_amount' => $taxAmount,
            'total_amount' => $taxableBase + $serviceCharge + $taxAmount,
        ];
    }
}
