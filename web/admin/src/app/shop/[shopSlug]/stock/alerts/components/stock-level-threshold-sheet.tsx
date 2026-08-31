"use client";

/**
 * Plan-024 — StockLevelThresholdSheet
 *
 * Inline threshold-edit sheet opened from the stock-alerts page action
 * dropdown. Submits via the existing useUpdateStockLevel mutation
 * (PUT /stock-levels/{id}). Backend auto-resolves the active alert when
 * the new threshold drops below current quantity (StockLevelService
 * reEvaluateActiveAlertForLevel).
 *
 * Form fields (Decision 6 / DESIGN.md S2):
 *   - alert_enabled (master switch, first)
 *   - min_stock
 *   - max_stock
 *
 * Validation:
 *   - min_stock and max_stock must be >= 0
 *   - min_stock <= max_stock when both set
 *
 * The sheet expects the caller to supply the StockLevel id (resolved
 * server-side in StockAlertResource as `stock_level_id`). When that
 * field is null the sheet should not be opened — the alerts page
 * hides the action item in that case.
 */

import { useEffect, useState } from "react";
import {
  Button,
  Input,
  Sheet,
  SheetClose,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
  Spinner,
  Switch,
} from "@godxjp/ui";
import { useUpdateStockLevel } from "@/hooks/api/use-stock-levels";
import { useTranslation } from "@/providers/app-provider";

export interface StockLevelThresholdSheetProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  shopSlug: string;
  stockLevelId: string;
  itemName: string;
  warehouseName: string;
  currentQuantity: number;
  unit?: string | null;
  initialMinStock?: string | null;
  initialMaxStock?: string | null;
  initialAlertEnabled?: boolean;
}

export function StockLevelThresholdSheet({
  open,
  onOpenChange,
  shopSlug,
  stockLevelId,
  itemName,
  warehouseName,
  currentQuantity,
  unit,
  initialMinStock,
  initialMaxStock,
  initialAlertEnabled = true,
}: StockLevelThresholdSheetProps) {
  const { t } = useTranslation();
  const update = useUpdateStockLevel(shopSlug);

  const [alertEnabled, setAlertEnabled] = useState(initialAlertEnabled);
  const [minStock, setMinStock] = useState(initialMinStock ?? "");
  const [maxStock, setMaxStock] = useState(initialMaxStock ?? "");

  // Reset state when the sheet opens for a different alert row.
  useEffect(() => {
    if (open) {
      setAlertEnabled(initialAlertEnabled);
      setMinStock(initialMinStock ?? "");
      setMaxStock(initialMaxStock ?? "");
    }
  }, [open, initialMinStock, initialMaxStock, initialAlertEnabled]);

  const minNum = minStock === "" ? null : Number(minStock);
  const maxNum = maxStock === "" ? null : Number(maxStock);

  const minInvalid = minNum !== null && (Number.isNaN(minNum) || minNum < 0);
  const maxInvalid = maxNum !== null && (Number.isNaN(maxNum) || maxNum < 0);
  const rangeInvalid = minNum !== null && maxNum !== null && minNum > maxNum;

  const hasChanges =
    alertEnabled !== initialAlertEnabled ||
    minStock !== (initialMinStock ?? "") ||
    maxStock !== (initialMaxStock ?? "");

  const canSubmit = hasChanges && !minInvalid && !maxInvalid && !rangeInvalid && !update.isPending;

  async function handleSave() {
    try {
      await update.mutateAsync({
        id: stockLevelId,
        data: {
          min_stock: minNum,
          max_stock: maxNum,
          alert_enabled: alertEnabled,
        },
      });
      onOpenChange(false);
    } catch {
      /* toast fired by hook */
    }
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent data-slot="stock-level-threshold-sheet" side="right" className="w-[420px]">
        <SheetHeader>
          <SheetTitle>{t("shop.stock.alerts.threshold_sheet.title")}</SheetTitle>
          <SheetDescription>{t("shop.stock.alerts.threshold_sheet.desc")}</SheetDescription>
        </SheetHeader>

        <div className="space-y-4 px-4 py-3">
          {/* Read-only context */}
          <div className="space-y-1.5 rounded-md border bg-muted/30 p-3 text-xs">
            <div>
              <span className="text-muted-foreground">
                {t("shop.stock.alerts.threshold_sheet.warehouse")}:
              </span>{" "}
              <span className="font-medium">{warehouseName}</span>
            </div>
            <div>
              <span className="text-muted-foreground">
                {t("shop.stock.alerts.threshold_sheet.item")}:
              </span>{" "}
              <span className="font-medium">{itemName}</span>
            </div>
            <div>
              <span className="text-muted-foreground">
                {t("shop.stock.alerts.threshold_sheet.current_quantity")}:
              </span>{" "}
              <span className="font-medium">
                {currentQuantity}
                {unit ? ` ${unit}` : ""}
              </span>
            </div>
          </div>

          {/* Master switch */}
          <div className="flex items-center justify-between gap-3 rounded-md border p-3">
            <div className="space-y-0.5">
              <label className="text-sm font-medium">
                {t("shop.stock.alerts.threshold_sheet.alert_enabled")}
              </label>
              <p className="text-xs text-muted-foreground">
                {t("shop.stock.alerts.threshold_sheet.alert_enabled_hint")}
              </p>
            </div>
            <Switch checked={alertEnabled} onCheckedChange={setAlertEnabled} />
          </div>

          {/* min_stock */}
          <div className="space-y-1">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.stock.alerts.threshold_sheet.min_stock")}
            </label>
            <Input
              type="number"
              min="0"
              step="0.01"
              value={minStock}
              onChange={(e) => setMinStock(e.target.value)}
              placeholder={t("shop.stock.alerts.threshold_sheet.min_stock_placeholder")}
              className="h-9 text-sm"
              aria-invalid={minInvalid || rangeInvalid}
              disabled={!alertEnabled}
            />
            {minInvalid && (
              <span className="text-xs text-destructive">
                {t("shop.stock.alerts.threshold_sheet.invalid_negative")}
              </span>
            )}
            {!minInvalid && (
              <span className="text-xs text-muted-foreground">
                {t("shop.stock.alerts.threshold_sheet.min_stock_hint")}
              </span>
            )}
          </div>

          {/* max_stock */}
          <div className="space-y-1">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.stock.alerts.threshold_sheet.max_stock")}
            </label>
            <Input
              type="number"
              min="0"
              step="0.01"
              value={maxStock}
              onChange={(e) => setMaxStock(e.target.value)}
              placeholder={t("shop.stock.alerts.threshold_sheet.max_stock_placeholder")}
              className="h-9 text-sm"
              aria-invalid={maxInvalid || rangeInvalid}
              disabled={!alertEnabled}
            />
            {maxInvalid && (
              <span className="text-xs text-destructive">
                {t("shop.stock.alerts.threshold_sheet.invalid_negative")}
              </span>
            )}
          </div>

          {rangeInvalid && (
            <p className="text-xs text-destructive">
              {t("shop.stock.alerts.threshold_sheet.invalid_range")}
            </p>
          )}
        </div>

        <SheetFooter className="gap-2">
          <SheetClose asChild>
            <Button type="button" variant="outline" size="sm" disabled={update.isPending}>
              {t("common.cancel")}
            </Button>
          </SheetClose>
          <Button
            type="button"
            size="sm"
            onClick={() => void handleSave()}
            disabled={!canSubmit}
            className="gap-1.5"
          >
            {update.isPending ? <Spinner className="size-3.5" /> : null}
            {t("common.save")}
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  );
}
