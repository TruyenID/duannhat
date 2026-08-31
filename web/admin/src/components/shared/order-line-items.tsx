"use client";

import type { ReactNode } from "react";
import { useTranslation } from "@/providers/app-provider";
import { formatCurrency } from "@/lib/currency";
import type {
  CustomerOrderItem,
  OrderItemToppingRow,
  ProductOptionValueRef,
} from "@/services/order-service";

export interface OrderLineItemsProps {
  items: CustomerOrderItem[];
  /**
   * Optional per-item status badge. The shop view renders its colocated
   * `OrderItemStatusBadge`; the HQ (read-only) view omits it.
   */
  statusSlot?: (item: CustomerOrderItem) => ReactNode;
  /**
   * ISO 4217 currency of the order the lines belong to (#555 M7). Pass
   * `order.currency`; defaults to JPY when the caller has no order context.
   */
  currencyCode?: string;
  /** Empty-state copy when the order has no lines. */
  emptyLabel?: string;
}

function num(value: number | string | null | undefined): number {
  return Number(value ?? 0);
}

/** Pick the locale-appropriate string from a `{ja,en,vi}` map, with fallbacks. */
function pickLocale(map: Record<string, string> | null | undefined, locale: string): string | null {
  if (!map) return null;
  return map[locale] ?? map.en ?? map.ja ?? Object.values(map)[0] ?? null;
}

/** "Size: M" when the option group is known, else just the value label. */
function optionLabel(opt: ProductOptionValueRef): string {
  const value = opt.label ?? opt.value ?? "";
  const group = opt.option?.name;
  return group ? `${group}: ${value}` : value;
}

export function OrderLineItems({
  items,
  statusSlot,
  currencyCode = "JPY",
  emptyLabel,
}: OrderLineItemsProps) {
  const { t, locale } = useTranslation();
  const money = (v: number | string | null | undefined) =>
    formatCurrency(v, locale, currencyCode);

  if (!items || items.length === 0) {
    return <p className="text-sm text-muted-foreground">{emptyLabel ?? t("order.no_items")}</p>;
  }

  return (
    <div data-slot="order-line-items" className="divide-y">
      {items.map((item) => {
        const sku = item.product_sku;
        const dishName = sku?.product?.name ?? sku?.name ?? sku?.sku ?? item.product_sku_id;

        // Variant: prefer explicit option values; fall back to the SKU name
        // when it carries the variant label and differs from the dish name.
        const optionValues = [sku?.option_value1, sku?.option_value2, sku?.option_value3].filter(
          (o): o is ProductOptionValueRef => Boolean(o)
        );
        const variantFallback =
          optionValues.length === 0 && sku?.name && sku.name !== sku.product?.name
            ? sku.name
            : null;

        const toppings: OrderItemToppingRow[] = item.toppings ?? [];

        // plan-045 — a refund line is an appended negative-quantity clone of an
        // original line (`refund_of_item_id` set). Render it distinctly: qty is
        // shown as a positive count with a "返金 / Refund" badge, and the
        // subtotal renders as a red negative amount.
        const isRefund = item.refund_of_item_id != null;
        const qty = Math.trunc(Math.abs(num(item.quantity)));
        // On an ORIGINAL line, how many of its units have been refunded so far.
        const refundedQty = Math.trunc(num(item.refunded_quantity));

        /**
         * How much of this line's extras the shop gave away.
         *
         * A `free_up_to_n` topping group waives the N most expensive picks and
         * the engine stores only the RESULT, per unit, in `topping_subtotal`.
         * WHICH picks were waived is never persisted (`order_item_toppings`
         * carries the list price and nothing else), so the rows above print at
         * list price and this block over-states by exactly the amount given
         * away — the same defect the printed slip carried.
         *
         * The one figure both stored values agree on is the total waived, so
         * that is what gets its own row. It is DISPLAY ONLY: `item.subtotal`
         * already accounts for the waiver.
         *
         * Stays at 0 — no row — in every shape where the number would be a
         * guess rather than a fact:
         *
         *   · no stored `topping_subtotal` (legacy / unsynced line): the rows
         *     ARE the only figure, so the block already agrees with itself.
         *   · a negative-priced topping: it feeds `topping_subtotal` but shows
         *     no amount above (the row renders only when `unit_price > 0`), so
         *     the difference would not be a waiver.
         *   · a refund line: a mirrored negative clone, where a row labelled
         *     "free toppings" carrying a deduction reads backwards.
         *   · gap ≤ 0: nothing was given away. A "free toppings" row carrying a
         *     surcharge would be a false statement.
         */
        const storedToppingPerUnit = num(item.topping_subtotal);
        const listToppingPerUnit = toppings.reduce(
          (sum, top) => sum + num(top.unit_price) * Math.max(1, Math.trunc(num(top.quantity))),
          0,
        );
        const hasNegativeTopping = toppings.some((top) => num(top.unit_price) < 0);
        const waivedTopping =
          isRefund || hasNegativeTopping || storedToppingPerUnit === 0
            ? 0
            : Math.max(0, listToppingPerUnit - storedToppingPerUnit) * Math.max(1, qty);

        const promo = item.applied_promotion_snapshot;
        const promoName = pickLocale(promo?.name, locale);
        const hasDiscountedPrice =
          !isRefund &&
          item.original_unit_price != null &&
          num(item.original_unit_price) > num(item.unit_price);

        return (
          <div
            key={item.id}
            className={
              isRefund
                ? "border-l-2 border-red-300 bg-red-50/50 py-2.5 pl-2 text-sm dark:border-red-500/40 dark:bg-red-950/20"
                : "py-2.5 text-sm"
            }
          >
            <div className="flex items-start justify-between gap-3">
              <div className="flex flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                {isRefund && (
                  <span className="inline-flex items-center rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 dark:bg-red-900/50 dark:text-red-200">
                    {t("order.refund_badge")}
                  </span>
                )}
                <span className={isRefund ? "font-medium text-red-800 dark:text-red-200" : "font-medium"}>
                  {dishName}
                </span>
                {statusSlot?.(item)}
              </div>
              <div className="flex shrink-0 items-baseline gap-4 text-xs tabular-nums">
                <span className="text-muted-foreground">×{qty}</span>
                <span className="flex items-baseline gap-1">
                  {hasDiscountedPrice && (
                    <span className="text-muted-foreground/60 line-through">
                      {money(item.original_unit_price)}
                    </span>
                  )}
                  <span>{money(item.unit_price)}</span>
                </span>
                {isRefund ? (
                  <span className="w-20 text-right font-medium text-red-600">
                    −{money(Math.abs(num(item.subtotal)))}
                  </span>
                ) : (
                  <span className="w-20 text-right font-medium">{money(item.subtotal)}</span>
                )}
              </div>
            </div>

            {/* Variant / options */}
            {(optionValues.length > 0 || variantFallback) && (
              <div className="mt-1 flex flex-wrap gap-1.5 pl-0.5">
                {variantFallback && (
                  <span className="inline-flex items-center rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground">
                    {variantFallback}
                  </span>
                )}
                {optionValues.map((opt) => (
                  <span
                    key={opt.id}
                    className="inline-flex items-center rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground"
                  >
                    {optionLabel(opt)}
                  </span>
                ))}
              </div>
            )}

            {/* Toppings / modifiers */}
            {toppings.length > 0 && (
              <ul className="mt-1 space-y-0.5 pl-3">
                {toppings.map((top) => {
                  const tPrice = num(top.unit_price);
                  // `top.quantity` counts the extra WITHIN ONE UNIT of the dish
                  // ("2 slices on each bowl"), so the amount shown on this row
                  // has to carry the LINE quantity as well — it sits next to
                  // `item.subtotal`, which is `qty × (unit_price +
                  // topping_subtotal)`, and two figures in different units on
                  // the same block is a block that cannot be checked.
                  //
                  // It could not be. A ¥1.000 bowl ordered ×3 with a ¥100 extra
                  // showed unit ¥1.000, subtotal ¥3.300 and "+¥100": 3 × 1.000
                  // is 3.000, and the ¥100 does not explain the missing ¥300.
                  // A shop reported exactly this (2026-08-13), on this screen
                  // and on the printed slip, which carried the same defect.
                  const totalQty = Math.max(1, Math.trunc(num(top.quantity))) * Math.max(1, qty);
                  return (
                    <li
                      key={top.id}
                      className="flex items-baseline justify-between gap-2 text-xs text-muted-foreground"
                    >
                      <span>
                        + {top.name ?? "—"}
                        {totalQty > 1 && <span className="ml-1">×{totalQty}</span>}
                        {top.note && <span className="ml-1 italic">({top.note})</span>}
                      </span>
                      {tPrice > 0 && (
                        <span className="tabular-nums">+{money(tPrice * totalQty)}</span>
                      )}
                    </li>
                  );
                })}
                {waivedTopping > 0 && (
                  <li
                    className="flex items-baseline justify-between gap-2 text-xs text-emerald-700 dark:text-emerald-400"
                    data-slot="topping-waiver"
                  >
                    <span>{t("order.topping_waived")}</span>
                    <span className="tabular-nums">−{money(waivedTopping)}</span>
                  </li>
                )}
              </ul>
            )}

            {/* Promotion */}
            {promo && (
              <div className="mt-1 pl-0.5">
                <span className="inline-flex items-center gap-1 rounded bg-green-100 px-1.5 py-0.5 text-[11px] font-medium text-green-800">
                  {t("order.promotion")}: {promoName ?? "—"}
                  {promo.discount_percent != null && num(promo.discount_percent) > 0 && (
                    <span>−{num(promo.discount_percent)}%</span>
                  )}
                </span>
              </div>
            )}

            {/* Note (on a refund line this holds the refund reason) */}
            {item.note && (
              <p className="mt-1 pl-0.5 text-xs text-muted-foreground">
                <span className="font-medium">
                  {isRefund ? t("order.refund_reason") : t("order.note")}:
                </span>{" "}
                {item.note}
              </p>
            )}

            {/* plan-045 — refunded-progress hint on an ORIGINAL line that has
                been partially/fully refunded. */}
            {!isRefund && refundedQty > 0 && (
              <p className="mt-1 pl-0.5 text-xs font-medium text-red-600">
                {t("order.refunded_progress", { done: String(refundedQty), total: String(qty) })}
              </p>
            )}
          </div>
        );
      })}
    </div>
  );
}
