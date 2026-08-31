"use client";

import { useMemo, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, ArrowRight } from "lucide-react";
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
import { useCreateStockTransfer, useSubmitStockTransfer } from "@/hooks/api/use-stock-transfers";
import { useWarehouseLookup } from "@/hooks/api/use-warehouses";
import { useTranslation } from "@/providers/app-provider";

export default function NewStockTransferPage() {
  const { t } = useTranslation();
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const router = useRouter();

  const warehousesQuery = useWarehouseLookup(shopSlug);
  const warehouses = warehousesQuery.data?.data ?? [];

  const createMutation = useCreateStockTransfer(shopSlug);
  const submitMutation = useSubmitStockTransfer(shopSlug);

  const [sourceWarehouseId, setSourceWarehouseId] = useState<string>("");
  const [destinationWarehouseId, setDestinationWarehouseId] = useState<string>("");
  const [note, setNote] = useState("");
  const [rows, setRows] = useState<ItemRow[]>([emptyItemRow()]);

  const payloadItems = useMemo(
    () => rows.map(itemRowToPayload).filter((x): x is NonNullable<typeof x> => x !== null),
    [rows]
  );

  const canSave =
    !!sourceWarehouseId &&
    !!destinationWarehouseId &&
    sourceWarehouseId !== destinationWarehouseId &&
    payloadItems.length > 0;

  const pending = createMutation.isPending || submitMutation.isPending;

  async function handleSave(thenSubmit: boolean) {
    if (!canSave || pending) return;
    try {
      const result = await createMutation.mutateAsync({
        source_warehouse_id: sourceWarehouseId,
        destination_warehouse_id: destinationWarehouseId,
        note: note.trim() || null,
        items: payloadItems,
      });
      if (thenSubmit) {
        await submitMutation.mutateAsync(result.data.id);
      }
      router.push(`/shop/${shopSlug}/stock/transfers/${result.data.id}`);
    } catch {
      // toast already shown
    }
  }

  return (
    <>
      <PageHeader title={t("shop.stock.transfers.new.title")}>
        <Button variant="outline" size="sm" onClick={() => router.back()}>
          <ArrowLeft className="mr-1.5 size-3.5" />
          {t("common.back")}
        </Button>
      </PageHeader>
      <PageContent>
        <div className="mb-4 rounded-md border bg-card p-4">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_auto_1fr]">
            <div className="space-y-1.5">
              <label className="text-xs font-medium text-muted-foreground">
                {t("shop.stock.transfers.new.source")}
              </label>
              <Select value={sourceWarehouseId} onValueChange={setSourceWarehouseId}>
                <SelectTrigger className="h-9 text-sm">
                  <SelectValue placeholder={t("shop.stock.transfers.new.from_placeholder")} />
                </SelectTrigger>
                <SelectContent>
                  {warehouses.map((w) => (
                    <SelectItem key={`src-${w.id}`} value={w.id}>
                      {w.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="flex items-end justify-center pb-1">
              <ArrowRight className="size-4 text-muted-foreground" />
            </div>
            <div className="space-y-1.5">
              <label className="text-xs font-medium text-muted-foreground">
                {t("shop.stock.transfers.new.destination")}
              </label>
              <Select value={destinationWarehouseId} onValueChange={setDestinationWarehouseId}>
                <SelectTrigger className="h-9 text-sm">
                  <SelectValue placeholder={t("shop.stock.transfers.new.to_placeholder")} />
                </SelectTrigger>
                <SelectContent>
                  {warehouses
                    .filter((w) => w.id !== sourceWarehouseId)
                    .map((w) => (
                      <SelectItem key={`dst-${w.id}`} value={w.id}>
                        {w.name}
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
            </div>
          </div>
          <div className="mt-3 space-y-1.5">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.stock.transfers.new.note")}
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
            {t("shop.stock.transfers.new.items_heading")}
          </h3>
          <ItemRowEditor shopSlug={shopSlug} rows={rows} onChange={setRows} />
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
