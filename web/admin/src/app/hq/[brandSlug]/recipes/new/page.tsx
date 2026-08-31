"use client";

import { useEffect, useMemo, useState } from "react";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import { useQueries, useQuery } from "@tanstack/react-query";
import { Factory, HelpCircle, Package, Plus, Save, Trash2, X } from "lucide-react";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { Button } from "@godxjp/ui";
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@godxjp/ui";
import { Card } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import { Checkbox } from "@godxjp/ui";
import { Label } from "@godxjp/ui";
import { Popover, PopoverContent, PopoverTrigger } from "@godxjp/ui";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";
import { Combobox, type ComboboxOption } from "@godxjp/ui";
import { TranslatableRichText } from "@godxjp/ui";
import { ProductSearchSelect } from "@/components/shared/product-search-select";
import type { TranslatableValue } from "@godxjp/ui";
import { ApiError, apiFetch } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import { useCreateRecipe } from "@/hooks/api/use-recipes";
import { Spinner } from "@godxjp/ui";
import { DEFAULT_LOCALE } from "@/i18n";
import { buildI18nPayload, emptyLocaleMap } from "@/types/models/payload-helpers";
import { fillLocalesFallback } from "@/lib/i18n-fill";
import { useMaterialLookup, useVariantLookup } from "@/hooks/api/use-materials";
import { productKeys } from "@/hooks/api/query-keys";
import { productService, type ProductSku } from "@/services/product-service";
import type {
  RecipeIngredient,
  IngredientType,
  CreateRecipeInput,
} from "@/services/recipe-service";

/** Shape returned by productService.getById — `{ data: Product }`. */
type ProductDetailResponse = Awaited<ReturnType<typeof productService.getById>>;

// ---------------------------------------------------------------------------
//  Types
// ---------------------------------------------------------------------------

interface BrandResponse {
  data: { id: string; slug: string; name: string };
}

interface IngredientRow {
  type: IngredientType;
  /** Parent product of the chosen variant. Required to reach the SKU dropdown. */
  product_id: string;
  /** Display name of the picked product, kept so the trigger button renders
   *  correctly when the server search no longer returns this id in its page. */
  product_name: string;
  variant_id: string;
  material_id: string;
  unit_id: string;
  /** Plan-024 UX-3 — literal unit per row (e.g. `g`, `ml`). Defaults to the
   *  linked material's yield_unit on selection but the user can override. */
  unit: string;
  quantity: string;
}

/**
 * Recipe output kind — what the recipe produces.
 *   - `material`: a produced BTP (the original plan-022 flow). Sets
 *     `material_id` and (optionally) snaps `output_unit` to the material's
 *     yield_unit.
 *   - `sku`: one or more menu-facing Product SKUs. Sets `sku_ids` (which
 *     RecipeService writes back as `ProductSku.recipe_id`). No material is
 *     linked. `output_quantity` describes one batch yield (default 1 serving).
 */
type OutputKind = "material" | "sku";

interface FormState {
  name: TranslatableValue;
  description: TranslatableValue;
  /** Which side of the output picker is active. */
  output_kind: OutputKind;
  /** Plan-022 T18 — output material the recipe produces. */
  material_id: string;
  /** Product SKUs this recipe produces. Used when output_kind === "sku". */
  sku_ids: string[];
  output_quantity: string;
  output_unit: string;
  ingredients: IngredientRow[];
  instructions: TranslatableValue;
  is_active: boolean;
  /**
   * Plan-022 (yield variance) — % drift between actual and planned yield
   * that the shop can complete a batch with WITHOUT supplying a reason.
   * 0 = strict (any drift requires a reason). Stored as string in the form
   * for input-control ergonomics; parsed to Number on submit.
   */
  yield_variance_tolerance_pct: string;
}

function emptyFormState(): FormState {
  return {
    name: emptyLocaleMap(),
    description: emptyLocaleMap(),
    output_kind: "material",
    material_id: "",
    sku_ids: [],
    output_quantity: "1",
    output_unit: "",
    ingredients: [],
    instructions: emptyLocaleMap(),
    is_active: true,
    yield_variance_tolerance_pct: "0",
  };
}

// ---------------------------------------------------------------------------
//  Page
// ---------------------------------------------------------------------------

export default function NewRecipePage() {
  const router = useRouter();
  const params = useParams<{ brandSlug: string }>();
  const searchParams = useSearchParams();
  const brandSlug = params.brandSlug;
  const { t, locale } = useTranslation();

  // Plan-022 T19 — when the user lands here from the Material new form
  // (kind=produced), pre-select the just-created material as the output.
  const presetOutputMaterialId = searchParams?.get("output_material_id") ?? "";

  const [form, setForm] = useState<FormState>(() => ({
    ...emptyFormState(),
    material_id: presetOutputMaterialId,
  }));
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);
  // Tracks whether the user has edited the form. Set only by user-driven
  // handlers below — NOT by the output-unit auto-fill effect — so entering
  // with a preset output material doesn't falsely trip the Cancel guard.
  const [dirty, setDirty] = useState(false);

  // Brand info
  const { data: brandResponse } = useQuery({
    queryKey: ["hq", "brand", brandSlug],
    queryFn: () => apiFetch<BrandResponse>(`/api/v1/hq/${brandSlug}`),
    staleTime: 5 * 60 * 1000,
  });
  const brandId = brandResponse?.data.id ?? "";

  // Lookups
  const materialLookup = useMaterialLookup(brandSlug, true);
  const allMaterials = materialLookup.data?.data ?? [];

  // Product-SKU lookup — only fetched when the user chose the SKU output kind
  // so we don't pay the round-trip for the default Material flow.
  const variantLookup = useVariantLookup(brandSlug, form.output_kind === "sku");
  const allVariants = variantLookup.data?.data ?? [];
  const variantOptions = useMemo<ComboboxOption[]>(() => {
    // Hide variants already added so the picker only shows what can still be
    // chosen. Label format: "Product Name › Variant Name" — gives the operator
    // enough context to pick the right SKU when names collide across products.
    const taken = new Set(form.sku_ids);
    return allVariants
      .filter((v) => !taken.has(v.id))
      .map((v) => ({
        value: v.id,
        label: v.product?.name
          ? `${v.product.name} › ${v.name || v.sku || v.id}`
          : v.name || v.sku || v.id,
      }));
  }, [allVariants, form.sku_ids]);

  // Map for rendering selected chips below the picker.
  const selectedVariants = useMemo(() => {
    const byId = new Map(allVariants.map((v) => [v.id, v]));
    return form.sku_ids.map((id) => {
      const v = byId.get(id);
      return {
        id,
        label: v
          ? v.product?.name
            ? `${v.product.name} › ${v.name || v.sku || v.id}`
            : v.name || v.sku || v.id
          : id,
      };
    });
  }, [allVariants, form.sku_ids]);

  // Per-row variant data is fetched on demand from /products/{id} (which
  // embeds `skus`). useQueries de-dupes via React Query cache.
  const rowProductIds = useMemo(() => {
    const ids = new Set<string>();
    for (const row of form.ingredients) {
      if (row.type === "variant" && row.product_id) ids.add(row.product_id);
    }
    return Array.from(ids);
  }, [form.ingredients]);

  const { skusByProduct, productDetailResults } = useQueries({
    queries: rowProductIds.map((id) => ({
      queryKey: productKeys.detail(brandSlug, locale, id),
      queryFn: () => productService.getById(brandSlug, id),
      enabled: !!brandSlug && !!id,
      staleTime: 5 * 60 * 1000,
    })),
    combine: (results) => {
      const map = new Map<string, ProductSku[]>();
      results.forEach((q, i) => {
        const product = (q.data as ProductDetailResponse | undefined)?.data;
        if (product) map.set(rowProductIds[i], product.skus ?? []);
      });
      return { skusByProduct: map, productDetailResults: results };
    },
  });

  const isProductDetailLoading = (productId: string): boolean => {
    const idx = rowProductIds.indexOf(productId);
    if (idx < 0) return false;
    const q = productDetailResults[idx];
    return !!q && (q.isLoading || q.isFetching);
  };

  const createMutation = useCreateRecipe(brandSlug);

  // Plan-022 — output_unit is locked to the picked material's yield_unit
  // when the material is already a produced BTP (BE enforces
  // `output_unit ∈ material.material_units`, so a free-text mismatch is a
  // footgun). output_quantity pre-fills from the material's yield_quantity
  // as a *suggested default* but stays editable — recipes can scale up/down
  // (small test batch vs production scale). Raw materials keep both inputs
  // editable; first recipe approval stamps the values onto the material.
  const selectedMaterial = useMemo(
    () => allMaterials.find((m) => m.id === form.material_id) ?? null,
    [allMaterials, form.material_id]
  );
  // Lock output_unit only when the recipe outputs a produced material AND
  // that material already has a yield_unit on file — SKU-mode recipes always
  // keep the unit field free-text (no canonical unit to lock to).
  const isOutputUnitLocked = form.output_kind === "material" && !!selectedMaterial?.yield_unit;

  useEffect(() => {
    if (!selectedMaterial?.yield_unit) return;
    setForm((prev) => {
      const next = { ...prev };
      // Output unit always mirrors the material — non-negotiable.
      next.output_unit = selectedMaterial.yield_unit ?? "";
      // Pre-fill quantity ONLY if the user hasn't touched it yet
      // (default "1" or empty). Otherwise respect their override.
      if (prev.output_quantity === "" || prev.output_quantity === "1") {
        next.output_quantity = String(selectedMaterial.yield_quantity ?? prev.output_quantity);
      }
      return next;
    });
  }, [selectedMaterial]);

  // ---------------------------------------------------------------------------
  //  Helpers
  // ---------------------------------------------------------------------------

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setDirty(true);
    setForm((prev) => ({ ...prev, [key]: value }));
    if (fieldErrors[key as string]) {
      setFieldErrors((prev) => {
        const next = { ...prev };
        delete next[key as string];
        return next;
      });
    }
  }

  function updateIngredient(index: number, patch: Partial<IngredientRow>) {
    setDirty(true);
    setForm((prev) => {
      const next = [...prev.ingredients];
      next[index] = { ...next[index], ...patch };
      return { ...prev, ingredients: next };
    });
  }

  function addIngredient() {
    setDirty(true);
    setForm((prev) => ({
      ...prev,
      ingredients: [
        ...prev.ingredients,
        {
          type: "variant",
          product_id: "",
          product_name: "",
          variant_id: "",
          material_id: "",
          unit_id: "",
          unit: "",
          quantity: "1",
        },
      ],
    }));
  }

  function removeIngredient(index: number) {
    setDirty(true);
    setForm((prev) => ({
      ...prev,
      ingredients: prev.ingredients.filter((_, i) => i !== index),
    }));
  }

  // Cancel guards unsaved changes: confirm before discarding a dirty form,
  // otherwise navigate straight back to the list.
  function handleCancel() {
    if (dirty) {
      setConfirmExitOpen(true);
    } else {
      router.push(`/hq/${brandSlug}/recipes`);
    }
  }

  // Pick the first non-empty locale value in ja → en → vi priority.
  // Mirrors materials/new so the user can fill any single locale and the
  // top-level scalar field (used by the BE as the source-of-truth + by
  // search/sort indexes) is never empty.
  const effectiveName =
    form.name[DEFAULT_LOCALE]?.trim() || form.name["en"]?.trim() || form.name["vi"]?.trim() || "";
  const effectiveDescription =
    form.description[DEFAULT_LOCALE]?.trim() ||
    Object.values(form.description).find((v) => v?.trim()) ||
    "";
  const effectiveInstructions =
    form.instructions[DEFAULT_LOCALE]?.trim() ||
    Object.values(form.instructions).find((v) => v?.trim()) ||
    "";
  const hasDescription = effectiveDescription.length > 0;
  const hasInstructions = effectiveInstructions.length > 0;

  // An ingredient row counts as "filled" once the picker for its type has a
  // value AND quantity is > 0. The Save button needs at least one such row,
  // matching the backend rule that recipes must consume something.
  const hasValidIngredient = form.ingredients.some((row) => {
    const ref = row.type === "variant" ? row.variant_id : row.material_id;
    return !!ref && Number(row.quantity) > 0;
  });

  // ---------------------------------------------------------------------------
  //  Submit
  // ---------------------------------------------------------------------------

  async function handleSubmit() {
    setFieldErrors({});

    // Client-side guard for the two required relations. The Save button is
    // already disabled in these cases, but keep the check so direct callers
    // (and future entry points) surface the same inline errors.
    const localErrors: Record<string, string[]> = {};
    if (form.output_kind === "material" && !form.material_id) {
      localErrors.material_id = [t("hq.recipes.field.output_material_required")];
    }
    if (form.output_kind === "sku" && form.sku_ids.length === 0) {
      localErrors.sku_ids = [t("hq.recipes.field.output_sku_required")];
    }
    if (!hasValidIngredient) {
      localErrors.ingredients = [t("hq.recipes.form.ingredients_required")];
    }
    if (Object.keys(localErrors).length > 0) {
      setFieldErrors(localErrors);
      return;
    }

    const ingredients: RecipeIngredient[] = form.ingredients.map((row) => ({
      type: row.type,
      variant_id: row.type === "variant" ? row.variant_id || null : null,
      material_id: row.type === "material" ? row.material_id || null : null,
      unit_id: row.unit_id || null,
      // Plan-024 UX-3 — persist literal unit per row so downstream
      // material-consumption transactions can use the recipe-author intent
      // instead of the hard-coded `"piece"` fallback. Trim to null when
      // blank (backend treats null as "infer from linked material/SKU").
      unit: row.unit.trim() || null,
      quantity: Number(row.quantity) || 0,
    }));

    // Same pattern as materials/new: user only needs to fill ONE locale.
    // Empty locales are back-filled in priority order (ja → en → vi) so the
    // backend never stores empty strings and Astrotomic fallback always
    // renders something. Rule 3 then strips locales whose required `name`
    // is empty (it won't be after the fill, but keep the guard).
    const i18n = buildI18nPayload({
      name: fillLocalesFallback(form.name),
      description: hasDescription ? fillLocalesFallback(form.description) : form.description,
      instructions: hasInstructions ? fillLocalesFallback(form.instructions) : form.instructions,
    });
    for (const locale of Object.keys(i18n) as Array<keyof typeof i18n>) {
      if (!i18n[locale]?.name?.trim()) delete i18n[locale];
    }

    const payload: CreateRecipeInput = {
      brand_id: brandId,
      name: effectiveName,
      description: effectiveDescription || null,
      // SKU-output mode never persists a material link — switching kinds
      // clears the orthogonal field so the server doesn't see a stale value.
      material_id: form.output_kind === "material" ? form.material_id || null : null,
      sku_ids: form.output_kind === "sku" ? form.sku_ids : [],
      output_quantity: Number(form.output_quantity) || 0,
      output_unit: form.output_unit.trim() || null,
      ingredients,
      instructions: effectiveInstructions || null,
      is_active: form.is_active,
      yield_variance_tolerance_pct: Math.max(0, Number(form.yield_variance_tolerance_pct) || 0),
      ...i18n,
    };

    try {
      await createMutation.mutateAsync(payload);
      router.push(`/hq/${brandSlug}/recipes`);
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const body = err.body as { errors?: Record<string, string[]> };
        if (body.errors) setFieldErrors(body.errors);
      }
    }
  }

  // ---------------------------------------------------------------------------
  //  Render
  // ---------------------------------------------------------------------------

  return (
    <>
      <PageHeader
        title={t("hq.recipes.new_page.title")}
        description={t("hq.recipes.new_page.description")}
      >
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8"
          onClick={handleCancel}
          disabled={createMutation.isPending}
        >
          {t("common.cancel")}
        </Button>
        <Button
          type="button"
          size="sm"
          className="h-8 gap-1.5"
          onClick={handleSubmit}
          disabled={
            !effectiveName ||
            (form.output_kind === "material" ? !form.material_id : form.sku_ids.length === 0) ||
            !hasValidIngredient ||
            createMutation.isPending
          }
        >
          {createMutation.isPending ? (
            <Spinner className="size-3.5" />
          ) : (
            <Save className="size-3.5" />
          )}
          {t("hq.recipes.save")}
        </Button>
      </PageHeader>

      <PageContent>
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          {/* LEFT: main content */}
          <div className="flex flex-col gap-4 lg:col-span-8">
            {/* Basic info */}
            <Card className="p-4">
              <div className="mb-4 text-sm font-semibold">{t("hq.recipes.section.basic_info")}</div>
              <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">
                    {t("hq.recipes.field.name")}
                    <span className="ml-0.5 text-destructive">*</span>
                  </Label>
                  <Input
                    translatable
                    value={form.name}
                    onChange={(value) => update("name", value)}
                    maxLength={255}
                    autoFocus
                    placeholder={t("hq.recipes.field.name_placeholder")}
                    className="h-9"
                  />
                  {fieldErrors.name?.[0] && (
                    <p className="text-[11px] text-red-500">{fieldErrors.name[0]}</p>
                  )}
                </div>

                <div className="flex flex-col gap-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">
                    {t("hq.recipes.field.description")}
                  </Label>
                  <TranslatableRichText
                    value={form.description}
                    onChange={(value) => update("description", value)}
                  />
                  {fieldErrors.description?.[0] && (
                    <p className="text-[11px] text-red-500">{fieldErrors.description[0]}</p>
                  )}
                </div>

                {/* Output picker — Material or Product SKU. The recipe writes
                    exactly one output kind to the server: switching tabs
                    clears the orthogonal selection so the operator can't
                    accidentally save both at once. */}
                <div className="flex flex-col gap-2 border-t border-dashed pt-4">
                  <Label className="text-xs font-medium text-muted-foreground">
                    {t("hq.recipes.field.output_kind")}
                    <span className="ml-0.5 text-destructive">*</span>
                  </Label>
                  <div
                    role="tablist"
                    className="inline-flex rounded-md border border-input bg-muted/30 p-0.5 text-xs"
                  >
                    {(["material", "sku"] as const).map((kind) => (
                      <button
                        key={kind}
                        type="button"
                        role="tab"
                        aria-selected={form.output_kind === kind}
                        onClick={() => {
                          if (form.output_kind === kind) return;
                          setDirty(true);
                          // Switching kind clears the orthogonal value so the
                          // submit payload is unambiguous.
                          setForm((prev) => ({
                            ...prev,
                            output_kind: kind,
                            material_id: kind === "material" ? prev.material_id : "",
                            sku_ids: kind === "sku" ? prev.sku_ids : [],
                          }));
                        }}
                        className={
                          "rounded-sm px-3 py-1 transition-colors " +
                          (form.output_kind === kind
                            ? "bg-background font-medium text-foreground shadow-sm"
                            : "text-muted-foreground hover:text-foreground")
                        }
                      >
                        {t(
                          kind === "material"
                            ? "hq.recipes.field.output_kind_material"
                            : "hq.recipes.field.output_kind_sku"
                        )}
                      </button>
                    ))}
                  </div>
                </div>

                {form.output_kind === "material" && (
                  <div className="flex flex-col gap-1.5">
                    <div className="flex items-center gap-1">
                      <Label className="text-xs font-medium text-muted-foreground">
                        {t("hq.recipes.field.output_material")}
                        <span className="ml-0.5 text-destructive">*</span>
                      </Label>
                      <Popover>
                        <PopoverTrigger asChild>
                          <button
                            type="button"
                            className="inline-flex size-5 cursor-pointer items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                            aria-label={t("common.help")}
                          >
                            <HelpCircle className="size-3.5" />
                          </button>
                        </PopoverTrigger>
                        <PopoverContent
                          align="start"
                          className="max-w-sm space-y-1 text-xs leading-relaxed text-muted-foreground"
                        >
                          <p>{t("hq.recipes.field.output_material_help_produced")}</p>
                        </PopoverContent>
                      </Popover>
                    </div>
                    <Select
                      value={form.material_id || undefined}
                      onValueChange={(v) => update("material_id", v ?? "")}
                      disabled={materialLookup.isLoading}
                    >
                      <SelectTrigger className="h-9 text-xs">
                        <SelectValue
                          placeholder={t("hq.recipes.field.output_material_placeholder")}
                        />
                      </SelectTrigger>
                      <SelectContent>
                        {allMaterials
                          .filter((m) => !!m.yield_unit)
                          .sort((a, b) => (a.name ?? "").localeCompare(b.name ?? ""))
                          .map((m) => (
                            <SelectItem key={m.id} value={m.id}>
                              <Factory className="mr-1 inline h-3.5 w-3.5 align-text-bottom" aria-hidden />
                              {m.name}
                            </SelectItem>
                          ))}
                      </SelectContent>
                    </Select>
                    {fieldErrors.material_id?.[0] && (
                      <p className="text-[11px] text-red-500">{fieldErrors.material_id[0]}</p>
                    )}
                  </div>
                )}

                {form.output_kind === "sku" && (
                  <div className="flex flex-col gap-1.5">
                    <div className="flex items-center gap-1">
                      <Label className="text-xs font-medium text-muted-foreground">
                        {t("hq.recipes.field.output_skus")}
                        <span className="ml-0.5 text-destructive">*</span>
                      </Label>
                      <Popover>
                        <PopoverTrigger asChild>
                          <button
                            type="button"
                            className="inline-flex size-5 cursor-pointer items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                            aria-label={t("common.help")}
                          >
                            <HelpCircle className="size-3.5" />
                          </button>
                        </PopoverTrigger>
                        <PopoverContent
                          align="start"
                          className="max-w-sm space-y-1 text-xs leading-relaxed text-muted-foreground"
                        >
                          <p>{t("hq.recipes.field.output_skus_help")}</p>
                        </PopoverContent>
                      </Popover>
                    </div>
                    <Combobox
                      options={variantOptions}
                      value=""
                      onChange={(id) => {
                        if (!id) return;
                        setDirty(true);
                        setForm((prev) =>
                          prev.sku_ids.includes(id)
                            ? prev
                            : { ...prev, sku_ids: [...prev.sku_ids, id] }
                        );
                        if (fieldErrors.sku_ids) {
                          setFieldErrors((prev) => {
                            const next = { ...prev };
                            delete next.sku_ids;
                            return next;
                          });
                        }
                      }}
                      placeholder={
                        variantLookup.isLoading
                          ? t("common.loading")
                          : t("hq.recipes.field.output_skus_placeholder")
                      }
                      searchPlaceholder={t("hq.recipes.field.output_skus_search_placeholder")}
                      clearable={false}
                    />
                    {selectedVariants.length > 0 && (
                      <div className="flex flex-wrap gap-1.5 pt-1">
                        {selectedVariants.map((v) => (
                          <span
                            key={v.id}
                            className="inline-flex items-center gap-1 rounded-full border border-input bg-muted px-2 py-0.5 text-[11px]"
                          >
                            {v.label}
                            <button
                              type="button"
                              onClick={() => {
                                setDirty(true);
                                setForm((prev) => ({
                                  ...prev,
                                  sku_ids: prev.sku_ids.filter((sid) => sid !== v.id),
                                }));
                              }}
                              className="inline-flex items-center text-muted-foreground hover:text-foreground"
                              aria-label={t("common.remove")}
                            >
                              <X className="size-3" />
                            </button>
                          </span>
                        ))}
                      </div>
                    )}
                    {fieldErrors.sku_ids?.[0] && (
                      <p className="text-[11px] text-red-500">{fieldErrors.sku_ids[0]}</p>
                    )}
                  </div>
                )}

                <div className="grid grid-cols-2 gap-4 border-t border-dashed pt-4">
                  <div className="flex flex-col gap-1.5">
                    <div className="flex items-center gap-1">
                      <Label className="text-xs font-medium text-muted-foreground">
                        {t("hq.recipes.field.output_quantity")}
                        <span className="ml-0.5 text-destructive">*</span>
                      </Label>
                      <Popover>
                        <PopoverTrigger asChild>
                          <button
                            type="button"
                            className="inline-flex size-5 cursor-pointer items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                            aria-label={t("common.help")}
                          >
                            <HelpCircle className="size-3.5" />
                          </button>
                        </PopoverTrigger>
                        <PopoverContent
                          align="start"
                          className="max-w-sm text-xs leading-relaxed text-muted-foreground"
                        >
                          {t("hq.recipes.field.output_quantity_help")}
                        </PopoverContent>
                      </Popover>
                    </div>
                    <Input
                      type="number"
                      min="0"
                      step="0.0001"
                      value={form.output_quantity}
                      onChange={(e) => update("output_quantity", e.target.value)}
                      className="h-9"
                    />
                    {fieldErrors.output_quantity?.[0] && (
                      <p className="text-[11px] text-red-500">{fieldErrors.output_quantity[0]}</p>
                    )}
                    {/* Plan-024 BUG-C — preview the per-order material draw so
                        the author sees immediately how output_quantity scales
                        deduction. Backend formula:
                          consumption = ingredient.qty / output_quantity × order_qty
                        If output_quantity = material yield (e.g. 1000g) but
                        the recipe is "1 serving", users get 1/1000 the
                        expected consumption — this hint surfaces that. */}
                    {(() => {
                      const outputQty = Number(form.output_quantity);
                      const firstIngredient = form.ingredients.find(
                        (r) => r.type === "material" && r.material_id && Number(r.quantity) > 0
                      );
                      if (!Number.isFinite(outputQty) || outputQty <= 0 || !firstIngredient) {
                        return null;
                      }
                      const ingredientName =
                        allMaterials.find((m) => m.id === firstIngredient.material_id)?.name ??
                        t("hq.materials.title");
                      const perOrder = Number(firstIngredient.quantity) / outputQty;
                      const displayUnit =
                        firstIngredient.unit.trim() ||
                        allMaterials.find((m) => m.id === firstIngredient.material_id)
                          ?.yield_unit ||
                        "";
                      return (
                        <p className="text-[11px] leading-snug text-muted-foreground">
                          {t("hq.recipes.field.output_quantity_preview", {
                            ingredient: ingredientName,
                            qty: perOrder.toLocaleString(undefined, {
                              maximumFractionDigits: 4,
                            }),
                            unit: displayUnit,
                          })}
                        </p>
                      );
                    })()}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <div className="flex items-center gap-1">
                      <Label className="text-xs font-medium text-muted-foreground">
                        {t("hq.recipes.field.output_unit")}
                      </Label>
                      <Popover>
                        <PopoverTrigger asChild>
                          <button
                            type="button"
                            className="inline-flex size-5 cursor-pointer items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                            aria-label={t("common.help")}
                          >
                            <HelpCircle className="size-3.5" />
                          </button>
                        </PopoverTrigger>
                        <PopoverContent
                          align="start"
                          className="max-w-sm text-xs leading-relaxed text-muted-foreground"
                        >
                          {t("hq.recipes.field.output_unit_help")}
                        </PopoverContent>
                      </Popover>
                    </div>
                    <Input
                      value={form.output_unit}
                      onChange={(e) => update("output_unit", e.target.value)}
                      maxLength={50}
                      placeholder={t("hq.recipes.field.output_unit_placeholder")}
                      className="h-9"
                      readOnly={isOutputUnitLocked}
                      disabled={isOutputUnitLocked}
                    />
                    {fieldErrors.output_unit?.[0] && (
                      <p className="text-[11px] text-red-500">{fieldErrors.output_unit[0]}</p>
                    )}
                  </div>
                  <p className="col-span-2 text-[11px] text-muted-foreground">
                    {form.output_kind === "sku"
                      ? t("hq.recipes.field.output_sku_hint")
                      : isOutputUnitLocked
                        ? t("hq.recipes.field.output_locked_hint")
                        : selectedMaterial
                          ? t("hq.recipes.field.output_raw_hint")
                          : t("hq.recipes.field.output_pick_material_hint")}
                  </p>
                  <div className="col-span-2 flex flex-col gap-1.5">
                    <div className="flex items-center gap-1">
                      <Label className="text-xs font-medium text-muted-foreground">
                        {t("hq.recipes.field.yield_variance_tolerance_pct")}
                      </Label>
                      <Popover>
                        <PopoverTrigger asChild>
                          <button
                            type="button"
                            className="inline-flex size-5 cursor-pointer items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                            aria-label={t("common.help")}
                          >
                            <HelpCircle className="size-3.5" />
                          </button>
                        </PopoverTrigger>
                        <PopoverContent
                          align="start"
                          className="max-w-sm space-y-1 text-xs leading-relaxed text-muted-foreground"
                        >
                          <p>{t("hq.recipes.field.yield_variance_tolerance_pct_help_main")}</p>
                          <p>{t("hq.recipes.field.yield_variance_tolerance_pct_help_zero")}</p>
                        </PopoverContent>
                      </Popover>
                    </div>
                    <div className="flex items-center gap-2">
                      <Input
                        type="number"
                        min="0"
                        max="100"
                        step="0.01"
                        value={form.yield_variance_tolerance_pct}
                        onChange={(e) => update("yield_variance_tolerance_pct", e.target.value)}
                        className="h-9 max-w-32"
                      />
                      <span className="text-xs text-muted-foreground">%</span>
                    </div>
                    {fieldErrors.yield_variance_tolerance_pct?.[0] && (
                      <p className="text-[11px] text-red-500">
                        {fieldErrors.yield_variance_tolerance_pct[0]}
                      </p>
                    )}
                  </div>
                </div>
              </div>
            </Card>

            {/* Ingredients */}
            <Card className="p-4">
              <div className="mb-4 text-sm font-semibold">
                {t("hq.recipes.section.ingredients")}
                <span className="ml-0.5 text-destructive">*</span>
              </div>
              <div className="flex flex-col gap-3">
                {form.ingredients.length === 0 && (
                  <div className="rounded-md border border-dashed border-input px-3 py-4 text-center text-xs text-muted-foreground">
                    {t("hq.recipes.form.no_ingredients")}
                  </div>
                )}
                {fieldErrors.ingredients?.[0] && (
                  <p className="text-[11px] text-red-500">{fieldErrors.ingredients[0]}</p>
                )}

                {form.ingredients.map((row, i) => {
                  const productSkus = row.product_id
                    ? (skusByProduct.get(row.product_id) ?? [])
                    : [];
                  const selectedMaterial =
                    row.type === "material" && row.material_id
                      ? allMaterials.find((m) => m.id === row.material_id)
                      : null;
                  const variantLoading = row.product_id
                    ? isProductDetailLoading(row.product_id)
                    : false;

                  return (
                    <div
                      key={i}
                      className="flex flex-wrap items-end gap-2 rounded-md border border-input bg-muted/30 p-3"
                    >
                      {/* Type */}
                      <div className="flex flex-col gap-1.5">
                        <Label className="text-xs font-medium text-muted-foreground">
                          {t("common.type")}
                        </Label>
                        <Select
                          value={row.type}
                          onValueChange={(v) =>
                            updateIngredient(i, {
                              type: v as IngredientType,
                              product_id: "",
                              product_name: "",
                              variant_id: "",
                              material_id: "",
                              unit_id: "",
                            })
                          }
                        >
                          <SelectTrigger className="h-9 w-28 text-xs">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="variant">
                              {t("hq.common.component_type.variant")}
                            </SelectItem>
                            <SelectItem value="material">
                              {t("hq.common.component_type.material")}
                            </SelectItem>
                          </SelectContent>
                        </Select>
                      </div>

                      {/* Product (variant type only) */}
                      {row.type === "variant" && (
                        <div className="flex min-w-50 flex-1 flex-col gap-1.5">
                          <Label className="text-xs font-medium text-muted-foreground">
                            {t("hq.materials.form.product")}
                          </Label>
                          <ProductSearchSelect
                            brandSlug={brandSlug}
                            value={row.product_id}
                            label={row.product_name}
                            onChange={(id, name) =>
                              updateIngredient(i, {
                                product_id: id,
                                product_name: name,
                                variant_id: "",
                                unit_id: "",
                              })
                            }
                          />
                        </div>
                      )}

                      {/* Variant / Material selector */}
                      <div className="flex min-w-50 flex-1 flex-col gap-1.5">
                        <Label className="text-xs font-medium text-muted-foreground">
                          {row.type === "variant"
                            ? t("hq.materials.form.variant_sku")
                            : t("hq.materials.title")}
                        </Label>
                        {row.type === "variant" ? (
                          <Select
                            value={row.variant_id || undefined}
                            onValueChange={(v) =>
                              updateIngredient(i, { variant_id: v ?? "", unit_id: "" })
                            }
                            disabled={variantLoading || !row.product_id}
                          >
                            <SelectTrigger className="h-9 w-full text-xs">
                              <SelectValue
                                placeholder={
                                  !row.product_id
                                    ? t("hq.materials.form.select_product_first")
                                    : variantLoading
                                      ? t("common.loading")
                                      : t("hq.materials.form.select_variant")
                                }
                              />
                            </SelectTrigger>
                            <SelectContent>
                              {productSkus.map((s) => (
                                <SelectItem key={s.id} value={s.id}>
                                  {s.name || s.sku || s.id}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        ) : (
                          <Select
                            value={row.material_id || undefined}
                            onValueChange={(v) => {
                              const picked = allMaterials.find((m) => m.id === v);
                              // Default to the material's base unit (units are
                              // base-first). A unit from the previous material
                              // would be invalid for the new one, so reset
                              // rather than preserve it. Falls back to
                              // yield_unit for materials with no registered
                              // units.
                              const base =
                                picked?.units?.find((u) => u.is_base) ?? picked?.units?.[0];
                              updateIngredient(i, {
                                material_id: v ?? "",
                                unit: base?.unit ?? picked?.yield_unit ?? "",
                              });
                            }}
                            disabled={materialLookup.isLoading}
                          >
                            <SelectTrigger className="h-9 w-full text-xs">
                              <SelectValue placeholder={t("hq.materials.form.select_material")} />
                            </SelectTrigger>
                            <SelectContent>
                              {allMaterials
                                // Plan-022 T18 B3 — hide the recipe's own
                                // output material so the user can't pick
                                // itself as an ingredient.
                                .filter((m) => m.id !== form.material_id)
                                .map((m) => (
                                  <SelectItem key={m.id} value={m.id}>
                                    {m.yield_unit ? <Factory className="mr-1 inline h-3.5 w-3.5 align-text-bottom" aria-hidden /> : <Package className="mr-1 inline h-3.5 w-3.5 align-text-bottom" aria-hidden />}
                                    {m.name}
                                  </SelectItem>
                                ))}
                            </SelectContent>
                          </Select>
                        )}
                      </div>

                      {/* Quantity */}
                      <div className="flex w-24 flex-col gap-1">
                        <label className="text-[11px] font-medium text-muted-foreground">
                          {t("hq.recipes.field.qty")}
                        </label>
                        <Input
                          type="number"
                          min="0"
                          step="0.0001"
                          value={row.quantity}
                          onChange={(e) => updateIngredient(i, { quantity: e.target.value })}
                          className="h-9 text-xs"
                        />
                      </div>

                      {/* Plan-024 UX-3 — editable unit input. Defaults to
                          the picked material's yield_unit (auto-filled on
                          selection) but the recipe author may override
                          (e.g. recipe uses `kg` while material yields `g`).
                          The literal string is persisted alongside
                          `unit_id`; downstream material-consumption
                          transactions prefer this over the hard-coded
                          `"piece"` fallback. */}
                      {row.type === "material" ? (
                        <div className="flex w-28 flex-col gap-1.5">
                          <Label className="text-xs font-medium text-muted-foreground">
                            {t("hq.materials.form.unit")}
                          </Label>
                          {selectedMaterial?.units && selectedMaterial.units.length > 0 ? (
                            <Select
                              value={row.unit || undefined}
                              onValueChange={(v) => updateIngredient(i, { unit: v })}
                            >
                              <SelectTrigger className="h-9 w-full text-xs">
                                <SelectValue
                                  placeholder={t("material_lot.field_unit_placeholder")}
                                />
                              </SelectTrigger>
                              <SelectContent>
                                {selectedMaterial.units.map((u) => (
                                  <SelectItem key={u.unit} value={u.unit}>
                                    {u.is_base ? u.unit : `${u.unit} (×${u.ratio})`}
                                  </SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                          ) : (
                            <Input
                              value={row.unit}
                              onChange={(e) => updateIngredient(i, { unit: e.target.value })}
                              placeholder={
                                selectedMaterial?.yield_unit ??
                                t("hq.recipes.form.unit_placeholder")
                              }
                              className="h-9 text-xs"
                            />
                          )}
                        </div>
                      ) : null}

                      {/* Remove */}
                      <button
                        type="button"
                        onClick={() => removeIngredient(i)}
                        className="ml-auto inline-flex size-9 items-center justify-center rounded-md text-destructive hover:bg-destructive/10"
                        aria-label={t("hq.recipes.form.remove_ingredient")}
                      >
                        <Trash2 className="size-3.5" />
                      </button>
                    </div>
                  );
                })}

                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 w-full gap-1 border-dashed text-xs"
                  onClick={addIngredient}
                >
                  <Plus className="size-3.5" /> {t("hq.recipes.form.add_ingredient")}
                </Button>
              </div>
            </Card>

            {/* Instructions */}
            <Card className="p-4">
              <div className="mb-4 text-sm font-semibold">
                {t("hq.recipes.section.instructions")}
              </div>
              <div className="flex flex-col gap-1.5">
                <Label className="text-xs font-medium text-muted-foreground">
                  {t("hq.recipes.field.preparation_steps")}
                </Label>
                <TranslatableRichText
                  value={form.instructions}
                  onChange={(value) => update("instructions", value)}
                />
                {fieldErrors.instructions?.[0] && (
                  <p className="text-[11px] text-red-500">{fieldErrors.instructions[0]}</p>
                )}
              </div>
            </Card>
          </div>

          {/* RIGHT: sidebar */}
          <div className="flex flex-col gap-4 lg:col-span-4">
            <Card className="p-4">
              <div className="mb-3 text-sm font-semibold">{t("hq.recipes.section.status")}</div>
              <label className="flex cursor-pointer items-center gap-2 text-xs">
                <Checkbox
                  checked={form.is_active}
                  onCheckedChange={(v) => update("is_active", v === true)}
                />
                {t("hq.recipes.field.active")}
              </label>
            </Card>
          </div>
        </div>
      </PageContent>

      {/* Unsaved-changes guard — confirm before discarding a dirty new form. */}
      <AlertDialog open={confirmExitOpen} onOpenChange={setConfirmExitOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.products.unsaved.title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("hq.products.unsaved.desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={createMutation.isPending}>
              {t("hq.products.unsaved.continue_editing")}
            </AlertDialogCancel>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => {
                setConfirmExitOpen(false);
                router.push(`/hq/${brandSlug}/recipes`);
              }}
              disabled={createMutation.isPending}
            >
              {t("hq.products.unsaved.exit_without_saving")}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
