"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { notFound, useParams, useRouter } from "next/navigation";
import { useQueries } from "@tanstack/react-query";
import {
  CheckCircle2,
  HelpCircle,
  PauseCircle,
  PlayCircle,
  Plus,
  Save,
  Send,
  Trash2,
  X,
  XCircle,
  Factory,
  Package,
} from "lucide-react";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { Button } from "@godxjp/ui";
import { Card } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import { Checkbox } from "@godxjp/ui";
import { Label } from "@godxjp/ui";
import { Popover, PopoverContent, PopoverTrigger } from "@godxjp/ui";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";
import { Combobox, type ComboboxOption } from "@godxjp/ui";
import { TranslatableRichText } from "@godxjp/ui";
import { ProductSearchSelect } from "@/components/shared/product-search-select";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@godxjp/ui";
import type { TranslatableValue } from "@godxjp/ui";
import { ApiError } from "@/lib/api";
import { Spinner } from "@godxjp/ui";
import { Alert, AlertDescription, AlertTitle } from "@godxjp/ui";
import { DEFAULT_LOCALE } from "@/i18n";
import { buildI18nPayload, emptyLocaleMap } from "@/types/models/payload-helpers";
import { fillLocalesFallback } from "@/lib/i18n-fill";
import { useTranslation } from "@/providers/app-provider";
import {
  useApproveRecipe,
  useDeleteRecipe,
  useRecipe,
  useSubmitRecipeForApproval,
  useUpdateRecipe,
} from "@/hooks/api/use-recipes";
import { useMaterialLookup, useVariantLookup } from "@/hooks/api/use-materials";
import { productKeys } from "@/hooks/api/query-keys";
import { productService, type ProductSku } from "@/services/product-service";
import { ApprovalStatus } from "@/types/models/enum/ApprovalStatus";
import type {
  UpdateRecipeInput,
  RecipeIngredient,
  IngredientType,
} from "@/services/recipe-service";
import { RejectRecipeDialog } from "../components/reject-recipe-dialog";

interface IngredientRow {
  type: IngredientType;
  product_id: string;
  product_name: string;
  variant_id: string;
  material_id: string;
  unit_id: string;
  /** Plan-024 UX-3 — literal unit per row (e.g. `g`, `ml`). */
  unit: string;
  quantity: string;
}

type ProductDetailResponse = Awaited<ReturnType<typeof productService.getById>>;

type OutputKind = "material" | "sku";

interface FormState {
  name: TranslatableValue;
  description: TranslatableValue;
  /** Which side of the output picker is active. See recipes/new/page.tsx. */
  output_kind: OutputKind;
  /** Plan-022 T18 — output material the recipe produces. */
  material_id: string;
  sku_ids: string[];
  output_quantity: string;
  output_unit: string;
  ingredients: IngredientRow[];
  instructions: TranslatableValue;
  is_active: boolean;
  /** Plan-022 (yield variance) — % drift allowed before reason is required. */
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

export default function EditRecipePage() {
  const params = useParams<{ brandSlug: string; id: string }>();
  const router = useRouter();
  const { brandSlug, id } = params;
  const { t, locale } = useTranslation();

  const [form, setForm] = useState<FormState>(emptyFormState);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [hydrated, setHydrated] = useState(false);

  const { data: recipeResponse, isLoading, error } = useRecipe(brandSlug, id);
  const recipe = recipeResponse?.data;

  const materialLookup = useMaterialLookup(brandSlug, true);
  const allMaterials = useMemo(() => materialLookup.data?.data ?? [], [materialLookup.data]);

  const variantLookup = useVariantLookup(brandSlug, true);
  const allVariants = useMemo(() => variantLookup.data?.data ?? [], [variantLookup.data]);
  const variantOptions = useMemo<ComboboxOption[]>(() => {
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
  const selectedVariants = useMemo(() => {
    const byId = new Map(allVariants.map((v) => [v.id, v]));
    // Carry over the SKU name embedded in the recipe payload so chips render
    // immediately on first paint, before the variant lookup has resolved.
    const fallbackById = new Map(
      (recipe?.skus ?? []).map((s) => [
        s.id,
        s.product?.name
          ? `${s.product.name} › ${s.name || s.sku || s.id}`
          : s.name || s.sku || s.id,
      ])
    );
    return form.sku_ids.map((id) => {
      const v = byId.get(id);
      const label =
        (v &&
          (v.product?.name
            ? `${v.product.name} › ${v.name || v.sku || v.id}`
            : v.name || v.sku || v.id)) ??
        fallbackById.get(id) ??
        id;
      return { id, label };
    });
  }, [allVariants, form.sku_ids, recipe]);

  // Hydrate form once recipe data arrives
  useEffect(() => {
    if (!recipe || hydrated) return;

    const nameMap = emptyLocaleMap();
    const descMap = emptyLocaleMap();
    const instrMap = emptyLocaleMap();
    if (recipe.translations) {
      for (const locale of ["ja", "en", "vi"] as const) {
        nameMap[locale] = recipe.translations[locale]?.name ?? "";
        descMap[locale] = recipe.translations[locale]?.description ?? "";
        instrMap[locale] = recipe.translations[locale]?.instructions ?? "";
      }
    } else {
      nameMap[DEFAULT_LOCALE] = recipe.name ?? "";
      descMap[DEFAULT_LOCALE] = recipe.description ?? "";
      instrMap[DEFAULT_LOCALE] = recipe.instructions ?? "";
    }

    const ingredients: IngredientRow[] = (recipe.ingredients ?? []).map((ing) => ({
      type: ing.type as IngredientType,
      product_id: "",
      product_name: "",
      variant_id: ing.variant_id ?? "",
      material_id: ing.material_id ?? "",
      unit_id: ing.unit_id ?? "",
      // Plan-024 UX-3 — hydrate the literal unit. Older recipes saved with
      // the hard-coded "piece" fallback show that on load; the user can
      // correct it inline.
      unit: ing.unit ?? "",
      quantity: String(ing.quantity ?? 1),
    }));

    // Output kind is implicit on the server — we infer from which side has
    // data. A recipe with no Material but ≥ 1 attached SKU is sku-mode;
    // anything else (including empty drafts) is material-mode by default.
    const attachedSkus = (recipe.skus ?? []).map((s) => s.id);
    const inferredKind: OutputKind =
      !recipe.material_id && attachedSkus.length > 0 ? "sku" : "material";

    setForm({
      name: nameMap,
      description: descMap,
      output_kind: inferredKind,
      material_id: recipe.material_id ?? "",
      sku_ids: attachedSkus,
      output_quantity: String(recipe.output_quantity ?? 1),
      output_unit: recipe.output_unit ?? "",
      ingredients,
      instructions: instrMap,
      is_active: recipe.is_active,
      yield_variance_tolerance_pct: String(recipe.yield_variance_tolerance_pct ?? 0),
    });
    setHydrated(true);
  }, [recipe, hydrated]);

  // Backfill product_id/product_name for variant ingredients from lookup
  useEffect(() => {
    if (!variantLookup.data?.data) return;
    const lookupMap = new Map(variantLookup.data.data.map((v) => [v.id, v]));
    setForm((prev) => {
      const updated = prev.ingredients.map((row) => {
        if (row.type === "variant" && row.variant_id && !row.product_id) {
          const v = lookupMap.get(row.variant_id);
          if (v?.product) {
            return { ...row, product_id: v.product.id, product_name: v.product.name };
          }
        }
        return row;
      });
      const changed = updated.some((row, i) => row.product_id !== prev.ingredients[i]?.product_id);
      return changed ? { ...prev, ingredients: updated } : prev;
    });
  }, [variantLookup.data]);

  // Plan-022 — output_unit locks to the picked material's yield_unit when
  // the material is a produced BTP (BE enforces unit ∈ material_units).
  // output_quantity pre-fills from yield_quantity but stays editable so
  // recipes can scale up/down per batch size. Raw materials keep both
  // inputs editable; first recipe approval stamps the values onto the
  // material.
  const selectedMaterial = useMemo(
    () => allMaterials.find((m) => m.id === form.material_id) ?? null,
    [allMaterials, form.material_id]
  );
  const isOutputUnitLocked = form.output_kind === "material" && !!selectedMaterial?.yield_unit;

  useEffect(() => {
    if (!hydrated || !selectedMaterial?.yield_unit) return;
    setForm((prev) => {
      const nextUnit = selectedMaterial.yield_unit ?? "";
      if (prev.output_unit === nextUnit) return prev;
      return { ...prev, output_unit: nextUnit };
    });
  }, [hydrated, selectedMaterial]);

  const rowProductIds = useMemo(() => {
    const ids = new Set<string>();
    for (const row of form.ingredients) {
      if (row.type === "variant" && row.product_id) ids.add(row.product_id);
    }
    return Array.from(ids);
  }, [form.ingredients]);

  const { skusByProduct, productDetailResults } = useQueries({
    queries: rowProductIds.map((pid) => ({
      queryKey: productKeys.detail(brandSlug, locale, pid),
      queryFn: () => productService.getById(brandSlug, pid),
      enabled: !!brandSlug && !!pid,
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

  const updateMutation = useUpdateRecipe(brandSlug);
  const deleteMutation = useDeleteRecipe(brandSlug);
  const submitForApproval = useSubmitRecipeForApproval(brandSlug);
  const approveMut = useApproveRecipe(brandSlug);

  // Header dialogs — confirmation gates for delete + unsaved-exit + reject.
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);
  const [rejectDialogOpen, setRejectDialogOpen] = useState(false);

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
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
    setForm((prev) => {
      const next = [...prev.ingredients];
      next[index] = { ...next[index], ...patch };
      return { ...prev, ingredients: next };
    });
  }

  function addIngredient() {
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
    setForm((prev) => ({
      ...prev,
      ingredients: prev.ingredients.filter((_, i) => i !== index),
    }));
  }

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

  // Detail-page-quick-actions skill — header derivations + handlers.
  //
  // Recipe has BOTH approval workflow (Draft → Pending → Approved → Rejected)
  // AND an is_active flag. Activate/Deactivate flip is_active via the same
  // update endpoint (there's no dedicated endpoint) but bypass the form so
  // the operator can toggle availability without saving in-progress edits.
  const isTrashed = !!recipe?.deleted_at;
  const approvalStatus = recipe?.approval_status ?? ApprovalStatus.Draft;
  const showSubmitWorkflow =
    !isTrashed &&
    (approvalStatus === ApprovalStatus.Draft || approvalStatus === ApprovalStatus.Rejected);
  const showApproveReject = !isTrashed && approvalStatus === ApprovalStatus.Pending;
  const showActivate = !isTrashed && recipe?.is_active === false;
  const showDeactivate = !isTrashed && recipe?.is_active === true;

  // Surface the rejection reason while the recipe is Rejected, and keep it
  // visible after a Rejected → Pending resubmit (the backend preserves it for
  // exactly this reason). Hidden once Approved/Draft so stale history doesn't
  // leak. Fixes the missing "lý do reject cũ" banner on the detail page.
  const showRejectionBanner =
    !!recipe?.rejection_reason &&
    (approvalStatus === ApprovalStatus.Rejected || approvalStatus === ApprovalStatus.Pending);

  const workflowPending = submitForApproval.isPending || approveMut.isPending;
  const disabledAll = updateMutation.isPending || deleteMutation.isPending || workflowPending;

  // Field-by-field comparison against the hydrated server snapshot. Drives
  // Save's enabled state + the Cancel unsaved-changes guard.
  const hasChanges = useMemo(() => {
    if (!hydrated || !recipe) return false;

    const localeEqual = (a: TranslatableValue, b: Record<string, string | null | undefined>) => {
      for (const l of ["ja", "en", "vi"] as const) {
        if ((a[l] ?? "") !== (b[l] ?? "")) return true;
      }
      return false;
    };
    const recipeTranslations = recipe.translations ?? {};
    if (
      localeEqual(form.name, {
        ja: recipeTranslations.ja?.name ?? recipe.name,
        en: recipeTranslations.en?.name,
        vi: recipeTranslations.vi?.name,
      })
    )
      return true;
    if (
      localeEqual(form.description, {
        ja: recipeTranslations.ja?.description ?? recipe.description,
        en: recipeTranslations.en?.description,
        vi: recipeTranslations.vi?.description,
      })
    )
      return true;
    if (
      localeEqual(form.instructions, {
        ja: recipeTranslations.ja?.instructions ?? recipe.instructions,
        en: recipeTranslations.en?.instructions,
        vi: recipeTranslations.vi?.instructions,
      })
    )
      return true;

    if ((form.material_id || "") !== (recipe.material_id ?? "")) return true;
    {
      // SKU-set drift: order doesn't matter, only membership.
      const serverIds = new Set((recipe.skus ?? []).map((s) => s.id));
      if (serverIds.size !== form.sku_ids.length) return true;
      for (const id of form.sku_ids) {
        if (!serverIds.has(id)) return true;
      }
    }
    if (Number(form.output_quantity) !== Number(recipe.output_quantity ?? 0)) return true;
    if ((form.output_unit || "") !== (recipe.output_unit ?? "")) return true;
    if (form.is_active !== recipe.is_active) return true;
    if (
      Number(form.yield_variance_tolerance_pct) !== Number(recipe.yield_variance_tolerance_pct ?? 0)
    )
      return true;

    const serverIngredients = recipe.ingredients ?? [];
    if (form.ingredients.length !== serverIngredients.length) return true;
    for (let i = 0; i < form.ingredients.length; i += 1) {
      const a = form.ingredients[i];
      const b = serverIngredients[i];
      if (a.type !== b.type) return true;
      if ((a.variant_id || null) !== (b.variant_id ?? null)) return true;
      if ((a.material_id || null) !== (b.material_id ?? null)) return true;
      if ((a.unit_id || null) !== (b.unit_id ?? null)) return true;
      // plan-040 H16: the literal `unit` string is a real, saveable field —
      // editing only g→kg must mark the form dirty + enable Save.
      if ((a.unit || null) !== (b.unit ?? null)) return true;
      if (Number(a.quantity) !== Number(b.quantity ?? 0)) return true;
    }
    return false;
  }, [hydrated, recipe, form]);

  // plan-040 M19: structural drift = ingredients OR output (material/sku/qty/unit).
  // The backend re-pends an Approved recipe whenever any of these change, so we
  // warn the operator inline before they Save. Name/description/instructions/
  // is_active/yield-tolerance are non-structural and do NOT re-pend.
  const isStructurallyDirty = useMemo(() => {
    if (!hydrated || !recipe) return false;

    if ((form.material_id || "") !== (recipe.material_id ?? "")) return true;
    {
      const serverIds = new Set((recipe.skus ?? []).map((s) => s.id));
      if (serverIds.size !== form.sku_ids.length) return true;
      for (const sid of form.sku_ids) {
        if (!serverIds.has(sid)) return true;
      }
    }
    if (Number(form.output_quantity) !== Number(recipe.output_quantity ?? 0)) return true;
    if ((form.output_unit || "") !== (recipe.output_unit ?? "")) return true;

    const serverIngredients = recipe.ingredients ?? [];
    if (form.ingredients.length !== serverIngredients.length) return true;
    for (let i = 0; i < form.ingredients.length; i += 1) {
      const a = form.ingredients[i];
      const b = serverIngredients[i];
      if (a.type !== b.type) return true;
      if ((a.variant_id || null) !== (b.variant_id ?? null)) return true;
      if ((a.material_id || null) !== (b.material_id ?? null)) return true;
      if ((a.unit_id || null) !== (b.unit_id ?? null)) return true;
      if ((a.unit || null) !== (b.unit ?? null)) return true;
      if (Number(a.quantity) !== Number(b.quantity ?? 0)) return true;
    }
    return false;
  }, [hydrated, recipe, form]);

  // plan-040 M19: only an Approved recipe can be knocked back to Pending by a
  // structural edit — show the re-approval warning exactly in that window.
  const showReapprovalWarning = approvalStatus === ApprovalStatus.Approved && isStructurallyDirty;

  const handleRequestExit = useCallback(() => {
    if (hasChanges) {
      setConfirmExitOpen(true);
      return;
    }
    router.push(`/hq/${brandSlug}/recipes`);
  }, [hasChanges, router, brandSlug]);

  async function handleActivate() {
    if (!recipe) return;
    try {
      await updateMutation.mutateAsync({ id: recipe.id, data: { is_active: true } });
      setForm((prev) => ({ ...prev, is_active: true }));
    } catch {
      /* toast fired by hook */
    }
  }

  async function handleDeactivate() {
    if (!recipe) return;
    try {
      await updateMutation.mutateAsync({ id: recipe.id, data: { is_active: false } });
      setForm((prev) => ({ ...prev, is_active: false }));
    } catch {
      /* toast fired by hook */
    }
  }

  async function handleSubmitForApproval() {
    if (!recipe) return;
    try {
      await submitForApproval.mutateAsync(recipe.id);
    } catch {
      /* toast fired by hook */
    }
  }

  async function handleApprove() {
    if (!recipe) return;
    try {
      await approveMut.mutateAsync(recipe.id);
    } catch {
      /* toast fired by hook */
    }
  }

  async function handleDelete() {
    if (!recipe) return;
    try {
      await deleteMutation.mutateAsync(recipe.id);
      setDeleteDialogOpen(false);
      router.push(`/hq/${brandSlug}/recipes`);
    } catch {
      /* toast fired by hook */
    }
  }

  async function handleSubmit() {
    setFieldErrors({});

    const ingredients: RecipeIngredient[] = form.ingredients.map((row) => ({
      type: row.type,
      variant_id: row.type === "variant" ? row.variant_id || null : null,
      material_id: row.type === "material" ? row.material_id || null : null,
      unit_id: row.unit_id || null,
      // Plan-024 UX-3 — persist literal unit.
      unit: row.unit.trim() || null,
      quantity: Number(row.quantity) || 0,
    }));

    const i18n = buildI18nPayload({
      name: fillLocalesFallback(form.name),
      description: effectiveDescription ? fillLocalesFallback(form.description) : form.description,
      instructions: effectiveInstructions
        ? fillLocalesFallback(form.instructions)
        : form.instructions,
    });
    for (const locale of Object.keys(i18n) as Array<keyof typeof i18n>) {
      if (!i18n[locale]?.name?.trim()) delete i18n[locale];
    }

    const payload: UpdateRecipeInput = {
      name: effectiveName,
      description: effectiveDescription || null,
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
      await updateMutation.mutateAsync({ id, data: payload });
      router.push(`/hq/${brandSlug}/recipes`);
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const body = err.body as { errors?: Record<string, string[]> };
        if (body.errors) setFieldErrors(body.errors);
      }
    }
  }

  // 404 / 403 → Next.js not-found page. MUST run before the loading guard: on
  // a missing id the query settles into an error with no data, so `!recipe`
  // would otherwise keep the page on the loading spinner forever (TC-REC-DET2).
  if (error instanceof ApiError && (error.status === 404 || error.status === 403)) {
    notFound();
  }

  // Other load errors (5xx / network) → inline error, also before the loading
  // guard so a failed fetch (no `recipe`) doesn't spin forever.
  if (error) {
    return (
      <>
        <PageHeader
          title={t("hq.recipes.edit_page.title")}
          description={t("hq.recipes.edit_page.description")}
        >
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8"
            onClick={() => router.push(`/hq/${brandSlug}/recipes`)}
          >
            {t("common.cancel")}
          </Button>
        </PageHeader>
        <PageContent>
          <div className="rounded-md border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
            {t("hq.recipes.load_failed")}
          </div>
        </PageContent>
      </>
    );
  }

  if (isLoading || !recipe) {
    return (
      <>
        <PageHeader
          title={t("hq.recipes.edit_page.title")}
          description={t("hq.recipes.edit_page.description")}
        >
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8"
            onClick={() => router.push(`/hq/${brandSlug}/recipes`)}
          >
            {t("common.cancel")}
          </Button>
        </PageHeader>
        <PageContent>
          <div className="flex items-center justify-center p-8">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        </PageContent>
      </>
    );
  }

  const hasOutput = form.output_kind === "material" ? !!form.material_id : form.sku_ids.length > 0;

  const canSubmit =
    hydrated &&
    !isTrashed &&
    effectiveName.length > 0 &&
    hasOutput &&
    hasChanges &&
    !updateMutation.isPending;

  return (
    <>
      <PageHeader
        title={t("hq.recipes.edit_page.title")}
        description={t("hq.recipes.edit_page.description")}
      >
        {/* 1. Cancel — always first, guards unsaved changes */}
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8"
          onClick={handleRequestExit}
          disabled={disabledAll}
        >
          {t("common.cancel")}
        </Button>

        {/* 2. Submit / Resubmit (draft or rejected) */}
        {showSubmitWorkflow && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 gap-1.5"
            onClick={handleSubmitForApproval}
            disabled={disabledAll}
          >
            {submitForApproval.isPending ? (
              <Spinner className="size-3.5" />
            ) : (
              <Send className="size-3.5" />
            )}
            {approvalStatus === ApprovalStatus.Rejected
              ? t("hq.recipes.actions.resubmit")
              : t("hq.recipes.actions.submit")}
          </Button>
        )}

        {/* 3. Reject + Approve (pending) */}
        {showApproveReject && (
          <>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 gap-1.5 border-red-300 text-red-700 hover:bg-red-50"
              onClick={() => setRejectDialogOpen(true)}
              disabled={disabledAll}
            >
              <XCircle className="size-3.5" />
              {t("hq.recipes.actions.reject")}
            </Button>
            <Button
              type="button"
              size="sm"
              className="h-8 gap-1.5"
              onClick={handleApprove}
              disabled={disabledAll}
            >
              {approveMut.isPending ? (
                <Spinner className="size-3.5" />
              ) : (
                <CheckCircle2 className="size-3.5" />
              )}
              {t("hq.recipes.actions.approve")}
            </Button>
          </>
        )}

        {/* 4. Activate (is_active=false) */}
        {showActivate && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 gap-1.5 border-green-400 text-green-700 hover:bg-green-50"
            onClick={handleActivate}
            disabled={disabledAll}
          >
            {updateMutation.isPending ? (
              <Spinner className="size-3.5" />
            ) : (
              <PlayCircle className="size-3.5" />
            )}
            {t("common.activate")}
          </Button>
        )}

        {/* 5. Deactivate (is_active=true) */}
        {showDeactivate && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 gap-1.5"
            onClick={handleDeactivate}
            disabled={disabledAll}
          >
            {updateMutation.isPending ? (
              <Spinner className="size-3.5" />
            ) : (
              <PauseCircle className="size-3.5" />
            )}
            {t("common.deactivate")}
          </Button>
        )}

        {/* 6. Delete — hidden for trashed resources */}
        {!isTrashed && (
          <Button
            type="button"
            variant="destructive"
            size="sm"
            className="h-8 gap-1.5"
            onClick={() => setDeleteDialogOpen(true)}
            disabled={disabledAll}
          >
            <Trash2 className="size-3.5" />
            {t("common.delete")}
          </Button>
        )}

        {/* 7. Save — always last, primary action */}
        <Button
          type="button"
          size="sm"
          className="h-8 gap-1.5"
          onClick={handleSubmit}
          disabled={!canSubmit || disabledAll}
        >
          {updateMutation.isPending ? (
            <Spinner className="size-3.5" />
          ) : (
            <Save className="size-3.5" />
          )}
          {t("hq.recipes.save")}
        </Button>
      </PageHeader>

      <PageContent>
        {showRejectionBanner && (
          <Alert
            color={approvalStatus === ApprovalStatus.Rejected ? "destructive" : "warning"}
            className="mb-4"
          >
            <AlertTitle>
              {t("hq.recipes.alerts.status_label")}: {t(`hq.recipes.status.${approvalStatus}`)}
            </AlertTitle>
            <AlertDescription className="min-w-0 [overflow-wrap:anywhere]">
              {t("hq.recipes.alerts.rejection_reason", {
                reason: recipe?.rejection_reason ?? "",
              })}
            </AlertDescription>
          </Alert>
        )}
        {/* plan-040 M19: warn that a structural edit re-pends an Approved recipe. */}
        {showReapprovalWarning && (
          <Alert color="warning" className="mb-4">
            <AlertTitle>{t("hq.recipes.alerts.reapproval_title")}</AlertTitle>
            <AlertDescription>{t("hq.recipes.alerts.reapproval_warning")}</AlertDescription>
          </Alert>
        )}
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

                {/* Output picker — Material or Product SKU (same UX as
                    recipes/new). Switching kind clears the orthogonal
                    selection so the submit payload is unambiguous. */}
                <div className="flex flex-col gap-2 border-t border-dashed pt-4">
                  <Label className="text-xs font-medium text-muted-foreground">
                    {t("hq.recipes.field.output_kind")}
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
                          // Recipes can only output produced materials. Keep the
                          // currently-selected material visible even if it's raw
                          // so legacy data hydrates without a blank trigger.
                          .filter((m) => !!m.yield_unit || m.id === form.material_id)
                          .sort((a, b) => (a.name ?? "").localeCompare(b.name ?? ""))
                          .map((m) => (
                            <SelectItem key={m.id} value={m.id}>
                              {m.yield_unit ? <Factory className="mr-1 inline h-3.5 w-3.5 align-text-bottom" aria-hidden /> : <Package className="mr-1 inline h-3.5 w-3.5 align-text-bottom" aria-hidden />}
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
                              onClick={() =>
                                setForm((prev) => ({
                                  ...prev,
                                  sku_ids: prev.sku_ids.filter((sid) => sid !== v.id),
                                }))
                              }
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
                    {/* Plan-024 BUG-C — same per-order deduction preview as
                        the new-page form. Surfaces the actual scaling so the
                        author catches "1000g output ↔ 1 serving" mismatches
                        before approving. */}
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
                      readOnly={isOutputUnitLocked}
                      disabled={isOutputUnitLocked}
                      className="h-9"
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
              </div>
              <div className="flex flex-col gap-3">
                {form.ingredients.length === 0 && (
                  <div className="rounded-md border border-dashed border-input px-3 py-4 text-center text-xs text-muted-foreground">
                    {t("hq.recipes.form.no_ingredients")}
                  </div>
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

                      {row.type === "variant" && (
                        <div className="flex min-w-50 flex-1 flex-col gap-1.5">
                          <Label className="text-xs font-medium text-muted-foreground">
                            {t("hq.materials.form.product")}
                          </Label>
                          <ProductSearchSelect
                            brandSlug={brandSlug}
                            value={row.product_id}
                            label={row.product_name}
                            onChange={(pid, name) =>
                              updateIngredient(i, {
                                product_id: pid,
                                product_name: name,
                                variant_id: "",
                                unit_id: "",
                              })
                            }
                          />
                        </div>
                      )}

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
                              // Reset to the new material's base unit (units are
                              // base-first); a unit from the previous material
                              // would be invalid. Falls back to yield_unit when
                              // the material has no registered units.
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
                                // Plan-022 T18 B3 — exclude the recipe's
                                // output material to prevent self-reference.
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

      {/* Delete confirmation — uses the shared component pattern. */}
      <DeleteConfirmDialog
        open={deleteDialogOpen}
        onOpenChange={setDeleteDialogOpen}
        description={t("hq.recipes.delete_confirm", { name: recipe?.name ?? "" })}
        onConfirm={handleDelete}
        isPending={deleteMutation.isPending}
      />

      {/* Reject dialog — collects the rejection reason before mutating. */}
      <RejectRecipeDialog
        brandSlug={brandSlug}
        recipeId={rejectDialogOpen ? (recipe?.id ?? null) : null}
        open={rejectDialogOpen}
        onOpenChange={setRejectDialogOpen}
      />

      {/* Unsaved-changes exit guard — fired by Cancel when hasChanges. Same
          three-button shape as products/[id] so the user can save, exit
          discarding, or cancel back to editing. */}
      <AlertDialog open={confirmExitOpen} onOpenChange={setConfirmExitOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.products.unsaved.title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("hq.products.unsaved.desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={updateMutation.isPending}>
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
              disabled={updateMutation.isPending}
            >
              {t("hq.products.unsaved.exit_without_saving")}
            </Button>
            <AlertDialogAction
              onClick={(e) => {
                e.preventDefault();
                void handleSubmit();
              }}
              disabled={!canSubmit || disabledAll}
            >
              {updateMutation.isPending ? (
                <Spinner className="mr-1.5 size-3.5" />
              ) : (
                <Save className="mr-1.5 size-3.5" />
              )}
              {t("hq.recipes.save")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
