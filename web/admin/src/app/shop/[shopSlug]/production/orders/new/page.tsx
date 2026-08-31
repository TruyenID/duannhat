"use client";

import { useMemo, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";
import {
  Button,
  Combobox,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Textarea,
  type ComboboxOption,
} from "@godxjp/ui";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import {
  useCreateProductionOrder,
  useSubmitProductionOrder,
} from "@/hooks/api/use-production-orders";
import { useProductSkuLookup } from "@/hooks/api/use-catalog-lookup";
import { useWarehouseLookup } from "@/hooks/api/use-warehouses";
import { useTranslation } from "@/providers/app-provider";

export default function NewProductionOrderPage() {
  const { t } = useTranslation();
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const router = useRouter();

  const warehousesQuery = useWarehouseLookup(shopSlug);
  const warehouses = warehousesQuery.data?.data ?? [];

  const skusQuery = useProductSkuLookup(shopSlug);
  const skus = skusQuery.data?.data ?? [];
  // Label format: "Product Name › Variant Name (SKU)". Falling back through
  // product.name → variant.name → sku → id makes sure the operator always
  // sees something they recognise — without this, default-SKUs (no variant
  // name) render as "{sku} ({sku})" which is meaningless on its own.
  const skuOptions: ComboboxOption[] = skus.map((s) => {
    const productName = s.product?.name ?? null;
    const variantName = s.name ?? null;
    const code = s.sku ?? null;
    const display = productName
      ? variantName
        ? `${productName} › ${variantName}`
        : productName
      : (variantName ?? code ?? s.id);
    return {
      value: s.id,
      label: code ? `${display} (${code})` : display,
    };
  });

  const createMutation = useCreateProductionOrder(shopSlug);
  const submitMutation = useSubmitProductionOrder(shopSlug);

  const [warehouseId, setWarehouseId] = useState<string>("");
  const [outputVariantId, setOutputVariantId] = useState<string>("");
  const [plannedQty, setPlannedQty] = useState<string>("");
  const [note, setNote] = useState("");

  const selectedSku = useMemo(
    () => skus.find((s) => s.id === outputVariantId) ?? null,
    [skus, outputVariantId]
  );

  // Output unit is locked to the SKU recipe's output_unit. BR-01 of
  // ProductionOrder requires the SKU to have a recipe; the recipe owns the
  // canonical unit of measure for what one batch produces (1 serving,
  // 1 piece, 1 bowl). Letting the operator override it on the order would
  // drift the unit away from StockLevel / VariantUnit base — typo-prone
  // and the wrong place to declare it.
  const outputUnit = selectedSku?.recipe?.output_unit ?? "";
  const skuMissingRecipe = !!selectedSku && !selectedSku.recipe;

  const canSave =
    !!warehouseId &&
    !!outputVariantId &&
    !skuMissingRecipe &&
    outputUnit.trim() !== "" &&
    Number(plannedQty) > 0 &&
    !createMutation.isPending &&
    !submitMutation.isPending;

  const pending = createMutation.isPending || submitMutation.isPending;

  async function handleSave(thenSubmit: boolean) {
    if (!canSave) return;
    try {
      const result = await createMutation.mutateAsync({
        warehouse_id: warehouseId,
        output_variant_id: outputVariantId,
        planned_quantity: Number(plannedQty),
        output_unit: outputUnit.trim(),
        note: note.trim() || null,
      });
      if (thenSubmit) {
        await submitMutation.mutateAsync(result.data.id);
      }
      router.push(`/shop/${shopSlug}/production/orders/${result.data.id}`);
    } catch {
      // toast already shown
    }
  }

  return (
    <>
      <PageHeader title={t("shop.production.orders.new_title")}>
        <Button variant="outline" size="sm" onClick={() => router.back()}>
          <ArrowLeft className="mr-1.5 size-3.5" />
          {t("common.back")}
        </Button>
      </PageHeader>
      <PageContent>
        <div className="mb-4 grid max-w-2xl grid-cols-1 gap-3 rounded-md border bg-card p-4 sm:grid-cols-2">
          <div className="space-y-1.5 sm:col-span-2">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.production.orders.form.warehouse")}
            </label>
            <Select value={warehouseId} onValueChange={setWarehouseId}>
              <SelectTrigger className="h-9 text-sm">
                <SelectValue placeholder={t("shop.production.orders.form.select_warehouse")} />
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

          <div className="space-y-1.5 sm:col-span-2">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.production.orders.form.output_variant")}
            </label>
            <Combobox
              options={skuOptions}
              value={outputVariantId}
              onChange={setOutputVariantId}
              placeholder={t("shop.production.orders.form.select_variant")}
              searchPlaceholder={t("shop.production.orders.form.search_variants")}
            />
          </div>

          <div className="space-y-1.5">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.production.orders.form.planned_qty")}
            </label>
            <Input
              type="number"
              step="0.0001"
              min="0"
              value={plannedQty}
              onChange={(e) => setPlannedQty(e.target.value)}
              className="h-9 text-sm"
            />
            {selectedSku?.recipe?.output_quantity != null &&
              (() => {
                // Show the operator how many recipe runs their requested
                // planned_quantity translates to. Without this hint the
                // distinction between recipe.output_quantity ("one batch
                // yields N units") and order.planned_quantity ("we want N
                // units total") is invisible — the only signal is the items
                // table further down, which appears after save.
                const batchYield = Number(selectedSku.recipe.output_quantity);
                const wanted = Number(plannedQty);
                if (!Number.isFinite(batchYield) || batchYield <= 0) return null;
                if (!Number.isFinite(wanted) || wanted <= 0) {
                  return (
                    <p className="text-[11px] text-muted-foreground">
                      {t("shop.production.orders.form.planned_qty_recipe_hint", {
                        yield: batchYield.toLocaleString(undefined, { maximumFractionDigits: 4 }),
                        unit: outputUnit,
                      })}
                    </p>
                  );
                }
                const multiplier = wanted / batchYield;
                return (
                  <p className="text-[11px] text-muted-foreground">
                    {t("shop.production.orders.form.planned_qty_multiplier_hint", {
                      multiplier: multiplier.toLocaleString(undefined, {
                        maximumFractionDigits: 4,
                      }),
                      yield: batchYield.toLocaleString(undefined, { maximumFractionDigits: 4 }),
                      unit: outputUnit,
                    })}
                  </p>
                );
              })()}
          </div>

          <div className="space-y-1.5">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.production.orders.form.output_unit")}
            </label>
            <Input
              value={outputUnit}
              readOnly
              disabled
              placeholder={t("shop.production.orders.form.output_unit_placeholder")}
              className="h-9 text-sm"
            />
            {skuMissingRecipe && (
              <p className="text-[11px] text-red-500">
                {t("shop.production.orders.form.output_unit_no_recipe_hint")}
              </p>
            )}
            {selectedSku && selectedSku.recipe && !selectedSku.recipe.output_unit && (
              <p className="text-[11px] text-red-500">
                {t("shop.production.orders.form.output_unit_recipe_missing_unit_hint")}
              </p>
            )}
          </div>

          <div className="space-y-1.5 sm:col-span-2">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.production.orders.form.note")}
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

        <div className="flex max-w-2xl items-center justify-end gap-2 border-t pt-4">
          <Button variant="outline" onClick={() => router.back()} disabled={pending}>
            {t("common.cancel")}
          </Button>
          <Button variant="outline" onClick={() => handleSave(false)} disabled={!canSave}>
            {pending && <Spinner className="mr-1.5 size-3.5" />}
            {t("common.save_draft")}
          </Button>
          <Button onClick={() => handleSave(true)} disabled={!canSave}>
            {pending && <Spinner className="mr-1.5 size-3.5" />}
            {t("common.save_and_submit")}
          </Button>
        </div>
      </PageContent>
    </>
  );
}
