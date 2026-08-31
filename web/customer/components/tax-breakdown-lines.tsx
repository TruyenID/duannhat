"use client";

// plan-043 T5.4 — renders per-rate consumption-tax lines (8%対象 / 10%対象)
// from an order's server-computed `tax_breakdown`, with 総額表示 (税込) vs
// tax-excluded framing. Shared by the dine-in payment/summary views and the
// account order-detail view so every customer surface renders インボイス-style
// per-rate blocks identically.

import { useTranslations } from "next-intl";
import type { TaxBreakdownRow } from "@/lib/tax";

interface Props {
  breakdown: TaxBreakdownRow[] | undefined | null;
  /** true = prices already include tax (総額表示) → show 内消費税 framing. */
  isTaxIncluded?: boolean;
  format: (value: number) => string;
  /** i18n namespace to pull the shared per-rate labels from. */
  namespace:
    | "checkout"
    | "dineIn"
    | "dineInPayment"
    | "orderDetail"
    | "account"
    | "guestOrders";
  className?: string;
}

/**
 * A per-rate group is "reduced" (gets the ※ marker + footnote) when its rate
 * is below the maximum rate present in the breakdown — i.e. the 8% group when
 * a 10% group also exists. Single-rate orders never mark.
 */
export function TaxBreakdownLines({
  breakdown,
  isTaxIncluded,
  format,
  namespace,
  className,
}: Props) {
  const t = useTranslations(namespace);

  if (!breakdown || breakdown.length === 0) return null;

  const rows = breakdown.filter((r) => r.rate > 0 || r.tax !== 0);
  if (rows.length === 0) return null;

  const maxRate = Math.max(...rows.map((r) => r.rate));
  const hasReduced = rows.some((r) => r.rate > 0 && r.rate < maxRate);

  return (
    <div className={className}>
      {rows.map((row) => {
        const reduced = row.rate > 0 && row.rate < maxRate;
        return (
          <div
            key={row.rate}
            className="flex items-center justify-between gap-3 text-xs md:text-sm"
          >
            <span className="min-w-0 flex-1 text-muted-foreground">
              {t("taxRateLine", { rate: row.rate })}
              {reduced ? ` ${t("reducedRateMark")}` : ""}
              {` (${format(isTaxIncluded ? row.taxable + row.tax : row.taxable)})`}
              {isTaxIncluded ? ` — ${t("taxIncludedInline")}` : ""}
            </span>
            <span className="shrink-0 font-medium tabular-nums">
              {format(row.tax)}
            </span>
          </div>
        );
      })}
      {hasReduced && (
        <p className="mt-1 text-[11px] text-muted-foreground">
          {t("reducedRateFootnote")}
        </p>
      )}
    </div>
  );
}
