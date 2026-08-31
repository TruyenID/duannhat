"use client";

import { useMemo, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { Factory, HelpCircle, Package, Save } from "lucide-react";
import Link from "next/link";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  Badge,
  Button,
  Card,
  Input,
  Label,
  MultiCombobox,
  Popover,
  PopoverContent,
  PopoverTrigger,
  Spinner,
  Switch,
  TranslatableRichText,
  type TranslatableValue,
} from "@godxjp/ui";
import { useAllergens } from "@/hooks/api/use-allergens";
import { ApiError, apiFetch } from "@/lib/api";
import { DEFAULT_LOCALE } from "@/i18n";
import { buildI18nPayload, emptyLocaleMap } from "@/types/models/payload-helpers";
import { fillLocalesFallback } from "@/lib/i18n-fill";
import { useTranslation } from "@/providers/app-provider";
import { useCreateMaterial } from "@/hooks/api/use-materials";
import type { CreateMaterialInput } from "@/services/material-service";

interface BrandResponse {
  data: { id: string; slug: string; name: string };
}

/**
 * Plan-022 T19 — explicit kind declaration at the top of the form.
 *   raw      = nguyên liệu thô (purchased from supplier, no BOM)
 *   produced = bán thành phẩm (output of a production batch, has a recipe)
 *
 * The kind drives conditional field rendering: raw materials hide
 * yield/shelf-life/temperature controls, produced materials require them.
 * BE backs this up via MaterialService::isProducedMaterial — yield_unit
 * presence is the source-of-truth flag once persisted.
 */
type MaterialKind = "raw" | "produced";

interface FormState {
  kind: MaterialKind;
  name: TranslatableValue;
  description: TranslatableValue;
  yield_quantity: string;
  yield_unit: string;
  shelf_life_days: string;
  calculated_cost: string;
  is_active: boolean;
  allergen_ids: string[];
  requires_temperature_check: boolean;
  temperature_min: string;
  temperature_max: string;
}

function emptyFormState(): FormState {
  return {
    kind: "raw",
    name: emptyLocaleMap(),
    description: emptyLocaleMap(),
    yield_quantity: "1",
    yield_unit: "",
    shelf_life_days: "",
    calculated_cost: "0",
    is_active: true,
    allergen_ids: [],
    requires_temperature_check: false,
    temperature_min: "",
    temperature_max: "",
  };
}

export default function NewMaterialPage() {
  const params = useParams<{ brandSlug: string }>();
  const router = useRouter();
  const brandSlug = params.brandSlug;
  const { t } = useTranslation();

  const [form, setForm] = useState<FormState>(emptyFormState);
  const [initialForm] = useState<FormState>(emptyFormState);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);

  // Dirty check — drives the unsaved-changes guard on Cancel.
  const isDirty = useMemo(
    () => JSON.stringify(form) !== JSON.stringify(initialForm),
    [form, initialForm]
  );

  const { data: brandResponse } = useQuery({
    queryKey: ["hq", "brand", brandSlug],
    queryFn: () => apiFetch<BrandResponse>(`/api/v1/hq/${brandSlug}`),
    staleTime: 5 * 60 * 1000,
  });
  const brandId = brandResponse?.data.id ?? "";

  const allergensQuery = useAllergens(brandSlug, { per_page: 100 });
  const allAllergens = useMemo(() => allergensQuery.data?.data ?? [], [allergensQuery.data]);
  const allergenOptions = useMemo(
    () => allAllergens.map((a) => ({ value: a.id, label: a.name })),
    [allAllergens]
  );
  const allergenById = useMemo(() => {
    const m = new Map<string, string>();
    for (const a of allAllergens) m.set(a.id, a.name);
    return m;
  }, [allAllergens]);

  const createMutation = useCreateMaterial(brandSlug);

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

  const effectiveName =
    form.name[DEFAULT_LOCALE]?.trim() || form.name["en"]?.trim() || form.name["vi"]?.trim() || "";
  const effectiveDescription =
    form.description[DEFAULT_LOCALE]?.trim() ||
    Object.values(form.description).find((v) => v?.trim()) ||
    "";

  const isProduced = form.kind === "produced";

  // Required-field gate for the Save button. Name is always required; produced
  // materials also require a production unit (the BE infers kind=produced from a
  // non-null yield_unit). Missing either keeps Save disabled so the user can't
  // trigger an avoidable 422. Kind always carries a value (defaults to "raw").
  const canSave = effectiveName !== "" && (!isProduced || form.yield_unit.trim() !== "");

  async function handleSubmit() {
    setFieldErrors({});

    // Defensive re-check (Save is already disabled when these are missing): keep
    // the inline messages as a safety net for any path that reaches submit.
    const errors: Record<string, string[]> = {};
    if (!effectiveName) {
      errors.name = [t("hq.materials.form.name_required")];
    }
    if (isProduced && !form.yield_unit.trim()) {
      errors.yield_unit = [t("hq.materials.form.yield_unit_required")];
    }
    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }

    const i18n = buildI18nPayload({
      name: fillLocalesFallback(form.name),
      description: effectiveDescription ? fillLocalesFallback(form.description) : form.description,
    });
    for (const locale of Object.keys(i18n) as Array<keyof typeof i18n>) {
      if (!i18n[locale]?.name?.trim()) delete i18n[locale];
    }

    const tempMinTrimmed = form.temperature_min.trim();
    const tempMaxTrimmed = form.temperature_max.trim();
    const shelfLifeTrimmed = form.shelf_life_days.trim();
    const payload = {
      name: effectiveName,
      description: effectiveDescription || null,
      yield_quantity: isProduced ? Number(form.yield_quantity) || 0 : 1,
      yield_unit: isProduced ? form.yield_unit.trim() || null : null,
      shelf_life_days: isProduced && shelfLifeTrimmed !== "" ? Number(shelfLifeTrimmed) : null,
      calculated_cost: Number(form.calculated_cost) || 0,
      is_active: form.is_active,
      allergen_ids: form.allergen_ids,
      requires_temperature_check: form.requires_temperature_check,
      temperature_min: tempMinTrimmed === "" ? null : Number(tempMinTrimmed),
      temperature_max: tempMaxTrimmed === "" ? null : Number(tempMaxTrimmed),
      brand_id: brandId,
      ...i18n,
    };

    try {
      const result = await createMutation.mutateAsync(payload as CreateMaterialInput);
      const newId = result?.data?.id;
      if (newId) {
        // Plan-022 T19 — produced materials need a recipe to be batchable, so
        // route the user straight into the recipe form with the new material
        // pre-selected. Raw materials land on the material detail page as before.
        if (isProduced) {
          router.replace(`/hq/${brandSlug}/recipes/new?output_material_id=${newId}`);
        } else {
          router.replace(`/hq/${brandSlug}/materials/${newId}`);
        }
      }
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const body = err.body as { errors?: Record<string, string[]> };
        if (body.errors) setFieldErrors(body.errors);
      }
    }
  }

  // Cancel guards unsaved changes: confirm before discarding a dirty form,
  // otherwise navigate straight back to the list.
  function handleCancel() {
    if (isDirty) {
      setConfirmExitOpen(true);
    } else {
      router.push(`/hq/${brandSlug}/materials`);
    }
  }

  return (
    <>
      <PageHeader title={t("hq.materials.new_title")} description={t("hq.materials.subtitle")}>
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
          disabled={createMutation.isPending || !canSave}
        >
          {createMutation.isPending ? (
            <Spinner className="size-3.5" />
          ) : (
            <Save className="size-3.5" />
          )}
          {t("hq.materials.save")}
        </Button>
      </PageHeader>

      <PageContent>
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          {/* LEFT: main content */}
          <div className="flex flex-col gap-4 lg:col-span-8">
            {/* Plan-022 T19 — Kind toggle. Drives conditional rendering of
                yield / shelf-life / temperature sections below. */}
            <Card className="p-4">
              <div className="mb-3 text-sm font-semibold">
                {t("hq.materials.form.kind_section")}
              </div>
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {(["raw", "produced"] as MaterialKind[]).map((kind) => {
                  const selected = form.kind === kind;
                  return (
                    <div
                      key={kind}
                      className={`relative flex items-center rounded-md border transition ${
                        selected
                          ? "border-primary bg-primary/5 ring-2 ring-primary/20"
                          : "border-input hover:border-primary/50 hover:bg-muted/40"
                      }`}
                    >
                      <button
                        type="button"
                        onClick={() => update("kind", kind)}
                        className="flex flex-1 items-center gap-2 p-3 text-left"
                        aria-pressed={selected}
                      >
                        <span className="text-xs font-semibold">
                          {kind === "raw" ? <Package className="h-3.5 w-3.5" aria-hidden /> : <Factory className="h-3.5 w-3.5" aria-hidden />}{" "}
                          {t(`hq.materials.kind.${kind}`)}
                        </span>
                      </button>
                      <Popover>
                        <PopoverTrigger asChild>
                          <button
                            type="button"
                            className="mr-2 inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                            aria-label={t("common.help")}
                            onClick={(e) => e.stopPropagation()}
                          >
                            <HelpCircle className="size-3.5" />
                          </button>
                        </PopoverTrigger>
                        <PopoverContent
                          align="end"
                          className="max-w-sm text-xs leading-relaxed text-muted-foreground"
                        >
                          {t(`hq.materials.form.kind_help.${kind}`)}
                        </PopoverContent>
                      </Popover>
                    </div>
                  );
                })}
              </div>
              {form.kind === "raw" ? (
                <p className="mt-3 text-[11px] leading-relaxed text-muted-foreground">
                  {t("hq.materials.form.kind_hint.raw")}
                </p>
              ) : (
                <p className="mt-3 text-[11px] leading-relaxed text-muted-foreground">
                  {t("hq.materials.form.kind_hint.produced")}
                </p>
              )}
            </Card>

            {/* Basic info */}
            <Card className="p-4">
              <div className="mb-4 text-sm font-semibold">{t("hq.materials.form.basic_info")}</div>
              <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="name" className="text-xs font-medium text-muted-foreground">
                    {t("hq.materials.form.name")}
                  </Label>
                  <Input
                    id="name"
                    translatable
                    value={form.name}
                    onChange={(value) => update("name", value)}
                    placeholder={t("hq.materials.form.name_placeholder")}
                    className="h-9"
                    maxLength={255}
                    autoFocus
                  />
                  {fieldErrors.name?.[0] && (
                    <p className="text-[11px] text-red-500">{fieldErrors.name[0]}</p>
                  )}
                </div>

                <div className="flex flex-col gap-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">
                    {t("common.description")}
                  </Label>
                  <TranslatableRichText
                    value={form.description}
                    onChange={(value) => update("description", value)}
                  />
                  {fieldErrors.description?.[0] && (
                    <p className="text-[11px] text-red-500">{fieldErrors.description[0]}</p>
                  )}
                </div>
              </div>
            </Card>

            {/* BOM via Recipes (plan-022 T4.4 — the components builder retired) */}
            <Card className="p-4">
              <div className="mb-2 text-sm font-semibold">
                {t("hq.materials.form.recipes_section")}
              </div>
              <p className="text-xs text-muted-foreground">{t("hq.materials.form.recipes_help")}</p>
            </Card>

            {/* Allergens */}
            <Card className="p-4">
              <div className="mb-4 text-sm font-semibold">{t("hq.materials.form.allergens")}</div>
              {allergenOptions.length === 0 ? (
                <div className="rounded-md border border-dashed px-3 py-4 text-center text-xs text-muted-foreground">
                  {t("hq.materials.form.allergens_empty")}
                </div>
              ) : (
                <div className="flex flex-col gap-2">
                  <MultiCombobox
                    options={allergenOptions}
                    value={form.allergen_ids}
                    onChange={(v) => update("allergen_ids", v)}
                    placeholder={t("hq.materials.form.allergens_placeholder")}
                    searchPlaceholder={t("hq.materials.form.allergens_search_placeholder")}
                    className="h-9 w-full"
                  />
                  {form.allergen_ids.length > 0 && (
                    <div className="flex flex-wrap gap-1.5 pt-1">
                      {form.allergen_ids.map((id) => {
                        const label = allergenById.get(id) ?? id;
                        return (
                          <Badge key={id} variant="soft" color="warning" className="gap-1">
                            {label}
                            <button
                              type="button"
                              onClick={() =>
                                update(
                                  "allergen_ids",
                                  form.allergen_ids.filter((x) => x !== id)
                                )
                              }
                              className="ml-0.5 inline-flex size-3.5 items-center justify-center rounded hover:bg-warning/20"
                              aria-label={t("common.delete")}
                            >
                              ×
                            </button>
                          </Badge>
                        );
                      })}
                    </div>
                  )}
                </div>
              )}
            </Card>

            {/* Yield — only meaningful for produced materials (BTP). The
                kind toggle at the top of the page hides this card for raw
                materials so the form doesn't surface fields the BE rejects. */}
            {form.kind === "produced" && (
              <Card className="p-4">
                <div className="mb-4 flex items-center gap-1 text-sm font-semibold">
                  <span>{t("hq.materials.form.yield_section")}</span>
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
                    <PopoverContent align="start" className="max-w-sm text-xs leading-relaxed">
                      <p className="text-muted-foreground">
                        {t("hq.materials.form.yield_help.tip")}
                      </p>
                    </PopoverContent>
                  </Popover>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div className="flex flex-col gap-1.5">
                    <div className="flex items-center gap-1">
                      <Label className="text-xs font-medium text-muted-foreground">
                        {t("hq.materials.form.yield_quantity")}
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
                          {t("hq.materials.form.yield_quantity_help")}
                        </PopoverContent>
                      </Popover>
                    </div>
                    <Input
                      type="number"
                      min="0"
                      step="0.0001"
                      value={form.yield_quantity}
                      onChange={(e) => update("yield_quantity", e.target.value)}
                      className="h-9"
                      required
                    />
                    {fieldErrors.yield_quantity?.[0] && (
                      <p className="text-[11px] text-red-500">{fieldErrors.yield_quantity[0]}</p>
                    )}
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <div className="flex items-center gap-1">
                      <Label className="text-xs font-medium text-muted-foreground">
                        {t("hq.materials.form.yield_unit")}
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
                          {t("hq.materials.form.yield_unit_help")}
                        </PopoverContent>
                      </Popover>
                    </div>
                    <Input
                      value={form.yield_unit}
                      onChange={(e) => update("yield_unit", e.target.value)}
                      maxLength={50}
                      placeholder={t("hq.materials.form.yield_unit_placeholder")}
                      className="h-9"
                      required
                      aria-invalid={fieldErrors.yield_unit?.[0] ? true : undefined}
                    />
                    {fieldErrors.yield_unit?.[0] && (
                      <p className="text-[11px] text-red-500">{fieldErrors.yield_unit[0]}</p>
                    )}
                  </div>
                  {/* Plan-022 T5.3 — shelf-life policy for production output lots. */}
                  <div className="col-span-2 flex flex-col gap-1.5">
                    <Label className="text-xs font-medium text-muted-foreground">
                      {t("hq.materials.form.shelf_life_days")}
                    </Label>
                    <Input
                      type="number"
                      min="0"
                      step="1"
                      value={form.shelf_life_days}
                      onChange={(e) => update("shelf_life_days", e.target.value)}
                      placeholder={t("hq.materials.form.shelf_life_days_placeholder")}
                      className="h-9"
                    />
                    <p className="text-[11px] text-muted-foreground">
                      {t("hq.materials.form.shelf_life_days_help")}
                    </p>
                    {fieldErrors.shelf_life_days?.[0] && (
                      <p className="text-[11px] text-red-500">{fieldErrors.shelf_life_days[0]}</p>
                    )}
                  </div>
                </div>
              </Card>
            )}
          </div>

          {/* RIGHT: sidebar */}
          <div className="flex flex-col gap-4 lg:col-span-4">
            {/* Status */}
            <Card className="p-4">
              <div className="mb-3 text-sm font-semibold">
                {t("hq.materials.form.status_section")}
              </div>
              <Label htmlFor="is-active" className="flex cursor-pointer items-center gap-2 text-xs">
                <Switch
                  id="is-active"
                  checked={form.is_active}
                  onCheckedChange={(v) => update("is_active", v)}
                />
                {t("common.active")}
              </Label>
            </Card>

            {/* Cost */}
            <Card className="p-4">
              <div className="mb-3 text-sm font-semibold">
                {t("hq.materials.form.cost_section")}
              </div>
              <div className="flex flex-col gap-1.5">
                <div className="flex items-center gap-1">
                  <Label className="text-xs font-medium text-muted-foreground">
                    {t("hq.materials.form.calculated_cost")}
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
                      {t("hq.materials.form.calculated_cost_help")}
                    </PopoverContent>
                  </Popover>
                </div>
                <Input
                  type="number"
                  min="0"
                  step="0.01"
                  value={form.calculated_cost}
                  onChange={(e) => update("calculated_cost", e.target.value)}
                  className="h-9"
                />
                {fieldErrors.calculated_cost?.[0] && (
                  <p className="text-[11px] text-red-500">{fieldErrors.calculated_cost[0]}</p>
                )}
              </div>
            </Card>

            {/* Temperature CCP — mirrors the card on /materials/[id] for parity. */}
            <Card className="p-4">
              <div className="mb-3 flex items-center gap-1 text-sm font-semibold">
                <span>{t("material_settings.temp_card_title")}</span>
                <Popover>
                  <PopoverTrigger asChild>
                    <button
                      type="button"
                      className="inline-flex size-5 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                      aria-label={t("common.help")}
                    >
                      <HelpCircle className="size-3.5" />
                    </button>
                  </PopoverTrigger>
                  <PopoverContent align="start" className="max-w-xs text-xs leading-relaxed">
                    <ul className="list-disc space-y-1.5 pl-4">
                      <li>{t("material_settings.temp_help.item1")}</li>
                      <li>{t("material_settings.temp_help.item2")}</li>
                      <li>{t("material_settings.temp_help.item3")}</li>
                    </ul>
                  </PopoverContent>
                </Popover>
              </div>
              <div className="flex flex-col gap-3">
                <Label
                  htmlFor="requires-temperature-check"
                  className="flex cursor-pointer items-center gap-2 text-xs"
                >
                  <Switch
                    id="requires-temperature-check"
                    checked={form.requires_temperature_check}
                    onCheckedChange={(v) => update("requires_temperature_check", v)}
                  />
                  {t("material_settings.requires_temp_label")}
                </Label>
                {form.requires_temperature_check ? (
                  <div className="grid grid-cols-2 gap-3">
                    <div className="flex flex-col gap-1.5">
                      <Label className="text-xs font-medium text-muted-foreground">
                        {t("material_settings.temp_min_label")}
                      </Label>
                      <Input
                        type="number"
                        step="0.1"
                        value={form.temperature_min}
                        onChange={(e) => update("temperature_min", e.target.value)}
                        placeholder={t("material_settings.temp_min_placeholder")}
                        className="h-9"
                      />
                      {fieldErrors.temperature_min?.[0] && (
                        <p className="text-[11px] text-red-500">{fieldErrors.temperature_min[0]}</p>
                      )}
                    </div>
                    <div className="flex flex-col gap-1.5">
                      <Label className="text-xs font-medium text-muted-foreground">
                        {t("material_settings.temp_max_label")}
                      </Label>
                      <Input
                        type="number"
                        step="0.1"
                        value={form.temperature_max}
                        onChange={(e) => update("temperature_max", e.target.value)}
                        placeholder={t("material_settings.temp_max_placeholder")}
                        className="h-9"
                      />
                      {fieldErrors.temperature_max?.[0] && (
                        <p className="text-[11px] text-red-500">{fieldErrors.temperature_max[0]}</p>
                      )}
                    </div>
                  </div>
                ) : null}
              </div>
            </Card>

            <div className="text-[11px] text-muted-foreground">
              <Link
                href={`/hq/${brandSlug}/recipes`}
                className="text-primary underline underline-offset-2"
              >
                {t("hq.materials.form.go_to_recipes")}
              </Link>
            </div>
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
              onClick={() => {
                setConfirmExitOpen(false);
                router.push(`/hq/${brandSlug}/materials`);
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
