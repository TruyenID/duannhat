"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
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
import { useCreateStockCount } from "@/hooks/api/use-stock-counts";
import { useWarehouseLookup } from "@/hooks/api/use-warehouses";
import { StockCountScope } from "@/services/stock-count-service";
import { useTranslation } from "@/providers/app-provider";

export interface CreateStockCountDialogProps {
  shopSlug: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

// Mirrors the legacy Inertia create modal: warehouse + scope required, note
// optional. Successful submission routes to the detail page — once Phase 2
// of the port ships, the detail page will resolve; until then the router
// push will 404 and the user can navigate back to the list.
export function CreateStockCountDialog({
  shopSlug,
  open,
  onOpenChange,
}: CreateStockCountDialogProps) {
  const { t } = useTranslation();
  const router = useRouter();
  const createMutation = useCreateStockCount(shopSlug);
  const warehousesQuery = useWarehouseLookup(shopSlug);
  const warehouses = warehousesQuery.data?.data ?? [];

  const [warehouseId, setWarehouseId] = useState<string>("");
  const [scope, setScope] = useState<StockCountScope>(StockCountScope.Full);
  const [note, setNote] = useState<string>("");

  const canSubmit = !!warehouseId && !createMutation.isPending;

  function reset() {
    setWarehouseId("");
    setScope(StockCountScope.Full);
    setNote("");
  }

  async function handleSubmit() {
    if (!canSubmit) return;
    try {
      const result = await createMutation.mutateAsync({
        warehouse_id: warehouseId,
        scope,
        note: note.trim() || null,
      });
      onOpenChange(false);
      reset();
      router.push(`/shop/${shopSlug}/stock/counts/${result.data.id}`);
    } catch {
      // toast already shown by the mutation hook
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        onOpenChange(next);
        if (!next) reset();
      }}
    >
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t("shop.stock.counts.create.title")}</DialogTitle>
          <DialogDescription>{t("shop.stock.counts.create.description")}</DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-1.5">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.stock.counts.create.warehouse")}
            </label>
            <Select value={warehouseId} onValueChange={setWarehouseId}>
              <SelectTrigger className="h-9 text-sm">
                <SelectValue placeholder={t("shop.stock.counts.create.select_warehouse")} />
              </SelectTrigger>
              <SelectContent>
                {warehouses.map((w) => (
                  <SelectItem key={w.id} value={w.id}>
                    {w.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.stock.counts.create.scope")}
            </label>
            <Select value={scope} onValueChange={(v) => setScope(v as StockCountScope)}>
              <SelectTrigger className="h-9 text-sm">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={StockCountScope.Full}>
                  {t("shop.stock.counts.create.scope_full_desc")}
                </SelectItem>
                <SelectItem value={StockCountScope.Partial}>
                  {t("shop.stock.counts.create.scope_partial_desc")}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.stock.counts.create.note")}
            </label>
            <Textarea
              value={note}
              onChange={(e) => setNote(e.target.value)}
              rows={3}
              maxLength={1000}
              className="field-sizing-fixed"
              placeholder={t("shop.stock.counts.create.note_placeholder")}
            />
          </div>
        </div>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={createMutation.isPending}
          >
            {t("common.cancel")}
          </Button>
          <Button onClick={handleSubmit} disabled={!canSubmit}>
            {createMutation.isPending && <Spinner className="mr-1.5 size-3.5" />}
            {t("common.create")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
