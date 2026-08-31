<?php

namespace App\Services\Customer;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Support\RoundingMode;

/**
 * Plan-033 — Pure PHP port of pos-web's by-items split calculator.
 *
 * Mirrors `pos-web/src/app/pos/lib/split-by-items.ts` bit-for-bit so the
 * shared fixture set at `tests/Fixtures/split_by_items_cases.json` produces
 * identical per-bill totals on both sides. The reconcile algorithm is the
 * same one referenced in plan-033 NOTES (đã xoá #2188 — git history):
 *
 *   - Drift between Σ bill.total and order.total_amount is absorbed by the
 *     last non-empty bill (its tax + total both move by the same delta).
 *   - If absorbing the delta would push that bill below zero, the bill is
 *     clamped at 0 and the overshoot is forwarded to the first non-empty
 *     bill instead.
 *
 * This class is intentionally pure — no database access, no service
 * dependencies. It is called by both `OrderPaymentService::create()` (for
 * the by-items validation 422 codes) and `CustomerOrderController::splitByItemsPreview()`.
 */
final class SplitByItemsCalculator
{
    /**
     * Compute per-bill totals for a by-items split of an order.
     *
     * Allocations shape:
     *   [
     *     ['item_id' => '<uuid>', 'units' => 1, 'bill_index' => 0],
     *     ['item_id' => '<uuid>', 'units' => 2, 'bill_index' => 1],
     *     ...
     *   ]
     *
     * Multiple entries can target the same (item_id, bill_index) pair — they
     * are summed. Units less than 1 are discarded silently (callers should
     * shape-validate first).
     *
     * @param  array<int, array{item_id: string, units: int, bill_index: int}>  $allocations
     * @return array{
     *     bills: array<int, array{
     *         index: int,
     *         label: string,
     *         items_breakdown: array<int, array{item_id: string, units: int, subtotal: float}>,
     *         subtotal: float,
     *         discount: float,
     *         taxable_base: float,
     *         tax: float,
     *         service: float,
     *         total: float,
     *         is_empty: bool
     *     }>,
     *     unassigned_units: array<int, array{item_id: string, unit_index: int}>,
     *     total_check: float
     * }
     */
    public function compute(
        CustomerOrder $order,
        array $allocations,
        string $roundingMode,
        ?string $currencyCode,
        float $taxRate,
        float $serviceChargeRate,
        int $peopleCount,
        bool $reconcile = true,
        bool $pricesIncludeTax = false,
    ): array {
        $orderSubtotal = (float) ($order->subtotal ?? 0);
        $orderDiscount = (float) ($order->discount_amount ?? 0);
        $orderTotal = (float) ($order->total_amount ?? 0);

        /** @var array<int, CustomerOrderItem> $items */
        $items = $order->relationLoaded('items')
            ? $order->items->all()
            : $order->items()->get()->all();

        // Voided items are excluded from both UI and tax calc (BR-OI07).
        // status may be an EnumRef cast or a raw string depending on how the
        // model was hydrated — normalise via ->value when available.
        $activeItems = array_values(array_filter($items, function (CustomerOrderItem $i): bool {
            // #2159 — DÒNG HOÀN không phải một suất để chia.
            //
            // `refundItem` phụ thêm một dòng `quantity = -1` mang `unit_price`
            // **DƯƠNG** (chỉ `quantity`/`subtotal`/`tax_amount` đổi dấu). Bộ lọc
            // cũ chỉ loại `voided`, nên dòng ấy lọt vào đây và hai chỗ dưới biến
            // nó thành một suất ma: `max(1, -1) = 1`.
            //
            // Đo được trên đơn 1 dòng qty 2 @¥1.000 đã hoàn 1, thuế 0%:
            //
            //	unassigned_units = 3 (đáng lẽ 1) ⇒ thu ngân KHÔNG BAO GIỜ gán hết,
            //	                                   màn chia bill không hoàn tất được
            //	bill 0: subtotal 2.000, **tax = −1.000**, total 1.000
            //
            // Dòng thuế ÂM ¥1.000 trên một đơn 0% thuế là con số khách nhìn thấy.
            // Cơ chế giống #2130 mục A: nền tính từ giá GỘP không khớp tổng đơn,
            // và bước đối soát cuối dồn chênh lệch vào **thuế**.
            if ($i->refund_of_item_id !== null && $i->refund_of_item_id !== '') {
                return false;
            }

            // Voided items are excluded from both UI and tax calc (BR-OI07).
            // status may be an EnumRef cast or a raw string depending on how the
            // model was hydrated — normalise via ->value when available.
            $status = $i->status;
            $value = is_object($status) && property_exists($status, 'value') ? $status->value : $status;

            return $value !== 'voided';
        }));

        $people = max(0, $peopleCount);

        // Pre-size empty bills.
        $bills = [];
        for ($i = 0; $i < $people; $i++) {
            $bills[$i] = self::emptyBill($i);
        }

        // Build a quick lookup: itemId → CustomerOrderItem.
        $itemsById = [];
        foreach ($activeItems as $item) {
            $itemsById[(string) $item->id] = $item;
        }

        // Aggregate allocations by (itemId, billIndex).
        $aggregated = [];
        foreach ($allocations as $alloc) {
            $itemId = (string) ($alloc['item_id'] ?? '');
            $units = (int) ($alloc['units'] ?? 0);
            $billIndex = (int) ($alloc['bill_index'] ?? -1);
            if ($units < 1 || ! isset($itemsById[$itemId]) || $billIndex < 0 || $billIndex >= $people) {
                continue;
            }
            $key = $itemId.'|'.$billIndex;
            if (! isset($aggregated[$key])) {
                $aggregated[$key] = ['item_id' => $itemId, 'units' => 0, 'bill_index' => $billIndex];
            }
            $aggregated[$key]['units'] += $units;
        }

        // #2159 — KHÔNG gán quá số suất còn chia được.
        //
        // Trước đây phép gán không có trần: gán 2 suất cho một dòng chỉ còn 1
        // suất (vì 1 đã hoàn) được nhận im lặng, bill mang nền 2× tổng đơn, và
        // bước đối soát cuối dồn chênh lệch vào **thuế** — ra dòng thuế ÂM trên
        // phiếu khách, đúng cơ chế #2130 mục A.
        //
        // Kẹp ở đây chứ không 422: hàm này vừa phục vụ màn xem trước vừa phục vụ
        // đường validate, và một màn xem trước phải VẼ ĐƯỢC trạng thái sai để
        // thu ngân thấy mà sửa, thay vì ném lỗi rồi không hiện gì. Bên gọi nào
        // cần chặn cứng thì so `unassigned_units` như trước.
        $remaining = [];
        foreach ($activeItems as $item) {
            $remaining[(string) $item->id] = self::splittableUnits($item);
        }
        foreach ($aggregated as $key => $row) {
            $left = $remaining[$row['item_id']] ?? 0;
            $take = max(0, min($row['units'], $left));
            $remaining[$row['item_id']] = $left - $take;

            if ($take === 0) {
                unset($aggregated[$key]);

                continue;
            }
            $aggregated[$key]['units'] = $take;
        }

        // Apply allocations onto bills + track per-item claimed counts.
        $claimedByItem = [];
        foreach ($aggregated as $row) {
            $item = $itemsById[$row['item_id']];
            $bill = &$bills[$row['bill_index']];
            $unitSubtotal = self::perUnitSubtotal($item);
            $units = $row['units'];

            $bill['items_breakdown'][] = [
                'item_id' => $row['item_id'],
                'units' => $units,
                'subtotal' => $unitSubtotal * $units,
            ];

            $claimedByItem[$row['item_id']] = ($claimedByItem[$row['item_id']] ?? 0) + $units;
            unset($bill);
        }

        // Compute per-bill totals. plan-043 — each bill groups its own items
        // by snapshot tax_rate and rounds tax ONCE per rate group (インボイス),
        // so a mixed-rate bill (bentō 8% + beer 10%) is taxed correctly. A
        // line with no snapshot rate falls back to $taxRate (legacy single
        // rate) → single-rate bills reproduce the pre-plan-043 numbers.
        $step = RoundingMode::step($roundingMode, $currencyCode);
        // $minorStep — the currency's smallest accountable unit (JPY/VND → 1,
        // USD/EUR → 0.01, KWD → 0.001). Every money component (subtotal, discount,
        // tax, service) rounds half-up to THIS so sub-unit currencies keep their
        // precision; $step (the split_bill_rounding_mode) applies once at the very
        // end to the bill total. For integer currencies both steps are 1, so
        // JPY/VND behaviour is byte-for-byte unchanged.
        $minorStep = RoundingMode::step('auto', $currencyCode);
        foreach ($bills as &$bill) {
            $rawSubtotal = 0.0;
            $rateGroups = []; // rate (string key) → Σ subtotal in this bill
            foreach ($bill['items_breakdown'] as $breakdown) {
                $rawSubtotal += $breakdown['subtotal'];
                $item = $itemsById[$breakdown['item_id']];
                $rate = $item->tax_rate !== null ? (float) $item->tax_rate : $taxRate;
                $rateGroups[(string) $rate] = ($rateGroups[(string) $rate] ?? 0.0) + $breakdown['subtotal'];
            }
            $bill['subtotal'] = RoundingMode::roundHalfUpToStep($rawSubtotal, $minorStep);

            $ratio = $orderSubtotal > 0 ? $bill['subtotal'] / $orderSubtotal : 0.0;
            $bill['discount'] = RoundingMode::roundHalfUpToStep($orderDiscount * $ratio, $minorStep);
            $bill['taxable_base'] = max(0.0, $bill['subtotal'] - $bill['discount']);

            // Per-rate tax within the bill — coupon share allocated pro-rata
            // per group, then rounded once per group (half-up to the minor unit).
            $billTax = 0.0;
            foreach ($rateGroups as $rateKey => $groupSubtotal) {
                $rate = (float) $rateKey;
                $groupDiscount = $bill['subtotal'] > 0
                    ? $bill['discount'] * $groupSubtotal / $bill['subtotal']
                    : 0.0;
                $groupNet = max(0.0, $groupSubtotal - $groupDiscount);
                $billTax += $pricesIncludeTax
                    ? $groupNet - RoundingMode::roundHalfUpToStep($groupNet / (1 + $rate / 100.0), $minorStep)
                    : RoundingMode::roundHalfUpToStep($groupNet * $rate / 100.0, $minorStep);
            }
            $bill['tax'] = $billTax;
            $bill['service'] = RoundingMode::roundHalfUpToStep($bill['taxable_base'] * $serviceChargeRate / 100.0, $minorStep);
            // Included mode: tax is already inside the prices (内税) → not added.
            $bill['total'] = $pricesIncludeTax
                ? $bill['taxable_base'] + $bill['service']
                : $bill['taxable_base'] + $bill['tax'] + $bill['service'];

            // Apply the per-bill rounding step (round UP) only on a positive step.
            if ($step > 0 && $bill['total'] > 0) {
                $bill['total'] = RoundingMode::roundUpToStep($bill['total'], $step);
            }

            $bill['is_empty'] = $bill['subtotal'] == 0.0;
        }
        unset($bill);

        // Reconcile rounding drift against order.total_amount.
        // Matches pos-web/src/app/pos/lib/split-by-items.ts:148-182.
        $grossSum = 0.0;
        foreach ($bills as $bill) {
            $grossSum += $bill['total'];
        }
        $hasAnyAllocation = false;
        foreach ($bills as $bill) {
            if (! $bill['is_empty']) {
                $hasAnyAllocation = true;
                break;
            }
        }

        if ($reconcile && $hasAnyAllocation && $orderTotal > 0) {
            $diff = $orderTotal - $grossSum;
            if (abs($diff) > 0.0000001) {
                $lastNonEmptyIdx = -1;
                for ($i = count($bills) - 1; $i >= 0; $i--) {
                    if (! $bills[$i]['is_empty']) {
                        $lastNonEmptyIdx = $i;
                        break;
                    }
                }

                if ($lastNonEmptyIdx >= 0) {
                    $target = &$bills[$lastNonEmptyIdx];
                    $projected = $target['total'] + $diff;
                    if ($projected >= 0) {
                        $target['tax'] += $diff;
                        $target['total'] = $projected;
                    } else {
                        // Negative clamp — push the bill to zero, forward overshoot
                        // to the first non-empty bill if it is different from the last.
                        $overshoot = -$projected;
                        $target['tax'] += -$target['total'];
                        $target['total'] = 0.0;

                        $firstNonEmptyIdx = -1;
                        foreach ($bills as $i => $b) {
                            if (! $b['is_empty']) {
                                $firstNonEmptyIdx = $i;
                                break;
                            }
                        }
                        if ($firstNonEmptyIdx >= 0 && $firstNonEmptyIdx !== $lastNonEmptyIdx) {
                            $other = &$bills[$firstNonEmptyIdx];
                            $other['tax'] -= $overshoot;
                            $other['total'] -= $overshoot;
                            unset($other);
                        }
                    }
                    unset($target);
                }
            }
        }

        // Determine unassigned units per item.
        $unassignedUnits = [];
        foreach ($activeItems as $item) {
            $qty = self::splittableUnits($item);
            $claimed = $claimedByItem[(string) $item->id] ?? 0;
            for ($u = $claimed; $u < $qty; $u++) {
                $unassignedUnits[] = ['item_id' => (string) $item->id, 'unit_index' => $u];
            }
        }

        $totalCheck = 0.0;
        foreach ($bills as $bill) {
            $totalCheck += $bill['total'];
        }

        return [
            'bills' => array_values($bills),
            'unassigned_units' => $unassignedUnits,
            'total_check' => $totalCheck,
        ];
    }

    /**
     * Compute a single sub-check total without producing the full bill set.
     *
     * Used by the validator to recompute the expected `amount` for one
     * by-items payment under the per-order lock.
     */
    public function computeBillTotal(
        CustomerOrder $order,
        array $itemAllocations,
        int $billIndex,
        string $roundingMode,
        ?string $currencyCode,
        float $taxRate,
        float $serviceChargeRate,
        int $peopleCount,
    ): float {
        $shaped = [];
        foreach ($itemAllocations as $a) {
            $shaped[] = [
                'item_id' => (string) ($a['item_id'] ?? ''),
                'units' => (int) ($a['units'] ?? 0),
                'bill_index' => $billIndex,
            ];
        }
        $result = $this->compute(
            $order,
            $shaped,
            $roundingMode,
            $currencyCode,
            $taxRate,
            $serviceChargeRate,
            max($peopleCount, $billIndex + 1),
            reconcile: false,
            pricesIncludeTax: (bool) ($order->is_tax_included ?? false),
        );

        return (float) ($result['bills'][$billIndex]['total'] ?? 0.0);
    }

    /**
     * Số suất CÒN CHIA ĐƯỢC của một dòng — đã trừ phần đã hoàn (#2159).
     *
     * Lọc dòng hoàn khỏi `$activeItems` mới xử được nửa lỗi: suất ma biến mất,
     * nhưng dòng GỐC vẫn giữ `quantity` nguyên vẹn sau khi hoàn một phần
     * (`refundItem` không giảm `quantity`, nó CỘNG vào `refunded_quantity`).
     *
     * Đo được trên đơn 1 dòng qty 2 @¥1.000 đã hoàn 1, thuế 0% — chỉ lọc dòng
     * hoàn, chưa trừ phần đã hoàn:
     *
     *	bill 0: subtotal 2.000 (2 suất) nhưng tổng đơn chỉ 1.000
     *	     ⇒ bước đối soát dồn chênh lệch vào **tax = −1.000**
     *
     * Trừ đi rồi thì 1 suất × 1.000 = 1.000 = tổng đơn ⇒ `tax = 0`, không còn gì
     * để đối soát. Cùng bài học với #2130: chữa NGUỒN của chênh lệch, đừng chữa
     * chỗ nó rơi xuống.
     *
     * `max(1, …)` cũ giữ lại **có điều kiện**: một dòng hoàn hết (`quantity ==
     * refunded_quantity`) phải ra **0** suất, không phải 1 — nếu không nó lại
     * thành đúng suất ma vừa bỏ. Nhưng dòng chưa hoàn gì mà `quantity` là 0
     * hoặc âm (dữ liệu cũ) vẫn giữ tối thiểu 1 như trước, để không đổi hành vi
     * ngoài phạm vi issue này.
     */
    public static function splittableUnits(CustomerOrderItem $item): int
    {
        $refunded = (int) ($item->refunded_quantity ?? 0);
        $quantity = (int) $item->quantity;

        if ($refunded > 0) {
            return max(0, $quantity - $refunded);
        }

        return max(1, $quantity);
    }

    /**
     * Per-unit subtotal.
     *
     * `topping_subtotal` is ALREADY per unit — the column says so
     * (`customer_order_items.topping_subtotal`, "Topping Subtotal (per unit)"),
     * the pricer says so, and every writer stores
     * `subtotal = quantity × (unit_price + topping_subtotal)`.
     *
     * This used to divide it by the line quantity, on the belief that it was a
     * line figure to spread across units. Three implementations agreed on that
     * reading — this one, the workstation's LAN preview and pos-web's — and all
     * three were wrong: a ¥1.000 bowl ×3 with a ¥100 extra priced each unit at
     * ¥1.033 instead of ¥1.100, so a split under-charged every guest except the
     * last, who absorbed the whole ¥200 gap through the final reconcile step.
     * Nothing caught it because not one case in the shared fixture set carried a
     * non-zero `topping_subtotal`; the topping case added alongside this fix is
     * the guard.
     *
     * Kept in step with `workstation/internal/handler/local_pos_phase3.go` and
     * `pos-web/src/app/pos/lib/split-by-items.ts` via
     * `tests/Fixtures/split_by_items_cases.json`.
     */
    private static function perUnitSubtotal(CustomerOrderItem $item): float
    {
        $unit = (float) $item->unit_price;
        $topping = (float) ($item->topping_subtotal ?? 0);

        return $unit + $topping;
    }

    private static function emptyBill(int $index): array
    {
        return [
            'index' => $index,
            'label' => 'Người '.($index + 1),
            'items_breakdown' => [],
            'subtotal' => 0.0,
            'discount' => 0.0,
            'taxable_base' => 0.0,
            'tax' => 0.0,
            'service' => 0.0,
            'total' => 0.0,
            'is_empty' => true,
        ];
    }

    /**
     * Half-up rounding to match TypeScript's Math.round semantics.
     */
    private static function roundHalfUp(float $value): float
    {
        return floor($value + 0.5);
    }
}
