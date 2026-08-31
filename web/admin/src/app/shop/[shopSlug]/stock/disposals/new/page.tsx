"use client";

import { useMemo, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";
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
import { useCreateDisposal, useSubmitDisposal } from "@/hooks/api/use-disposals";
import { useWarehouseLookup } from "@/hooks/api/use-warehouses";
import { useTranslation } from "@/providers/app-provider";

export default function NewDisposalPage() {
  const { t } = useTranslation();
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const router = useRouter();

  const warehousesQuery = useWarehouseLookup(shopSlug);
  const warehouses = warehousesQuery.data?.data ?? [];

  const createMutation = useCreateDisposal(shopSlug);
  const submitMutation = useSubmitDisposal(shopSlug);

  const [warehouseId, setWarehouseId] = useState<string>("");
  const [note, setNote] = useState("");
  const [rows, setRows] = useState<ItemRow[]>([emptyItemRow()]);

  const payloadItems = useMemo(
    () => rows.map(itemRowToPayload).filter((x): x is NonNullable<typeof x> => x !== null),
    [rows]
  );

  const canSave = !!warehouseId && payloadItems.length > 0;
  const pending = createMutation.isPending || submitMutation.isPending;

  async function handleSave(thenSubmit: boolean) {
    if (!canSave || pending) return;
    try {
      const result = await createMutation.mutateAsync({
        warehouse_id: warehouseId,
        note: note.trim() || null,
        items: payloadItems,
      });
      if (thenSubmit) {
        await submitMutation.mutateAsync(result.data.id);
      }
      router.push(`/shop/${shopSlug}/stock/disposals/${result.data.id}`);
    } catch {
      // mutation hook already showed a toast
    }
  }

  return (
    <>
      <PageHeader title={t("shop.stock.disposals.new.title")}>
        <Button variant="outline" size="sm" onClick={() => router.back()}>
          <ArrowLeft className="mr-1.5 size-3.5" />
          {t("common.back")}
        </Button>
      </PageHeader>
      <PageContent>
        <div className="mb-4 grid grid-cols-1 gap-3 rounded-md border bg-card p-4 sm:grid-cols-3">
          <div className="space-y-1.5">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.stock.disposals.new.warehouse")}
            </label>
            <Select value={warehouseId} onValueChange={setWarehouseId}>
              <SelectTrigger className="h-9 text-sm">
                <SelectValue placeholder={t("shop.stock.disposals.new.select_warehouse")} />
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
          <div className="space-y-1.5 sm:col-span-3">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.stock.disposals.new.note")}
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
            {t("shop.stock.disposals.new.items_heading")}
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
