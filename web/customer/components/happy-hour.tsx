"use client";

import { useEffect, useState } from "react";
import { Clock } from "lucide-react";
import { useTranslations } from "next-intl";
import type { MenuItem } from "@/data/menu";
import { useCurrency } from "@/lib/currency";
import { useBrand } from "@/context/brand-context";
import { useCart } from "@/context/cart-context";
import { resolveLineRate } from "@/lib/tax";

/**
 * plan-019 — Happy Hour UI atoms shared across the customer menu surfaces
 * (menu-card / menu-list-item / featured-carousel / product-modal) so the
 * strikethrough + discounted-price + badge treatment stays identical.
 *
 * Source of truth for the discount is the backend `active_promotion` block on
 * each MenuItem. The FE only renders it — the actual charge is recomputed and
 * snapshotted by the backend at order time, so showing the discounted price
 * here is purely cosmetic and can never double-apply.
 */

/** Discounted unit price when a promotion is active, else the original price. */
export function effectiveUnitPrice(item: Pick<MenuItem, "price" | "active_promotion">): number {
  const promo = item.active_promotion;
  if (!promo) return item.price;
  if (typeof promo.discounted_price === "number") return promo.discounted_price;
  return Math.round((item.price * (100 - promo.discount_percent)) / 100);
}

/** Apply a promotion's percent to an arbitrary unit price (variant/topping aware). */
export function applyPromotionPercent(
  unitPrice: number,
  promo: MenuItem["active_promotion"],
): number {
  if (!promo) return unitPrice;
  return Math.round((unitPrice * (100 - promo.discount_percent)) / 100);
}

/**
 * #1185 — badge for an item discounted by an active Floating Section. Distinct
 * from HappyHourBadge on purpose: the two can be live at once and a customer
 * needs to see which promo is on the card. The percent is derived from
 * base_price → price rather than sent, because a Floating Section prices each
 * SKU outright instead of applying a rate.
 *
 * Merge note (#1185): the other branch rendered the section NAME here instead.
 * Dropped, because the promo section header already shows that name — the
 * percent is the only thing the badge can add. This version also self-hides
 * when base_price is not actually higher, so a section that "discounts" to the
 * same price shows no badge rather than "-0%".
 */
export function FloatingSectionBadge({ item }: { item: Pick<MenuItem, "price" | "base_price"> }) {
  const base = item.base_price;
  if (base == null || base <= item.price) return null;

  const off = Math.round(((base - item.price) / base) * 100);

  return (
    <span
      className="absolute left-0 top-0 inline-flex items-center gap-1 rounded-br-md px-2 py-1 text-[11px] font-bold uppercase text-white shadow-md"
      style={{ backgroundColor: "#7C3AED" }}
    >
      <Clock className="h-3 w-3 stroke-[3]" aria-hidden />-{off}%
    </span>
  );
}

export function HappyHourBadge({
  discountPercent,
  endsAt,
}: {
  discountPercent: number;
  endsAt: string;
}) {
  const t = useTranslations("checkout");
  const target = new Date(endsAt).getTime();
  const [now, setNow] = useState(() => Date.now());

  useEffect(() => {
    const tick = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(tick);
  }, []);

  const diff = target - now;
  if (diff <= 0) return null;

  // Countdown only within the last hour — an all-day promo would be noisy.
  const showCountdown = diff < 60 * 60 * 1000;
  const hours = Math.floor(diff / (60 * 60 * 1000));
  const minutes = Math.floor((diff % (60 * 60 * 1000)) / (60 * 1000));
  const seconds = Math.floor((diff % (60 * 1000)) / 1000);

  return (
    <div className="absolute left-0 top-0 flex flex-col items-start gap-0.5">
      <span
        className="inline-flex items-center gap-0.5 rounded-br-md px-2 py-1 text-[11px] font-bold uppercase text-white shadow-md"
        style={{ backgroundColor: '#E11D48' }}
      >
        HH -{discountPercent}%
      </span>
      {showCountdown && (
        <span className="rounded bg-black/60 px-1 text-[9px] font-medium text-white ml-1 mt-0.5">
          {hours > 0
            ? t("happyHourCountdown", { hours, minutes })
            : `${minutes}:${String(seconds).padStart(2, "0")}`}
        </span>
      )}
    </div>
  );
}

/**
 * #1185 — khung giờ ưu đãi (floating section) corner badge. Mirrors the
 * HappyHourBadge slot (top-left) but carries the floating section's name
 * instead of a percent. Render only when no promotion badge is present, so
 * the two never overlap.
 */

/**
 * Renders `~~original~~ discounted` when the item is on promotion, else just
 * the plain price. `className` controls the discounted/plain price text size.
 */
export function HappyHourPrice({
  item,
  className,
  strikeClassName,
}: {
  item: Pick<
    MenuItem,
    "price" | "active_promotion" | "tax_rate" | "base_price" | "active_floating_section"
  >;
  className?: string;
  strikeClassName?: string;
}) {
  const { format: fmt } = useCurrency();
  const tMenu = useTranslations("menu");
  const { currentBranch } = useBrand();
  const { orderType } = useCart();

  // issue #1042 — 総額表示: show ONE price + ONE tax-status label. The price
  // shown IS the stored price (whatever the shop entered); the branch's
  // prices_include_tax toggle only drives the LABEL — 税込 when the shop entered
  // tax-included prices, 税抜 when not. The number stays identical between the
  // two modes and matches what checkout charges. The label is hidden when the
  // item has no resolved rate.
  const pricesIncludeTax = currentBranch.prices_include_tax ?? false;
  const rate = resolveLineRate(item as MenuItem, currentBranch);

  const promo = item.active_promotion;
  // #1185 — floating section (khung giờ ưu đãi) also discounts `price`; the
  // backend stamps `base_price` (the pre-discount value) when it does. Show the
  // same struck-through treatment as a promotion. A promotion (if any) takes
  // visual precedence since its price is computed on top.
  const floatingActive =
    !promo &&
    item.active_floating_section != null &&
    item.base_price != null &&
    item.base_price > item.price;
  const effective = promo ? effectiveUnitPrice(item) : item.price;
  // issue #1042 — show the stored price as-is; the toggle only drives the label.
  const shown = effective;
  // Union of both branches: Tri keyed off `active_floating_section`, this side
  // additionally required a REAL discount. Keep both — a floating section that
  // prices an item at its normal price must not render a strikethrough over an
  // identical number.
  const shownOriginal = floatingActive ? (item.base_price as number) : item.price;
  const struck = promo || floatingActive;

  return (
    <span className="inline-flex flex-col gap-0.5">
      <span className="inline-flex items-baseline gap-1.5">
        {struck && (
          <span
            className={"line-through " + (strikeClassName ?? "text-xs")}
            style={{ color: "#E11D48" }}
          >
            {fmt(shownOriginal)}
          </span>
        )}
        <span className={"font-bold " + (className ?? "")} style={{ color: "#171717" }}>
          {fmt(shown)}
        </span>
      </span>
      {rate != null && (
        <span className="text-[10px] font-medium leading-tight text-muted-foreground md:text-sm">
          {pricesIncludeTax ? tMenu("taxIncluded") : tMenu("taxExcluded")}
        </span>
      )}
    </span>
  );
}
