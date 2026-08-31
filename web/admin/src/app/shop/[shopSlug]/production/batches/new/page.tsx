"use client";

import { useMemo, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";
import { toast } from "sonner";
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
import { useCreateMaterialBatch, useSubmitMaterialBatch } from "@/hooks/api/use-material-batches";
import { useMaterialLookup, useRecipeLookup } from "@/hooks/api/use-catalog-lookup";
import { useShopMaterialLots } from "@/hooks/api/use-material-lots";
import { useCreateShopLotReservation } from "@/hooks/api/use-material-lot-reservations";
import { useWarehouseLookup } from "@/hooks/api/use-warehouses";
import { useTranslation } from "@/providers/app-provider";
import {
  LotReservationSection,
  suggestFefoReservations,
} from "./components/lot-reservation-section";

export default function NewMaterialBatchPage() {
  const { t } = useTranslation();
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const router = useRouter();

  const warehousesQuery = useWarehouseLookup(shopSlug);
  const warehouses = warehousesQuery.data?.data ?? [];

  const materialsQuery = useMaterialLookup(shopSlug);
  // A batch can only be produced for a "produced" material (BTP).
  // BE marks them with yield_unit !== null — that flag is the source of
  // truth (set when a recipe is first approved). Filter raw materials out
  // here so the picker never lets the user start a batch that BE will reject.
  const producedMaterials = useMemo(
    () => (materialsQuery.data?.data ?? []).filter((m) => m.yield_unit !== null),
    [materialsQuery.data]
  );
  const materialOptions: ComboboxOption[] = producedMaterials.map((m) => ({
    value: m.id,
    // #1189 — no emoji: every option here is already a produced material,
    // so the factory glyph distinguished nothing.
    label: m.sku ? `${m.name} (${m.sku})` : m.name,
  }));

  const createMutation = useCreateMaterialBatch(shopSlug);
  const submitMutation = useSubmitMaterialBatch(shopSlug);

  const [warehouseId, setWarehouseId] = useState<string>("");
  const [materialId, setMaterialId] = useState<string>("");
  // `explicitRecipeId` is the user's manual pick. The derived `recipeId`
  // below falls through to `recipes[0]` when nothing is explicitly chosen
  // — avoids a setState-in-effect that React 19 strict mode warns on.
  const [explicitRecipeId, setExplicitRecipeId] = useState<string>("");
  const [multiplier, setMultiplier] = useState<string>("1");
  const [note, setNote] = useState("");
  const [reserveLots, setReserveLots] = useState(false);
  // Only the rows the user actually typed into. Everything else follows the
  // FEFO suggestion, derived below — no state, so no effect pushing a
  // suggestion back into state on every lot/qty change.
  const [qtyOverrides, setQtyOverrides] = useState<Record<string, string>>({});

  // Plan-022 T3.5 — Recipe is explicit. The lookup returns only active +
  // approved recipes for the chosen material. The first (most recent) is
  // pre-selected so the simple "1 recipe per material" case stays
  // single-click; advanced shops with multiple revisions can override.
  const recipesQuery = useRecipeLookup(shopSlug, materialId || undefined);
  const recipes = useMemo(() => recipesQuery.data?.data ?? [], [recipesQuery.data]);

  // Auto-default to recipes[0] (most recently approved) when the user hasn't
  // overridden — covers the common 1-recipe case without an extra click.
  const recipeId =
    explicitRecipeId && recipes.some((r) => r.id === explicitRecipeId)
      ? explicitRecipeId
      : (recipes[0]?.id ?? "");

  // Resolve recipe yield from the picked material so the form can derive
  // planned_yield (= yield_quantity * multiplier) and inherit yield_unit
  // — both are required by the BE store request.
  const selectedMaterial = producedMaterials.find((m) => m.id === materialId);
  const selectedRecipe = recipes.find((r) => r.id === recipeId);
  const baseYield = selectedMaterial ? Number(selectedMaterial.yield_quantity) : 0;
  const plannedYield = baseYield * (Number(multiplier) || 0);
  // Default yield_unit to the recipe's output_unit (the canonical source)
  // and fall back to the material's yield_unit for legacy materials that
  // still carry one.
  const yieldUnit = selectedRecipe?.output_unit ?? selectedMaterial?.yield_unit ?? "";

  function handleMaterialChange(value: string) {
    setMaterialId(value);
    setExplicitRecipeId("");
    // The old material's lots are gone from the picker; keeping their typed
    // quantities would silently hold lots the user can no longer see.
    setQtyOverrides({});
  }

  const hasRecipe = recipes.length > 0;

  // =======================================================================
  //  Lot holds (#3112) — the write path is POST /shops/{shop}/material-lot-reservations
  // =======================================================================

  const canFetchLots = reserveLots && !!materialId && !!warehouseId;
  const lotsQuery = useShopMaterialLots(
    canFetchLots ? shopSlug : "",
    canFetchLots
      ? { material_id: materialId, warehouse_id: warehouseId, status: "active", per_page: 50 }
      : {}
  );
  const lots = useMemo(() => lotsQuery.data?.data ?? [], [lotsQuery.data]);

  const suggestions = useMemo(() => {
    if (lots.length === 0 || plannedYield <= 0) return {} as Record<string, number>;
    return Object.fromEntries(
      suggestFefoReservations(lots, plannedYield).map((r) => [r.lot_id, r.qty])
    );
  }, [lots, plannedYield]);

  /** What each row shows: the user's own number when they typed one, else FEFO. */
  const lotQtyValues = useMemo(() => {
    const values: Record<string, string> = {};
    for (const lot of lots) {
      const override = qtyOverrides[lot.id];
      if (override !== undefined) {
        values[lot.id] = override;
      } else if (suggestions[lot.id]) {
        values[lot.id] = String(suggestions[lot.id]);
      }
    }
    return values;
  }, [lots, qtyOverrides, suggestions]);

  /** What actually gets POSTed — clamped to each lot's on-hand qty. */
  const plannedReservations = useMemo(
    () =>
      lots
        .map((lot) => {
          const raw = lotQtyValues[lot.id];
          const qty = Math.min(Math.max(0, Number(raw) || 0), Number(lot.qty_on_hand));
          return { lot_id: lot.id, qty };
        })
        .filter((r) => r.qty > 0),
    [lots, lotQtyValues]
  );

  const reservationMutation = useCreateShopLotReservation(shopSlug);

  function handleLotQtyChange(lotId: string, raw: string) {
    setQtyOverrides((prev) => ({ ...prev, [lotId]: raw }));
  }

  const canSave =
    !!warehouseId &&
    !!materialId &&
    !!recipeId &&
    Number(multiplier) > 0 &&
    plannedYield > 0 &&
    !!yieldUnit &&
    hasRecipe &&
    !createMutation.isPending &&
    !submitMutation.isPending &&
    !reservationMutation.isPending;

  const pending =
    createMutation.isPending || submitMutation.isPending || reservationMutation.isPending;

  async function handleSave(thenSubmit: boolean) {
    if (!canSave) return;
    try {
      const result = await createMutation.mutateAsync({
        warehouse_id: warehouseId,
        material_id: materialId,
        recipe_id: recipeId,
        multiplier: Number(multiplier),
        planned_yield: plannedYield,
        yield_unit: yieldUnit,
        note: note.trim() || null,
      });

      // Holds are created AFTER the batch, because each one names the batch it
      // serves — that link is what lets the hold be released when the batch is
      // cancelled instead of sitting on the lot until the 7-day TTL.
      //
      // Sequential, not Promise.all: the server serialises on the lot row
      // anyway (`lockForUpdate` + the `Σ(active) ≤ qty_on_hand` guard), and a
      // partial failure has to be reportable per lot rather than collapsed into
      // one rejected race.
      if (reserveLots && plannedReservations.length > 0) {
        let held = 0;
        let failed = 0;

        for (const reservation of plannedReservations) {
          try {
            await reservationMutation.mutateAsync({
              material_lot_id: reservation.lot_id,
              qty_reserved: reservation.qty,
              material_batch_id: result.data.id,
            });
            held += 1;
          } catch {
            failed += 1;
          }
        }

        // Never let a failed hold pass for a successful one — that silence is
        // the whole of #3077. The batch is already saved, so this reports
        // rather than aborts.
        if (failed > 0) {
          toast.error(
            t("shop.production.batches.form.reserve_partial_failure", {
              held: String(held),
              failed: String(failed),
            })
          );
        } else {
          toast.success(
            t("shop.production.batches.form.reserve_succeeded", { count: String(held) })
          );
        }
      }

      if (thenSubmit) {
        await submitMutation.mutateAsync(result.data.id);
      }
      router.push(`/shop/${shopSlug}/production/batches/${result.data.id}`);
    } catch {
      // toast already shown
    }
  }

  return (
    <>
      <PageHeader title={t("shop.production.batches.new_title")}>
        <Button variant="outline" size="sm" onClick={() => router.back()}>
          <ArrowLeft className="mr-1.5 size-3.5" />
          {t("common.back")}
        </Button>
      </PageHeader>
      <PageContent>
        <div className="mb-4 grid max-w-2xl grid-cols-1 gap-3 rounded-md border bg-card p-4 sm:grid-cols-2">
          <div className="space-y-1.5 sm:col-span-2">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.production.batches.form.warehouse")}
            </label>
            <Select value={warehouseId} onValueChange={setWarehouseId}>
              <SelectTrigger className="h-9 text-sm">
                <SelectValue placeholder={t("shop.production.batches.form.select_warehouse")} />
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
              {t("shop.production.batches.form.produced_material")}
            </label>
            <Combobox
              options={materialOptions}
              value={materialId}
              onChange={handleMaterialChange}
              placeholder={t("shop.production.batches.form.select_material")}
              searchPlaceholder={t("shop.production.batches.form.search_materials")}
              disabled={materialOptions.length === 0}
            />
            {!materialsQuery.isLoading && materialOptions.length === 0 ? (
              <p className="text-xs text-amber-700">
                {t("shop.production.batches.form.no_produced_materials")}
              </p>
            ) : null}
          </div>

          {materialId ? (
            <div className="space-y-1.5 sm:col-span-2">
              <label className="text-xs font-medium text-muted-foreground">
                {t("shop.production.batches.form.recipe")}
              </label>
              <Select
                value={recipeId}
                onValueChange={setExplicitRecipeId}
                disabled={recipes.length === 0}
              >
                <SelectTrigger className="h-9 text-sm">
                  <SelectValue placeholder={t("shop.production.batches.form.select_recipe")} />
                </SelectTrigger>
                <SelectContent>
                  {recipes.map((r) => (
                    <SelectItem key={r.id} value={r.id}>
                      {r.sku ? `${r.name} (${r.sku})` : r.name}
                      {r.output_quantity && r.output_unit
                        ? ` — ${Number(r.output_quantity).toLocaleString()} ${r.output_unit}`
                        : ""}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          ) : null}

          <div className="space-y-1.5">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.production.batches.form.multiplier")}
            </label>
            <Input
              type="number"
              step="0.01"
              min="0.01"
              value={multiplier}
              onChange={(e) => setMultiplier(e.target.value)}
              className="h-9 text-sm"
            />
            <p className="text-xs text-muted-foreground">
              {t("shop.production.batches.form.multiplier_help")}
            </p>
          </div>

          {selectedMaterial && yieldUnit ? (
            <div className="space-y-1.5">
              <label className="text-xs font-medium text-muted-foreground">
                {t("shop.production.batches.form.planned_yield")}
              </label>
              <div className="flex h-9 items-center rounded-md border bg-muted/40 px-3 text-sm">
                <span className="font-medium tabular-nums">{plannedYield.toLocaleString()}</span>
                <span className="ml-1 text-muted-foreground">{yieldUnit}</span>
              </div>
              <p className="text-xs text-muted-foreground">
                {t("shop.production.batches.form.planned_yield_help", {
                  base: baseYield.toLocaleString(),
                  unit: yieldUnit,
                })}
              </p>
            </div>
          ) : null}

          {selectedMaterial && !hasRecipe ? (
            <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 sm:col-span-2">
              {t("shop.production.batches.form.no_recipe_warning")}
            </div>
          ) : null}

          <div className="space-y-1.5 sm:col-span-2">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.production.batches.form.note")}
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

        {/*
          #3077 → #3112 — ô "giữ lô" trở lại, lần này CÓ ghi thật.
          Điều kiện mà #3109 đặt ra đã đủ: endpoint phạm vi SHOP
          (`POST /shops/{shop}/material-lot-reservations`) cùng các ability
          `*AtShop`, nên nhân viên kho — đúng những người tạo mẻ ở màn này —
          không còn nhận 403. Mỗi hold gắn `material_batch_id`, và mọi lượt
          hỏng đều được BÁO, không nuốt.
        */}
        {selectedMaterial && hasRecipe && warehouseId && (
          <LotReservationSection
            lots={lots}
            isLoading={lotsQuery.isLoading}
            unit={yieldUnit}
            enabled={reserveLots}
            onEnabledChange={setReserveLots}
            values={lotQtyValues}
            suggestions={suggestions}
            onQtyChange={handleLotQtyChange}
          />
        )}

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
