"use client";

/**
 * PromotionBadge + StrikethroughPrice — shared atoms surfaced by the POS
 * menu-catalog (per-product card) and order-cart (per-line item) so the
 * Happy Hour visual treatment stays consistent (plan-019 S5b).
 *
 * The "HH" Badge highlights items inside an active Happy Hour window. The
 * `StrikethroughPrice` pair renders `~~original~~ discounted` for promo
 * items and just `current` otherwise — callers always pass both prices and
 * the helper decides whether to render the strikethrough.
 */

import { Badge, Tooltip, TooltipContent, TooltipTrigger } from "@godxjp/ui";
import { formatTime } from "@/lib/format-date";
import { useTranslation } from "@/providers/app-provider";

interface PromotionBadgeProps {
  discountPercent: number;
  endsAt?: string | null;
  className?: string;
}

export function PromotionBadge({ discountPercent, endsAt, className }: PromotionBadgeProps) {
  const { t, locale } = useTranslation();
  const badge = (
    <Badge
      variant="default"
      data-slot="promotion-badge"
      className={
        "h-5 gap-0.5 bg-amber-500 px-1.5 text-[10px] font-semibold uppercase text-white hover:bg-amber-600 " +
        (className ?? "")
      }
    >
      {t("pos.promo.badge_hh")} −{discountPercent}%
    </Badge>
  );
  if (!endsAt) return badge;
  const endsAtLabel = formatTime(new Date(endsAt), locale, {
    withSeconds: false,
  });
  return (
    <Tooltip>
      <TooltipTrigger asChild>{badge}</TooltipTrigger>
      <TooltipContent>{t("pos.promo.tooltip_until", { time: endsAtLabel })}</TooltipContent>
    </Tooltip>
  );
}

interface StrikethroughPriceProps {
  current: number | string;
  original?: number | string | null;
  formatCurrency: (v: number | string) => string;
  className?: string;
}

export function StrikethroughPrice({
  current,
  original,
  formatCurrency,
  className,
}: StrikethroughPriceProps) {
  const hasDiscount = original != null && Number(original) > Number(current);
  if (!hasDiscount) {
    return <span className={className}>{formatCurrency(current)}</span>;
  }
  return (
    <span className={"inline-flex items-baseline gap-1 " + (className ?? "")}>
      <span className="text-xs text-muted-foreground line-through">
        {formatCurrency(original!)}
      </span>
      <span className="font-medium text-amber-600">{formatCurrency(current)}</span>
    </span>
  );
}
