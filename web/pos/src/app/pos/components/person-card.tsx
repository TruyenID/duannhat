/**
 * PersonCard — per-person summary on the right pane of
 * SplitBillByItemsTab. Renders the items breakdown table followed by
 * subtotal / discount / tax / service / total — but only rows with a
 * non-zero value so empty bills collapse to "Chưa gán món".
 *
 * Props mirror DESIGN.md S3 (plan-021). Stateless, presentation-only —
 * all derivation lives in `splitByItems()`.
 */

import { Badge, Button, Card } from "@godxjp/ui";
import { XIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/providers/app-provider";
import type { PersonBill } from "../types";
import { formatCurrency } from "../lib/totals";
import { orderItemDisplayName } from "../lib/order-item-name";

export interface PersonCardProps {
  bill: PersonBill;
  /** Disable the X button (e.g. when this bill has items assigned). */
  removable: boolean;
  onRemove?: () => void;
  /**
   * Click handler for the card header. When set, lets the parent toggle
   * "bulk assign mode" (planned UX — DESIGN.md S2). Currently used by
   * the by-items tab to highlight the active assignee.
   */
  onHeaderClick?: () => void;
  /** Visual cue that this card is the current bulk-assign target. */
  isActive?: boolean;
}

export function PersonCard({
  bill,
  removable,
  onRemove,
  onHeaderClick,
  isActive,
}: PersonCardProps) {
  const { t } = useTranslation();

  return (
    <Card
      data-slot="person-card"
      className={cn(
        "border-border/70 bg-background flex flex-col gap-2 rounded-xl border p-3",
        isActive && "ring-primary/40 ring-2"
      )}
    >
      <header
        className={cn("flex items-center justify-between gap-2", onHeaderClick && "cursor-pointer")}
        onClick={onHeaderClick}
      >
        <div className="flex items-center gap-2">
          <Badge variant="secondary" className="h-5 px-1.5 text-[10px] font-semibold tabular-nums">
            {bill.label}
          </Badge>
          {bill.isEmpty && (
            <span className="text-muted-foreground text-xs">
              {t("pos.split_bill.by_items.empty_person")}
            </span>
          )}
        </div>
        {onRemove && (
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-6 shrink-0"
            disabled={!removable}
            onClick={(e) => {
              e.stopPropagation();
              onRemove();
            }}
            aria-label={t("pos.split_bill.by_items.remove_person")}
            title={
              removable
                ? t("pos.split_bill.by_items.remove_person")
                : t("pos.split_bill.by_items.cannot_remove_person_with_items")
            }
          >
            <XIcon className="size-3.5" />
          </Button>
        )}
      </header>

      {bill.itemsBreakdown.length > 0 && (
        <ul className="space-y-0.5 text-xs">
          {bill.itemsBreakdown.map((entry) => (
            <li
              key={entry.item.id}
              className="flex items-center justify-between gap-2 tabular-nums"
            >
              <span className="truncate">
                {orderItemDisplayName(entry.item, entry.item.product_sku_id)}
                <span className="text-muted-foreground ml-1">× {entry.units}</span>
              </span>
              <span className="tabular-nums">{formatCurrency(entry.subtotal)}</span>
            </li>
          ))}
        </ul>
      )}

      {!bill.isEmpty && (
        <div className="border-border/40 mt-1 space-y-0.5 border-t pt-1.5 text-xs">
          <Row label={t("pos.split_bill.by_items.subtotal_label")} value={bill.subtotal} />
          {bill.discount > 0 && (
            <Row label={t("pos.split_bill.by_items.discount_label")} value={-bill.discount} muted />
          )}
          {/* plan-043 — per-rate tax rows (8%対象 / 10%対象). A single-rate
              bill collapses to one row; a mixed-rate bill shows each group. */}
          {bill.taxBreakdown
            .filter((g) => g.tax > 0)
            .map((g) => (
              <Row
                key={g.rate}
                label={t("pos.split_bill.by_items.tax_rate_label", { rate: g.rate })}
                value={g.tax}
                muted
              />
            ))}
          {bill.service > 0 && (
            <Row label={t("pos.split_bill.by_items.service_label")} value={bill.service} muted />
          )}
          <Row label={t("pos.split_bill.by_items.total_label")} value={bill.total} emphasis />
        </div>
      )}
    </Card>
  );
}

function Row({
  label,
  value,
  muted,
  emphasis,
}: {
  label: string;
  value: number;
  muted?: boolean;
  emphasis?: boolean;
}) {
  return (
    <div
      className={cn(
        "flex items-center justify-between tabular-nums",
        muted && "text-muted-foreground",
        emphasis && "border-border/30 border-t pt-1 text-sm font-semibold"
      )}
    >
      <span>{label}</span>
      <span>{formatCurrency(value)}</span>
    </div>
  );
}
