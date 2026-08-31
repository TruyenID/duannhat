"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Minus, Plus } from "lucide-react";
import {
  Button,
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Textarea,
} from "@godxjp/ui";

import { useSplitLot } from "@/hooks/api/use-material-lots";
import { useWarehouseLookup } from "@/hooks/api/use-warehouses";
import { useTranslation } from "@/providers/app-provider";
import type { MaterialLot } from "@/services/material-lot-service";

// ---------------------------------------------------------------------------
//  Types
// ---------------------------------------------------------------------------

interface Part {
  qty: string;
  target_warehouse_id: string | null;
}

export interface SplitLotDialogProps {
  shopSlug: string;
  lot: MaterialLot;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

// ---------------------------------------------------------------------------
//  Component
// ---------------------------------------------------------------------------

const SAME_WAREHOUSE = "__same__";

export function SplitLotDialog({ shopSlug, lot, open, onOpenChange }: SplitLotDialogProps) {
  const { t, locale } = useTranslation();
  const splitMutation = useSplitLot(shopSlug);
  const { data: warehouseData } = useWarehouseLookup(shopSlug);
  const warehouses = warehouseData?.data ?? [];

  const qtyOnHand = Number(lot.qty_on_hand);

  // ── Local state ──────────────────────────────────────────────────────
  // One part by default: split off a single portion and the parent keeps the
  // remainder (it stays Active). The operator adds more parts as needed.
  const [parts, setParts] = useState<Part[]>([{ qty: "", target_warehouse_id: null }]);
  const [reason, setReason] = useState("");

  // Reset form when dialog opens
  useEffect(() => {
    if (!open) return;
    setParts([{ qty: "", target_warehouse_id: null }]);
    setReason("");
  }, [open]);

  // ── Derived values ───────────────────────────────────────────────────
  const totalQty = useMemo(() => parts.reduce((sum, p) => sum + (Number(p.qty) || 0), 0), [parts]);
  const remaining = qtyOnHand - totalQty;
  const overLimit = totalQty > qtyOnHand;
  const allPositive = parts.every((p) => Number(p.qty) > 0);
  const canSubmit = parts.length >= 1 && allPositive && !overLimit && !splitMutation.isPending;

  // ── Handlers ─────────────────────────────────────────────────────────
  const updatePart = useCallback((index: number, patch: Partial<Part>) => {
    setParts((prev) => prev.map((p, i) => (i === index ? { ...p, ...patch } : p)));
  }, []);

  const addPart = useCallback(() => {
    setParts((prev) => [...prev, { qty: "", target_warehouse_id: null }]);
  }, []);

  const removePart = useCallback((index: number) => {
    setParts((prev) => prev.filter((_, i) => i !== index));
  }, []);

  async function handleSubmit() {
    if (!canSubmit) return;
    try {
      await splitMutation.mutateAsync({
        lotId: lot.id,
        input: {
          parts: parts.map((p) => ({
            qty: Number(p.qty),
            target_warehouse_id:
              p.target_warehouse_id && p.target_warehouse_id !== SAME_WAREHOUSE
                ? p.target_warehouse_id
                : null,
          })),
          reason: reason.trim() || null,
        },
      });
      onOpenChange(false);
    } catch {
      // toast already shown by mutation hook
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        data-slot="split-lot-dialog"
        aria-describedby={undefined}
        className="sm:max-w-lg"
      >
        <DialogHeader>
          <DialogTitle>{t("material_lot.split_title")}</DialogTitle>
        </DialogHeader>

        <div className="space-y-4">
          {/* Current qty info */}
          <p className="text-sm text-muted-foreground">
            <span className="font-medium text-foreground">{lot.lot_code}</span>
            {" — "}
            {t("material_lot.split_current_qty", {
              qty: qtyOnHand.toLocaleString(locale),
              unit: lot.unit,
            })}
          </p>

          {/* Dynamic parts list */}
          <div className="space-y-3">
            {parts.map((part, index) => (
              <div key={index} className="space-y-2 rounded-md border p-3">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-medium text-muted-foreground">
                    {t("material_lot.split_part", { index: index + 1 })}
                  </span>
                  {parts.length > 1 && (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-6 px-2 text-xs"
                      onClick={() => removePart(index)}
                    >
                      <Minus className="mr-1 size-3" />
                      {t("material_lot.split_remove_part")}
                    </Button>
                  )}
                </div>
                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1">
                    <label className="text-xs font-medium text-muted-foreground">
                      {t("material_lot.split_part_qty")}
                    </label>
                    <Input
                      type="number"
                      min={0}
                      step="any"
                      value={part.qty}
                      onChange={(e) => updatePart(index, { qty: e.target.value })}
                      className="h-9 text-sm"
                    />
                  </div>
                  <div className="space-y-1">
                    <label className="text-xs font-medium text-muted-foreground">
                      {t("material_lot.split_target_warehouse")}
                    </label>
                    <Select
                      value={part.target_warehouse_id ?? SAME_WAREHOUSE}
                      onValueChange={(v) =>
                        updatePart(index, {
                          target_warehouse_id: v === SAME_WAREHOUSE ? null : v,
                        })
                      }
                    >
                      <SelectTrigger className="h-9 text-sm">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value={SAME_WAREHOUSE}>
                          {t("material_lot.split_same_warehouse")}
                        </SelectItem>
                        {warehouses.map((wh) => (
                          <SelectItem key={wh.id} value={wh.id}>
                            {wh.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                </div>
              </div>
            ))}
          </div>

          {/* Add part button */}
          <Button variant="outline" size="sm" className="w-full" onClick={addPart}>
            <Plus className="mr-1.5 size-3.5" />
            {t("material_lot.split_add_part")}
          </Button>

          {/* Remaining / over-limit feedback */}
          <div className="flex items-center justify-between text-sm">
            <span className="text-muted-foreground">
              {t("material_lot.split_remaining", {
                qty: remaining.toLocaleString(locale),
              })}
            </span>
            {overLimit && (
              <span className="text-xs font-medium text-destructive">
                {t("material_lot.split_over_limit")}
              </span>
            )}
          </div>

          {/* Reason */}
          <div className="space-y-1.5">
            <label className="text-xs font-medium text-muted-foreground">
              {t("material_lot.split_reason")}
            </label>
            <Textarea
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              rows={2}
              maxLength={500}
              className="field-sizing-fixed"
            />
          </div>
        </div>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={splitMutation.isPending}
          >
            {t("common.cancel")}
          </Button>
          <Button onClick={handleSubmit} disabled={!canSubmit}>
            {splitMutation.isPending && <Spinner className="mr-1.5 size-3.5" />}
            {t("material_lot.action_split")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
