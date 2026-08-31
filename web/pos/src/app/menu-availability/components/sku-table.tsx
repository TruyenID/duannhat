/**
 * plan-056 — the variant rows under one dish.
 *
 * Price is READ-ONLY here and there is deliberately no control that edits it:
 * price lives in admin-web behind `MenuPolicy::shopUpdatePrice` (Manager and
 * above), while availability is `shopUpdateAvailability` (any shop staff). The
 * two numbers sit side by side so a cashier can tell "Nhỏ" from "Lớn" at a
 * glance — that is the only reason they are on screen.
 */

import { Switch } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { cn } from "@/lib/utils";
import type { AvailabilitySku } from "@/services/menu-availability-service";
// The SAME formatter the sales screen uses. A second money formatter is a
// second set of rounding and currency-symbol rules to keep in step, and the
// two would drift on the first shop that is not in JPY.
import { formatCurrency } from "@/app/pos/lib/totals";

export interface AvailabilitySkuTableProps {
  skus: AvailabilitySku[];
  /** A dish that is off greys its variants and locks their switches. */
  parentActive: boolean;
  pendingSkuId: string | null;
  onToggle: (sku: AvailabilitySku, next: boolean) => void;
}

export function AvailabilitySkuTable({
  skus,
  parentActive,
  pendingSkuId,
  onToggle,
}: AvailabilitySkuTableProps) {
  const { t } = useTranslation();

  if (skus.length === 0) {
    return (
      <p className="px-3 py-2 text-xs text-muted-foreground">
        {t("menu_availability.no_variants")}
      </p>
    );
  }

  return (
    <div
      className="overflow-x-auto rounded-md border bg-background"
      data-slot="availability-sku-table"
    >
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b text-xs text-muted-foreground">
            <th className="px-3 py-2 text-left font-medium">
              {t("menu_availability.col.variant")}
            </th>
            <th className="px-3 py-2 text-left font-medium">
              {t("menu_availability.col.sku_code")}
            </th>
            <th className="px-3 py-2 text-right font-medium">
              {t("menu_availability.col.price")}
            </th>
            <th className="w-24 px-3 py-2 text-right font-medium">
              {t("menu_availability.col.status")}
            </th>
          </tr>
        </thead>
        <tbody>
          {skus.map((sku) => {
            // A variant HQ added after the branch cloned its menu has no pivot
            // row, so there is no address on Cloud to write a toggle back to.
            // It IS on sale; it simply cannot be switched from here yet. The
            // switch is disabled with a reason rather than hidden, so the row
            // does not look broken.
            const notYetTogglable = sku.menu_product_sku_id == null;
            const disabled =
              !parentActive ||
              notYetTogglable ||
              pendingSkuId === sku.menu_product_sku_id;

            return (
              <tr
                key={sku.product_sku_id}
                className={cn(
                  "border-b last:border-0",
                  (!sku.is_active || !parentActive) && "opacity-55",
                )}
                data-testid={`availability-sku-${sku.product_sku_id}`}
              >
                <td className="px-3 py-2">
                  <span className="font-medium">
                    {/* `variant_label` first (the option axis, e.g. "Lớn"),
                        then the SKU's own name, then its CODE. The code is a
                        real identifier a cashier can match against a package —
                        a generic "(variant)" placeholder is not, and a column
                        of them is what made this table unreadable. */}
                    {sku.variant_label || sku.name || sku.sku || "—"}
                  </span>
                  {!sku.is_active && sku.disabled_reason && (
                    <span className="mt-0.5 block text-[11px] text-destructive">
                      {sku.disabled_reason}
                    </span>
                  )}
                </td>
                <td className="px-3 py-2">
                  <code className="rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground">
                    {sku.sku || "—"}
                  </code>
                </td>
                <td className="px-3 py-2 text-right tabular-nums">
                  {formatCurrency(sku.selling_price)}
                  {sku.is_price_overridden && sku.default_price != null && (
                    <span className="ml-1.5 text-[11px] text-muted-foreground line-through">
                      {formatCurrency(sku.default_price)}
                    </span>
                  )}
                </td>
                <td className="px-3 py-2 text-right">
                  <Switch
                    checked={sku.is_active && parentActive}
                    disabled={disabled}
                    onCheckedChange={(next: boolean) => onToggle(sku, next)}
                    aria-label={t("menu_availability.toggle_variant_aria", {
                      name: sku.name || sku.sku || "",
                    })}
                    title={
                      notYetTogglable
                        ? t("menu_availability.variant_not_togglable")
                        : undefined
                    }
                    data-testid={`availability-sku-switch-${sku.product_sku_id}`}
                  />
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
