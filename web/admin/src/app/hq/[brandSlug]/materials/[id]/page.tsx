"use client";

import { useEffect, useMemo, useState } from "react";
import { notFound, useParams, useRouter } from "next/navigation";
import { HelpCircle, Save } from "lucide-react";
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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Switch,
  TranslatableRichText,
  type TranslatableValue,
} from "@godxjp/ui";
import { useAllergens } from "@/hooks/api/use-allergens";
import { ApiError } from "@/lib/api";
import { DEFAULT_LOCALE } from "@/i18n";
import { buildI18nPayload, emptyLocaleMap } from "@/types/models/payload-helpers";
import { fillLocalesFallback } from "@/lib/i18n-fill";
import { useTranslation } from "@/providers/app-provider";
import { useMaterial, useUpdateMaterial } from "@/hooks/api/use-materials";
import { useMaterialUnits } from "@/hooks/api/use-material-units";
import { SubstitutionRulesCard } from "./components/substitution-rules-card";
import type { MaterialTranslations, UpdateMaterialInput } from "@/services/material-service";

interface FormState {
  name: TranslatableValue;
  description: TranslatableValue;
  yield_quantity: string;
  yield_unit: string;
  shelf_life_days: string;
  calculated_cost: string;
  is_active: boolean;
  allergen_ids: string[];
  /** Plan-017 Tier 1.D — temperature CCP. */
  requires_temperature_check: boolean;
  temperature_min: string;
  temperature_max: string;
  /** Plan-017 Tier 1.C — comma-separated days, e.g. "7, 3, 1". */
  expiry_alert_thresholds: string;
}

function emptyFormState(): FormState {
  return {
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
    expiry_alert_thresholds: "7, 3, 1",
  };
}

/**
 * Parse a comma/whitespace-separated string of days into a sorted positive
 * integer array. Returns null when the string parses to zero numbers, so
 * the caller can persist NULL (= "use system default [7, 3, 1]").
 */
function parseExpiryThresholds(raw: string): number[] | null {
  const parts = raw
    .split(/[,\s]+/)
    .map((s) => s.trim())
    .filter((s) => s !== "")
    .map((s) => Number(s))
    .filter((n) => Number.isFinite(n) && n > 0)
    .map((n) => Math.floor(n));
  return parts.length > 0 ? parts : null;
}

/**
 * Hydrate a TranslatableValue dict from the material's `translations` response.
 */
function hydrateLocaleMap(
  translations: MaterialTranslations | undefined,
  fallback: string | null,
  field: "name" | "description"
): TranslatableValue {
  const map = emptyLocaleMap();
  const locales: Array<keyof MaterialTranslations> = ["ja", "en", "vi"];
  let touchedAny = false;
  for (const locale of locales) {
    const value = translations?.[locale]?.[field];
    if (value !== undefined && value !== null) {
      map[locale] = value;
      touchedAny = true;
    }
  }
  if (!touchedAny && fallback) {
    map[DEFAULT_LOCALE] = fallback;
  }
  return map;
}

export default function EditMaterialPage() {
  const params = useParams<{ brandSlug: string; id: string }>();
  const router = useRouter();
  const { brandSlug, id } = params;
  const { t } = useTranslation();

  const [form, setForm] = useState<FormState>(emptyFormState);
  const [initialForm, setInitialForm] = useState<FormState | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [hydrated, setHydrated] = useState(false);
  const [confirmExit, setConfirmExit] = useState(false);

  const { data: materialResponse, isLoading, error } = useMaterial(brandSlug, id);
  // Registered units back the yield-unit picker. yield_unit must be one of
  // them (BE-enforced on update) — base unit is auto-seeded from yield_unit at
  // create, so a produced material always has at least one option here.
  const { data: unitsResponse } = useMaterialUnits(brandSlug, id);
  const unitOptions = unitsResponse?.data ?? [];
  const material = materialResponse?.data;

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

  // Hydrate form once material data arrives.
  useEffect(() => {
    if (!material || hydrated) return;

    const nameMap = hydrateLocaleMap(material.translations, material.name, "name");
    const descMap = hydrateLocaleMap(material.translations, material.description, "description");

    const next: FormState = {
      name: nameMap,
      description: descMap,
      yield_quantity: String(material.yield_quantity ?? 1),
      yield_unit: material.yield_unit ?? "",
      shelf_life_days:
        material.shelf_life_days !== null && material.shelf_life_days !== undefined
          ? String(material.shelf_life_days)
          : "",
      calculated_cost: String(material.calculated_cost ?? 0),
      is_active: material.is_active,
      allergen_ids: (material.allergens ?? []).map((a) => a.id),
      requires_temperature_check: !!material.requires_temperature_check,
      temperature_min:
        material.temperature_min !== null && material.temperature_min !== undefined
          ? String(material.temperature_min)
          : "",
      temperature_max:
        material.temperature_max !== null && material.temperature_max !== undefined
          ? String(material.temperature_max)
          : "",
      expiry_alert_thresholds:
        Array.isArray(material.expiry_alert_thresholds) &&
        material.expiry_alert_thresholds.length > 0
          ? material.expiry_alert_thresholds.join(", ")
          : "7, 3, 1",
    };
    // Snapshot the hydrated form as the baseline for the unsaved-changes guard.
    setForm(next);
    setInitialForm(next);
    setHydrated(true);
  }, [material, hydrated]);

  const updateMutation = useUpdateMaterial(brandSlug);

  // Dirty check against the hydrated baseline — drives the unsaved-changes
  // guard on Cancel. False until hydrated so the initial load never counts.
  const hasChanges = useMemo(
    () => (hydrated && initialForm ? JSON.stringify(form) !== JSON.stringify(initialForm) : false),
    [form, initialForm, hydrated]
  );

  // Cancel guards unsaved changes: confirm before discarding a dirty form,
  // otherwise navigate straight back to the list.
  function handleCancel() {
    if (hasChanges) {
      setConfirmExit(true);
    } else {
      router.push(`/hq/${brandSlug}/materials`);
    }
  }

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

  async function handleSubmit() {
    setFieldErrors({});

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

    const payload: UpdateMaterialInput = {
      name: effectiveName,
      description: effectiveDescription || null,
      yield_quantity: Number(form.yield_quantity) || 0,
      yield_unit: form.yield_unit.trim() || null,
      shelf_life_days: shelfLifeTrimmed === "" ? null : Number(shelfLifeTrimmed),
      calculated_cost: Number(form.calculated_cost) || 0,
      is_active: form.is_active,
      allergen_ids: form.allergen_ids,
      requires_temperature_check: form.requires_temperature_check,
      temperature_min: tempMinTrimmed === "" ? null : Number(tempMinTrimmed),
      temperature_max: tempMaxTrimmed === "" ? null : Number(tempMaxTrimmed),
      expiry_alert_thresholds: parseExpiryThresholds(form.expiry_alert_thresholds),
      ...i18n,
    };

    try {
      await updateMutation.mutateAsync({ id, data: payload });
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const body = err.body as { errors?: Record<string, string[]> };
        if (body.errors) setFieldErrors(body.errors);
      }
    }
  }

  // 404 / 403 → Next.js not-found page (id doesn't exist or no access), instead
  // of a generic inline "load_failed" — consistent with the other detail pages
  // (TC-MAT-DET2). Other errors fall through to the inline error below.
  if (error instanceof ApiError && (error.status === 404 || error.status === 403)) {
    notFound();
  }

  if (isLoading) {
    return (
      <>
        <PageHeader title={t("hq.materials.form.edit_title")} />
        <PageContent>
          <div className="flex items-center justify-center p-8">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        </PageContent>
      </>
    );
  }

  if (error || !material) {
    return (
      <>
        <PageHeader title={t("hq.materials.form.edit_title")} />
        <PageContent>
          <div className="rounded-md border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
            {t("hq.materials.load_failed")}
          </div>
        </PageContent>
      </>
    );
  }

  const canSubmit = effectiveName.length > 0 && !updateMutation.isPending;

  // Kind is derived from yield_unit (no stored column) — mirror the header rule.
  // When the saved material is still raw, the Yield card is framed as an optional
  // "convert to produced" action rather than a plain section, so the fields that
  // were hidden on the raw create form don't look like they appeared by accident.
  const isProduced = material
    ? material.kind === "produced" || material.yield_unit !== null
    : false;

  return (
    <>
      <PageHeader
        title={
          material
            ? (material.kind === "produced" || material.yield_unit !== null
                ? `[${t("hq.materials.kind.produced")}] `
                : `[${t("hq.materials.kind.raw")}] `) + t("hq.materials.form.edit_title")
            : t("hq.materials.form.edit_title")
        }
        description={t("hq.materials.form.edit_description")}
      >
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8"
          onClick={() => router.push(`/hq/${brandSlug}/materials/${id}/units`)}
        >
          {t("nav.units_link")}
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8"
          onClick={handleCancel}
          disabled={updateMutation.isPending}
        >
          {t("common.cancel")}
        </Button>
        <Button
          type="button"
          size="sm"
          className="h-8 gap-1.5"
          onClick={handleSubmit}
          disabled={!canSubmit}
        >
          {updateMutation.isPending ? (
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

            {/* Plan-022 T4.4 — components builder retired. BOM now lives on Recipe.ingredients. */}
            <Card className="p-4">
              <div className="mb-2 flex items-center justify-between text-sm font-semibold">
                <span>{t("hq.materials.form.recipes_section")}</span>
                <Link
                  href={`/hq/${brandSlug}/recipes?material_id=${id}`}
                  className="text-xs text-primary underline underline-offset-2"
                >
                  {t("hq.materials.form.go_to_recipes")}
                </Link>
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
                      {form.allergen_ids.map((aid) => {
                        const label = allergenById.get(aid) ?? aid;
                        return (
                          <Badge key={aid} variant="soft" color="warning" className="gap-1">
                            {label}
                            <button
                              type="button"
                              onClick={() =>
                                update(
                                  "allergen_ids",
                                  form.allergen_ids.filter((x) => x !== aid)
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

            {/* Yield */}
            <Card className="p-4">
              <div className="mb-4">
                <div className="text-sm font-semibold">
                  {isProduced
                    ? t("hq.materials.form.yield_section")
                    : t("hq.materials.form.yield_section_convert")}
                </div>
                {!isProduced && (
                  <p className="mt-1 text-xs text-muted-foreground">
                    {t("hq.materials.form.yield_section_convert_hint")}
                  </p>
                )}
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
                  {unitOptions.length > 0 ? (
                    <Select
                      value={form.yield_unit || undefined}
                      onValueChange={(v) => update("yield_unit", v)}
                    >
                      <SelectTrigger className="h-9 w-full">
                        <SelectValue placeholder={t("hq.materials.form.yield_unit_placeholder")} />
                      </SelectTrigger>
                      <SelectContent>
                        {unitOptions.map((u) => (
                          <SelectItem key={u.unit} value={u.unit}>
                            {u.is_base ? u.unit : `${u.unit} (×${u.ratio})`}
                          </SelectItem>
                        ))}
                        {/* Surface a legacy yield_unit that predates the
                            registered units so it stays visible + selectable
                            (BE will still reject saving an unregistered one). */}
                        {form.yield_unit &&
                          !unitOptions.some((u) => u.unit === form.yield_unit) && (
                            <SelectItem value={form.yield_unit}>{form.yield_unit}</SelectItem>
                          )}
                      </SelectContent>
                    </Select>
                  ) : (
                    <Input
                      value={form.yield_unit}
                      onChange={(e) => update("yield_unit", e.target.value)}
                      maxLength={50}
                      placeholder={t("hq.materials.form.yield_unit_placeholder")}
                      className="h-9"
                    />
                  )}
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
          </div>

          {/* RIGHT: sidebar */}
          <div className="flex flex-col gap-4 lg:col-span-4">
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

            {/* Temperature CCP — Tier 1.D. */}
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
                  htmlFor="requires_temp"
                  className="flex cursor-pointer items-center gap-2 text-xs"
                >
                  <Switch
                    id="requires_temp"
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
                    </div>
                  </div>
                ) : null}
              </div>
            </Card>

            {/* Expiry alert thresholds — Tier 1.C. */}
            <Card className="p-4">
              <div className="mb-3 flex items-center gap-1 text-sm font-semibold">
                <span>{t("material_settings.expiry_card_title")}</span>
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
                      <li>{t("material_settings.expiry_help.item1")}</li>
                      <li>{t("material_settings.expiry_help.item2")}</li>
                      <li>{t("material_settings.expiry_help.item3")}</li>
                    </ul>
                  </PopoverContent>
                </Popover>
              </div>
              <div className="flex flex-col gap-3">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="thresholds" className="text-xs font-medium text-muted-foreground">
                    {t("material_settings.thresholds_label")}
                  </Label>
                  <Input
                    id="thresholds"
                    value={form.expiry_alert_thresholds}
                    onChange={(e) => update("expiry_alert_thresholds", e.target.value)}
                    placeholder="7, 3, 1"
                    className="h-9"
                  />
                </div>
                <div className="flex flex-wrap gap-1.5">
                  {(parseExpiryThresholds(form.expiry_alert_thresholds) ?? []).length === 0 ? (
                    <span className="text-xs text-muted-foreground">
                      {t("material_settings.thresholds_default")}
                    </span>
                  ) : (
                    (parseExpiryThresholds(form.expiry_alert_thresholds) ?? []).map((d) => (
                      <Badge key={d} variant="outline">
                        {d}d
                      </Badge>
                    ))
                  )}
                </div>
              </div>
            </Card>
          </div>

          {/* FULL-WIDTH: Substitution Rules */}
          <div className="lg:col-span-12">
            <SubstitutionRulesCard brandSlug={brandSlug} materialId={id} />
          </div>
        </div>
      </PageContent>

      {/* Unsaved-changes guard — confirm before discarding edits on Cancel. */}
      <AlertDialog open={confirmExit} onOpenChange={setConfirmExit}>
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
              onClick={() => {
                setConfirmExit(false);
                router.push(`/hq/${brandSlug}/materials`);
              }}
              disabled={updateMutation.isPending}
            >
              {t("hq.products.unsaved.exit_without_saving")}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
