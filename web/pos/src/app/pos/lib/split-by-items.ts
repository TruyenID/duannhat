/**
 * Plan-021 / plan-043 — split-bill by-items calculator.
 *
 * Pure function. No React, no service imports. Inputs:
 *   - order: snapshot from CustomerOrderResource
 *   - taxRate / serviceRate: percent (e.g. 8 means 8 %) — the LEGACY single
 *     rate, used only as a fallback for lines that carry no per-line
 *     `tax_rate` snapshot (pre-plan-043 / unstamped orders).
 *   - currency / roundingMode: drive the currency rounding step (mirror of
 *     backend `RoundingMode::step`). Default to the active session currency +
 *     `auto` so the per-payment `split_by_items_total_mismatch` guard agrees.
 *   - pricesIncludeTax: 総額表示 (内税) mode — tax is extracted from the price
 *     rather than added on top. Defaults to `order.is_tax_included`.
 *   - state: { people, allocations } from SplitBillByItemsTab
 *
 * Output: per-person bills with subtotal/discount/tax/service/total + a
 * per-bill per-rate `taxBreakdown`, the list of still-unassigned units (for
 * the warning counter), and a `totalCheck` that the dialog can sanity-test
 * against `order.total_amount`.
 *
 * plan-043 — each bill groups its OWN items by their snapshot `tax_rate` and
 * rounds tax ONCE per rate group (端数処理は税率ごとに1回, インボイス), so a
 * mixed-rate bill (bentō 8% + beer 10%) is taxed correctly. Coupon discount is
 * allocated pro-rata per group. A single-rate bill reproduces the pre-plan-043
 * numbers (step=1 half-up == the old integer round for JPY/VND).
 *
 * Bit-for-bit parity with backend `SplitByItemsCalculator::compute` +
 * `computeBillTotal(reconcile: false)` — the per-payment
 * `split_by_items_total_mismatch` guard recomputes each bill from raw
 * order.subtotal + order.discount_amount + item.unit_price with NO
 * reconciliation, then rejects if the FE-quoted amount differs by more than
 * `max(currency_step, 0.01)` (~1 JPY). Reconciling the FE total into the last
 * bill would drift from the guard and the payment would fail — so this
 * calculator does NOT reconcile (the BE overpay guard clamps the final ≤1-unit
 * drift to exact outstanding).
 */

import { getActiveCurrency } from "./totals";
import { perUnitPrice } from "./line-total";
import type {
  CustomerOrder,
  CustomerOrderItem,
  ItemAllocation,
  PersonBill,
  SplitByItemsResult,
  SplitByItemsState,
  TaxBreakdownEntry,
} from "../types";

interface CalculatorInputs {
  order: CustomerOrder;
  taxRate: number;
  serviceRate: number;
  state: SplitByItemsState;
  /**
   * Rounding mode from `ShopOrderSetting.split_bill_rounding_mode`. Mirrors the
   * backend guard's default (`auto`). Combined with `currency` to derive the
   * per-bill rounding step (`roundingStep`).
   */
  roundingMode?: "auto" | "integer" | "two_decimals" | "none";
  /** ISO 4217 code. Defaults to the active session currency. */
  currency?: string | null;
  /** 総額表示 (内税) mode. Defaults to `order.is_tax_included ?? false`. */
  pricesIncludeTax?: boolean;
  /**
   * Units per `CustomerOrderItem.id` already claimed by prior succeeded /
   * pending payments on this order. When present, those units are excluded
   * from the palette + reconciliation so a reopened modal on a partially-
   * paid order shows only the balance still owed. Absent / empty on a
   * fresh order — the calculator falls back to raw `item.quantity`.
   */
  paidUnitsByItem?: Record<string, number>;
}

// ---------------------------------------------------------------------------
//  Rounding — mirror of backend App\Support\RoundingMode
// ---------------------------------------------------------------------------

/** Currencies whose smallest accountable unit is the integer. */
const ZERO_FRACTION = new Set([
  "JPY", "VND", "KRW", "IDR", "LAK", "MMK", "UGX", "RWF", "BIF", "KMF",
  "PYG", "CLP", "COP", "GNF", "VUV", "XOF", "XAF", "XPF",
]);

/** Currencies whose smallest accountable unit is 0.001. */
const THREE_DECIMAL = new Set(["KWD", "BHD", "OMR", "JOD", "TND", "IQD"]);

/**
 * Resolve the rounding step for a (mode, currency) pair — mirrors
 * `RoundingMode::step`.
 */
function roundingStep(
  mode: CalculatorInputs["roundingMode"],
  currencyCode: string | null | undefined,
): number {
  const m = mode || "auto";
  const currency = (currencyCode || "VND").toUpperCase();
  switch (m) {
    case "integer":
      return 1;
    case "two_decimals":
      return 0.01;
    case "none":
      return 0;
    default:
      if (ZERO_FRACTION.has(currency)) return 1;
      if (THREE_DECIMAL.has(currency)) return 0.001;
      return 0.01;
  }
}

/**
 * plan-043 Decision 4 — the ONE rounding rule for consumption tax: half-up to
 * the currency step. Mirrors `RoundingMode::roundHalfUpToStep`. step 0 (mode
 * `none` / sub-unit currencies) falls back to 0.01 so tax is representable.
 *
 * `floor(x / step + 0.5) * step` reproduces PHP's `floor(x + 0.5)` when
 * step = 1, which is `Math.round` half-up for the non-negative tax values the
 * engine feeds it.
 */
function roundHalfUpToStep(value: number, step: number): number {
  const effectiveStep = step > 0 ? step : 0.01;
  // Guard float drift on the division (e.g. 79.99999999) before flooring.
  const scaled = value / effectiveStep;
  return Math.floor(scaled + 0.5 + 1e-9) * effectiveStep;
}

/**
 * Build an empty PersonBill placeholder. Labels follow the spec's default
 * Vietnamese form — i18n at render time can map the index back to a label
 * in the active locale; the calculator just emits "Người N" so the audit
 * row that goes to the backend has a deterministic value (used by
 * SplitBillReceiptDialog reprint flow regardless of locale).
 */
function emptyBill(index: number): PersonBill {
  return {
    index,
    label: `Người ${index + 1}`,
    itemsBreakdown: [],
    subtotal: 0,
    discount: 0,
    taxableBase: 0,
    tax: 0,
    service: 0,
    total: 0,
    taxBreakdown: [],
    isEmpty: true,
  };
}

/**
 * Per-unit subtotal.
 *
 * `topping_subtotal` is ALREADY per unit — see `CustomerOrderItem` in
 * `../types`, the DB column comment, and every writer, which all store
 * `subtotal = quantity × (unit_price + topping_subtotal)`.
 *
 * This used to divide by the line quantity, spreading one helping of extras
 * across all the units. A ¥1.000 bowl ×3 with a ¥100 extra priced each unit at
 * ¥1.033 instead of ¥1.100, so a split under-charged every guest except the
 * last, who absorbed the whole ¥200 gap through the final reconcile step. Cloud
 * (`SplitByItemsCalculator::perUnitSubtotal`) and the workstation's LAN preview
 * carried the same division; all three are fixed together and pinned by the
 * topping case in the shared fixture set.
 *
 * It still loses fidelity when staff want "only unit 1 has syrup" (documented
 * Q1) — the data model carries no per-unit topping, so every unit of a line
 * costs the same. That is a modelling limit, not an averaging rule.
 */
function perUnitSubtotal(item: CustomerOrderItem): number {
  return perUnitPrice(item.unit_price, item.topping_subtotal);
}

/**
 * Resolve a line's snapshot tax rate. A line with no snapshot rate (legacy /
 * unstamped) falls back to the passed legacy `taxRate` — this keeps
 * single-rate orders identical to the pre-plan-043 behaviour.
 */
function lineRate(item: CustomerOrderItem, legacyRate: number): number {
  return item.tax_rate != null && item.tax_rate !== ""
    ? Number(item.tax_rate)
    : legacyRate;
}

/**
 * Read CustomerOrderItem.id as a stable string key. Defensive — the wire
 * shape stringifies, but TS lets it be either.
 */
function itemKey(item: CustomerOrderItem): string {
  return String(item.id);
}

export function splitByItems({
  order,
  taxRate,
  serviceRate,
  state,
  roundingMode = "auto",
  currency,
  pricesIncludeTax,
  paidUnitsByItem = {},
}: CalculatorInputs): SplitByItemsResult {
  const orderSubtotal = Number(order.subtotal ?? 0);
  const orderDiscount = Number(order.discount_amount ?? 0);
  const includeTax = pricesIncludeTax ?? Boolean(order.is_tax_included);
  const step = roundingStep(roundingMode, currency ?? getActiveCurrency());

  // #2159 — dòng HOÀN không phải một suất để chia, và dòng đã hoàn một phần
  // không còn đủ suất như `quantity` nói.
  //
  // `refundItem` phụ thêm một dòng `quantity` ÂM mang `refund_of_item_id`, rồi
  // CỘNG vào `refunded_quantity` của dòng gốc — nó KHÔNG giảm `quantity`.
  //
  // Dòng hoàn trước đây "biến mất" khỏi palette một cách TÌNH CỜ:
  // `Math.max(0, Math.floor(-1))` = 0 ⇒ `qty === 0` ⇒ `continue`. Lọc tường
  // minh ở đây vì dựa vào tai nạn số học ấy nghĩa là một dòng hoàn nhiều suất
  // hay một lần đổi `Math.max` sẽ lặng lẽ mở lại nó.
  const items = (order.items ?? []).filter(
    (i) =>
      i.status !== "voided" &&
      (i.refund_of_item_id === null || i.refund_of_item_id === undefined),
  );

  // Still-owed quantities per item — pure display filter so items entirely
  // covered by earlier payments disappear from the palette. Does NOT feed
  // into the bill-total formulas (those must match BE which uses raw
  // orderSubtotal/orderDiscount) — only into slot iteration + unassigned
  // counting so the UI mirrors what the guard will accept.
  const effectiveQty = new Map<string, number>();
  for (const item of items) {
    const key = itemKey(item);
    const rawQty = Math.max(0, Math.floor(Number(item.quantity)));
    const paid = Math.max(0, paidUnitsByItem[key] ?? 0);
    // #2159 — suất đã HOÀN TIỀN cũng không còn để chia. Không trừ ra thì suất
    // ấy được đem chia cho một khách nữa và thu tiền lần thứ hai cho món đã
    // trả lại — trong khi BE (`SplitByItemsCalculator::splittableUnits`) đã
    // kẹp ở `quantity − refunded_quantity`, nên hai bên còn bất đồng về việc
    // đã chia xong hay chưa.
    const refunded = Math.max(0, Math.floor(Number(item.refunded_quantity ?? 0)));
    effectiveQty.set(key, Math.max(0, rawQty - paid - refunded));
  }

  const people = Math.max(0, Math.floor(state.people));
  const bills: PersonBill[] = Array.from({ length: people }, (_, i) =>
    emptyBill(i),
  );

  // 1. Walk allocations → per-unit assignment → per-bill itemsBreakdown.
  //    Same-item entries on the same bill are compacted (`Phở × 2` not 2 rows).
  const unassignedUnits: Array<{ itemId: string; unitIndex: number }> = [];

  for (const item of items) {
    const key = itemKey(item);
    const qty = effectiveQty.get(key) ?? 0;
    if (qty === 0) continue; // fully paid earlier — nothing to split here
    const alloc: ItemAllocation | undefined = state.allocations[key];
    // Only iterate over the effective slots — a stale allocation array
    // longer than `qty` is trimmed defensively, shorter is padded with
    // nulls so the unassigned counter surfaces the missing slots.
    const source = alloc?.units ?? Array.from({ length: qty }, () => null);
    const bounded: Array<number | null> = source.slice(0, qty);
    while (bounded.length < qty) bounded.push(null);

    bounded.forEach((personIdx, unitIndex) => {
      if (personIdx === null || personIdx === undefined) {
        unassignedUnits.push({ itemId: key, unitIndex });
        return;
      }
      if (personIdx < 0 || personIdx >= bills.length) {
        // Stale assignment to a no-longer-existing person — treat as
        // unassigned so the UI surfaces it rather than silently dropping.
        unassignedUnits.push({ itemId: key, unitIndex });
        return;
      }
      const bill = bills[personIdx]!;
      const existing = bill.itemsBreakdown.find((b) => itemKey(b.item) === key);
      if (existing) {
        existing.units += 1;
        existing.subtotal += perUnitSubtotal(item);
      } else {
        bill.itemsBreakdown.push({
          item,
          units: 1,
          subtotal: perUnitSubtotal(item),
        });
      }
    });
  }

  // 2. Per-bill totals — mirror backend SplitByItemsCalculator::compute.
  //    Discount is prorated against RAW orderSubtotal + RAW orderDiscount
  //    (the guard uses the same), then each bill groups its own items by
  //    snapshot tax_rate, allocates the bill's discount pro-rata per group,
  //    and taxes each group ONCE (half-up to step). No reconciliation — see
  //    the header note.
  for (const bill of bills) {
    // Σ per-rate subtotal within this bill.
    const rateGroups = new Map<number, number>();
    for (const entry of bill.itemsBreakdown) {
      const rate = lineRate(entry.item, taxRate);
      rateGroups.set(rate, (rateGroups.get(rate) ?? 0) + entry.subtotal);
    }

    bill.subtotal = Math.round(
      bill.itemsBreakdown.reduce((sum, b) => sum + b.subtotal, 0),
    );
    const ratio = orderSubtotal > 0 ? bill.subtotal / orderSubtotal : 0;
    bill.discount = Math.round(orderDiscount * ratio);
    bill.taxableBase = Math.max(0, bill.subtotal - bill.discount);

    // Per-rate tax within the bill — coupon share allocated pro-rata per
    // group, then rounded once per group (half-up to step).
    const breakdown: TaxBreakdownEntry[] = [];
    let billTax = 0;
    // Sort by rate ascending for a stable display order (matches BE ksort).
    const sortedRates = Array.from(rateGroups.keys()).sort((a, b) => a - b);
    for (const rate of sortedRates) {
      const groupSubtotal = rateGroups.get(rate)!;
      const groupDiscount =
        bill.subtotal > 0
          ? (bill.discount * groupSubtotal) / bill.subtotal
          : 0;
      const groupNet = Math.max(0, groupSubtotal - groupDiscount);
      const groupTax = includeTax
        ? groupNet - roundHalfUpToStep(groupNet / (1 + rate / 100), step)
        : roundHalfUpToStep((groupNet * rate) / 100, step);
      const taxable = includeTax ? groupNet - groupTax : groupNet;
      billTax += groupTax;
      breakdown.push({ rate, taxable, tax: groupTax });
    }

    bill.tax = billTax;
    bill.taxBreakdown = breakdown;
    bill.service = roundHalfUpToStep((bill.taxableBase * serviceRate) / 100, step);
    // Included mode: tax is already inside the prices (内税) → not added.
    bill.total = includeTax
      ? bill.taxableBase + bill.service
      : bill.taxableBase + bill.tax + bill.service;
    bill.isEmpty = bill.subtotal === 0;
  }

  // No reconciliation — see the header note. Σ bills may drift from
  // `order.remaining_amount` by ±1 unit due to per-bill rounding; the
  // BE overpay guard tolerates a 1-unit overshoot on the final payment
  // (clamps to exact outstanding), and an undershoot leaves ¥1 that the
  // last cashier can settle with a follow-up payment.

  return {
    bills,
    unassignedUnits,
    totalCheck: bills.reduce((s, b) => s + b.total, 0),
  };
}

/**
 * Completion gate for the by-items tab: is the WHOLE order split AND settled?
 *
 * True ONLY when all three hold:
 *   1. at least one non-empty bill exists,
 *   2. EVERY order unit is assigned to someone (no leftover units), and
 *   3. EVERY non-empty bill has been paid.
 *
 * Condition (2) — `unassignedUnitsCount === 0` — is the fix for the
 * premature-completion bug: paying the first person while later people's items
 * are still unassigned must NOT report the order as done (which would fire the
 * receipt/"completion" screen and close the order tab, stranding the rest).
 * Both the footer "done" button and the onAllRowsPaid effect derive from this
 * single source of truth so they can never diverge again.
 */
export function areAllByItemsBillsSettled(params: {
  bills: PersonBill[];
  unassignedUnitsCount: number;
  isBillPaid: (billIndex: number) => boolean;
}): boolean {
  const nonEmpty = params.bills.filter((b) => !b.isEmpty);
  if (nonEmpty.length === 0) return false;
  if (params.unassignedUnitsCount > 0) return false;
  return nonEmpty.every((b) => params.isBillPaid(b.index));
}
