"use client";

import { useMemo, useState } from "react";
import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  MultiCombobox,
  Spinner,
  type ComboboxOption,
} from "@godxjp/ui";
import { useAddStockCountItems } from "@/hooks/api/use-stock-counts";
import { useMaterialLookup, useProductSkuLookup } from "@/hooks/api/use-catalog-lookup";
import { useTranslation } from "@/providers/app-provider";

export interface AddItemsDialogProps {
  shopSlug: string;
  countId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Item ids (as `variant:<id>` / `material:<id>`) already on the count. */
  existingSelections: string[];
}

/**
 * Partial-scope stock counts allow new items to be attached while the
 * count is in_progress. This dialog surfaces the shop-scoped SKU +
 * material lookups and submits the chosen items via the
 * `/stock-counts/{id}/add-items` endpoint.
 */
export function AddItemsDialog({
  shopSlug,
  countId,
  open,
  onOpenChange,
  existingSelections,
}: AddItemsDialogProps) {
  const { t } = useTranslation();
  const skusQuery = useProductSkuLookup(shopSlug);
  const materialsQuery = useMaterialLookup(shopSlug);

  const alreadyAttached = useMemo(() => new Set(existingSelections), [existingSelections]);

  // cmdk filters by `value` field using command-score fuzzy matching.
  // A bare `kind:UUID` value pushes the UUID to the head of the score
  // calculation, so substring searches against the human-readable name
  // (e.g. "Phở Bò" or "Bột mì") return zero matches. Encode as
  // `display·kind:id` so the readable text leads and the id is recovered
  // after the trailing U+00B7 sentinel — same pattern as item-row-editor.
  const VALUE_SEP = "·";

  function encode(display: string, kind: "variant" | "material", id: string): string {
    return `${display}${VALUE_SEP}${kind}:${id}`;
  }

  function decodeToCanonical(encoded: string): string {
    const sepIdx = encoded.lastIndexOf(VALUE_SEP);
    return sepIdx >= 0 ? encoded.slice(sepIdx + 1) : encoded;
  }

  // Build the combined option list and skip entries that are already on
  // the count — the backend would reject duplicates and it's the single
  // most common validation error on this dialog in the legacy UI.
  const options: ComboboxOption[] = useMemo(() => {
    const out: ComboboxOption[] = [];
    for (const sku of skusQuery.data?.data ?? []) {
      const canonical = `variant:${sku.id}`;
      if (alreadyAttached.has(canonical)) continue;
      const variantName = sku.name ?? sku.sku ?? sku.id;
      const productName = sku.product?.name?.trim();
      const head =
        productName && productName !== variantName
          ? `${productName} — ${variantName}`
          : variantName;
      const label = `[SKU] ${head}${sku.sku ? ` (${sku.sku})` : ""}`;
      out.push({ value: encode(label, "variant", sku.id), label });
    }
    for (const mat of materialsQuery.data?.data ?? []) {
      const canonical = `material:${mat.id}`;
      if (alreadyAttached.has(canonical)) continue;
      const label = `[MAT] ${mat.name}${mat.sku ? ` (${mat.sku})` : ""}`;
      out.push({ value: encode(label, "material", mat.id), label });
    }
    return out;
  }, [skusQuery.data, materialsQuery.data, alreadyAttached]);

  const [selected, setSelected] = useState<string[]>([]);
  const addMutation = useAddStockCountItems(shopSlug);

  async function handleConfirm() {
    if (selected.length === 0) return;
    const items = selected.map((encoded) => {
      const canonical = decodeToCanonical(encoded);
      const [kind, id] = canonical.split(":");
      return kind === "variant"
        ? { product_sku_id: id, material_id: null }
        : { product_sku_id: null, material_id: id };
    });
    await addMutation.mutateAsync({ id: countId, data: { items } });
    setSelected([]);
    onOpenChange(false);
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        onOpenChange(next);
        if (!next) setSelected([]);
      }}
    >
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{t("shop.stock.counts.add_items.title")}</DialogTitle>
          <DialogDescription>{t("shop.stock.counts.add_items.description")}</DialogDescription>
        </DialogHeader>

        <div className="space-y-1.5">
          <label className="text-xs font-medium text-muted-foreground">
            {t("shop.stock.counts.add_items.items_label")}
          </label>
          <MultiCombobox
            options={options}
            value={selected}
            onChange={setSelected}
            placeholder={t("shop.stock.counts.add_items.placeholder")}
            searchPlaceholder={t("shop.stock.counts.add_items.search_placeholder")}
            emptyText={t("shop.stock.counts.add_items.empty")}
          />
          <p className="text-xs text-muted-foreground">
            {t("shop.stock.counts.add_items.selected_count", { n: selected.length })}
          </p>
        </div>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={addMutation.isPending}
          >
            {t("common.cancel")}
          </Button>
          <Button onClick={handleConfirm} disabled={addMutation.isPending || selected.length === 0}>
            {addMutation.isPending && <Spinner className="mr-1.5 size-3.5" />}
            {selected.length > 0
              ? t("shop.stock.counts.add_items.add_btn_count", { n: selected.length })
              : t("shop.stock.counts.add_items.add_btn")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
