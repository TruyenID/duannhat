"use client";

import { useMemo, useState } from "react";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import { ArrowLeft } from "lucide-react";
import { parseOptionalPrice } from "@/lib/utils";
import {
  Button,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Textarea,
} from "@godxjp/ui";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import {
  ItemRowEditor,
  emptyItemRow,
  itemRowToPayload,
  type ItemRow,
} from "@/components/shared/item-row-editor";
import {
  useCreateStockTransaction,
  useSubmitStockTransaction,
} from "@/hooks/api/use-stock-transactions";
import { useWarehouseLookup } from "@/hooks/api/use-warehouses";
import { useTranslation } from "@/providers/app-provider";
import {
  StockTransactionSubType,
  StockTransactionType,
} from "@/services/stock-transaction-service";

// Sub-type options grouped by transaction type. Purchase-style sub-types
// enable the per-row unit-price column (matching the old Inertia create
// form). `production` / `transfer_*` are intentionally excluded from the
// picker because those transactions are created by workflow services on
// their own (MaterialBatch.start, StockTransfer.approve, etc).
function useSubTypesByType() {
  const { t } = useTranslation();
  const SUB_TYPES_BY_TYPE: Record<
    StockTransactionType,
    { value: StockTransactionSubType; label: string; withPrice?: boolean }[]
  > = {
    [StockTransactionType.StockIn]: [
      {
        value: StockTransactionSubType.Purchase,
        label: t("shop.stock.transactions.sub_type.purchase"),
        withPrice: true,
      },
      {
        value: StockTransactionSubType.Return,
        label: t("shop.stock.transactions.sub_type.return"),
      },
      {
        value: StockTransactionSubType.AdjustmentIn,
        label: t("shop.stock.transactions.sub_type.adjustment_in"),
      },
      { value: StockTransactionSubType.Other, label: t("shop.stock.transactions.sub_type.other") },
    ],
    [StockTransactionType.StockOut]: [
      { value: StockTransactionSubType.Sales, label: t("shop.stock.transactions.sub_type.sales") },
      {
        value: StockTransactionSubType.Disposal,
        label: t("shop.stock.transactions.sub_type.disposal"),
      },
      {
        value: StockTransactionSubType.AdjustmentOut,
        label: t("shop.stock.transactions.sub_type.adjustment_out"),
      },
      { value: StockTransactionSubType.Other, label: t("shop.stock.transactions.sub_type.other") },
    ],
  };
  return SUB_TYPES_BY_TYPE;
}

function parseTypeParam(raw: string | null): StockTransactionType {
  return raw === StockTransactionType.StockOut
    ? StockTransactionType.StockOut
    : StockTransactionType.StockIn;
}

export default function NewStockTransactionPage() {
  const { t } = useTranslation();
  const SUB_TYPES_BY_TYPE = useSubTypesByType();
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const searchParams = useSearchParams();
  const router = useRouter();

  const type = parseTypeParam(searchParams.get("type"));

  const warehousesQuery = useWarehouseLookup(shopSlug);
  const warehouses = warehousesQuery.data?.data ?? [];

  const createMutation = useCreateStockTransaction(shopSlug);
  const submitMutation = useSubmitStockTransaction(shopSlug);

  const [warehouseId, setWarehouseId] = useState<string>("");
  const [subType, setSubType] = useState<StockTransactionSubType>(SUB_TYPES_BY_TYPE[type][0].value);
  const [note, setNote] = useState("");
  const [rows, setRows] = useState<ItemRow[]>([emptyItemRow()]);
  const [unitPrices, setUnitPrices] = useState<string[]>([""]);

  const subTypeOption = SUB_TYPES_BY_TYPE[type].find((o) => o.value === subType);
  const withPrice = !!subTypeOption?.withPrice;

  const payloadItems = useMemo(() => {
    return rows
      .map((row, i) => {
        const base = itemRowToPayload(row);
        if (!base) return null;
        if (withPrice) {
          // Blank price → null (no cost entered), NOT 0. Number("") === 0 would
          // otherwise stamp a ¥0 unit cost onto the inbound purchase lot.
          return {
            ...base,
            unit_price: parseOptionalPrice(unitPrices[i]),
          };
        }
        return base;
      })
      .filter((x): x is NonNullable<typeof x> => x !== null);
  }, [rows, unitPrices, withPrice]);

  const canSave = !!warehouseId && payloadItems.length > 0;
  const pending = createMutation.isPending || submitMutation.isPending;

  async function handleSave(thenSubmit: boolean) {
    if (!canSave || pending) return;
    try {
      const result = await createMutation.mutateAsync({
        warehouse_id: warehouseId,
        type,
        sub_type: subType,
        note: note.trim() || null,
        items: payloadItems,
      });
      if (thenSubmit) {
        await submitMutation.mutateAsync(result.data.id);
      }
      router.push(`/shop/${shopSlug}/stock/transactions/${result.data.id}`);
    } catch {
      // toast already shown by mutation hook
    }
  }

  return (
    <>
      <PageHeader
        title={
          type === StockTransactionType.StockIn
            ? t("shop.stock.transactions.new.stock_in_title")
            : t("shop.stock.transactions.new.stock_out_title")
        }
      >
        <Button variant="outline" size="sm" onClick={() => router.back()}>
          <ArrowLeft className="mr-1.5 size-3.5" />
          {t("common.back")}
        </Button>
      </PageHeader>
      <PageContent>
        <div className="mb-4 grid grid-cols-1 gap-3 rounded-md border bg-card p-4 sm:grid-cols-3">
          <div className="space-y-1.5">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.stock.transactions.new.warehouse")}
            </label>
            <Select value={warehouseId} onValueChange={setWarehouseId}>
              <SelectTrigger className="h-9 text-sm">
                <SelectValue placeholder={t("shop.stock.transactions.new.select_warehouse")} />
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
              {t("shop.stock.transactions.new.sub_type")}
            </label>
            <Select value={subType} onValueChange={(v) => setSubType(v as StockTransactionSubType)}>
              <SelectTrigger className="h-9 text-sm">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {SUB_TYPES_BY_TYPE[type].map((opt) => (
                  <SelectItem key={opt.value} value={opt.value}>
                    {opt.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5 sm:col-span-3">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.stock.transactions.new.note")}
            </label>
            <Textarea
              value={note}
              onChange={(e) => setNote(e.target.value)}
              rows={2}
              maxLength={1000}
              className="field-sizing-fixed"
            />
          </div>
        </div>

        <div className="mb-4">
          <h3 className="mb-2 text-sm font-medium">
            {t("shop.stock.transactions.new.items_heading")}
          </h3>
          <ItemRowEditor
            shopSlug={shopSlug}
            rows={rows}
            onChange={setRows}
            withUnitPrice={withPrice}
            unitPrices={unitPrices}
            onUnitPricesChange={setUnitPrices}
          />
        </div>

        <div className="flex items-center justify-end gap-2 border-t pt-4">
          <Button variant="outline" onClick={() => router.back()} disabled={pending}>
            {t("common.cancel")}
          </Button>
          <Button
            variant="outline"
            onClick={() => handleSave(false)}
            disabled={!canSave || pending}
          >
            {pending && <Spinner className="mr-1.5 size-3.5" />}
            {t("common.save_draft")}
          </Button>
          <Button onClick={() => handleSave(true)} disabled={!canSave || pending}>
            {pending && <Spinner className="mr-1.5 size-3.5" />}
            {t("common.save_and_submit")}
          </Button>
        </div>
      </PageContent>
    </>
  );
}
