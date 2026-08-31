"use client";

/**
 * PromotionForm — shared shop-scope create / edit form for menu promotions
 * (plan-019 B.S8 / B.S10).
 *
 * Locked-field rule (S10): when `report.items_with_promotion_count > 0` the
 * planner forbids changing the value of money already discounted — so
 * `discount_percent`, `applies_to`, and the scope ID pivots are frozen. The
 * user can still tune name / description / window / weekdays / stacking_mode
 * / is_active / extend valid_until.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { Calendar, Save } from "lucide-react";

import {
  Button,
  Card,
  Input,
  Label,
  MultiCombobox,
  RadioGroup,
  RadioGroupItem,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Switch,
  Textarea,
  ToggleGroup,
  ToggleGroupItem,
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@godxjp/ui";
import type { TranslatableValue } from "@godxjp/ui";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { ApiError, apiFetch } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import { useCategories } from "@/hooks/api/use-categories";
import { useProducts } from "@/hooks/api/use-products";
import { useCreateShopPromotion, useUpdateShopPromotion } from "@/hooks/api/use-menu-promotions";
import type {
  CreateMenuPromotionInput,
  MenuPromotion,
  UpdateMenuPromotionInput,
} from "@/services/menu-promotion-service";
import { MenuPromotionAppliesTo } from "@/types/models/enum/MenuPromotionAppliesTo";
import { MenuPromotionStackingMode } from "@/types/models/enum/MenuPromotionStackingMode";
import { buildI18nPayload, DEFAULT_LOCALE, emptyLocaleMap } from "@/types/models/payload-helpers";

interface PromotionFormProps {
  shopSlug: string;
  promotion?: MenuPromotion | null;
}

type TimeMode = "all_day" | "specific_hours";
type WeekdayMode = "all_days" | "specific_days";

interface FormState {
  name: Record<string, string>;
  description: Record<string, string>;
  discount_percent: number;
  applies_to: MenuPromotionAppliesTo;
  applicable_category_ids: string[];
  applicable_product_ids: string[];
  time_mode: TimeMode;
  daily_time_from: string;
  daily_time_to: string;
  weekday_mode: WeekdayMode;
  weekdays: string[]; // "1"–"7" iso weekdays
  valid_from: string;
  valid_until: string;
  stacking_mode: MenuPromotionStackingMode;
  is_active: boolean;
}

function emptyForm(): FormState {
  return {
    name: emptyLocaleMap(),
    description: emptyLocaleMap(),
    discount_percent: 10,
    applies_to: MenuPromotionAppliesTo.AllItems,
    applicable_category_ids: [],
    applicable_product_ids: [],
    time_mode: "all_day",
    daily_time_from: "",
    daily_time_to: "",
    weekday_mode: "all_days",
    weekdays: [],
    valid_from: "",
    valid_until: "",
    stacking_mode: MenuPromotionStackingMode.StackableWithCoupons,
    is_active: true,
  };
}

function hydrate(p: MenuPromotion): FormState {
  const name = emptyLocaleMap();
  const description = emptyLocaleMap();
  name[DEFAULT_LOCALE] = p.name ?? "";
  description[DEFAULT_LOCALE] = p.description ?? "";

  // Backend serializes Astrotomic translations under
  // `translations.{locale}.{field}` (see MenuPromotionResourceBase →
  // getTranslationsArray). Some legacy resources also mirror locale keys
  // at the top level (`p.ja.name`); read both shapes defensively.
  const ext = p as unknown as {
    translations?: Record<string, { name?: string; description?: string | null }>;
    ja?: { name?: string; description?: string | null };
    en?: { name?: string; description?: string | null };
    vi?: { name?: string; description?: string | null };
  };
  for (const loc of ["ja", "en", "vi"] as const) {
    const t = ext.translations?.[loc] ?? ext[loc];
    if (t?.name) name[loc] = t.name;
    if (t?.description) description[loc] = t.description;
  }

  const hasTime = !!(p.daily_time_from && p.daily_time_to);
  const hasWeekdays = Array.isArray(p.weekdays) && (p.weekdays as number[]).length > 0;

  return {
    name,
    description,
    discount_percent: Number(p.discount_percent),
    applies_to: p.applies_to,
    applicable_category_ids: p.applicable_category_ids ?? [],
    applicable_product_ids: p.applicable_product_ids ?? [],
    time_mode: hasTime ? "specific_hours" : "all_day",
    daily_time_from: p.daily_time_from?.slice(0, 5) ?? "",
    daily_time_to: p.daily_time_to?.slice(0, 5) ?? "",
    weekday_mode: hasWeekdays ? "specific_days" : "all_days",
    weekdays: hasWeekdays ? (p.weekdays as number[]).map(String) : [],
    valid_from: toLocalDatetime(p.valid_from),
    valid_until: toLocalDatetime(p.valid_until),
    stacking_mode: p.stacking_mode,
    is_active: p.is_active,
  };
}

/**
 * Convert ISO datetime ("2026-05-18T02:56:32.000000Z") to the format
 * `<input type="datetime-local">` expects (`YYYY-MM-DDTHH:mm`), in the
 * user's local timezone. Naïve `.slice(0,16)` on the ISO string would
 * display UTC, off by the browser timezone offset.
 */
function toLocalDatetime(iso: string | null | undefined): string {
  if (!iso) return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function PromotionForm({ shopSlug, promotion }: PromotionFormProps) {
  const { t } = useTranslation();
  const router = useRouter();
  const isEdit = !!promotion;
  const usedCount = promotion?.report?.items_with_promotion_count ?? 0;
  const locked = usedCount > 0;

  const [form, setForm] = useState<FormState>(() => (promotion ? hydrate(promotion) : emptyForm()));
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  useEffect(() => {
    if (promotion) setForm(hydrate(promotion));
  }, [promotion]);

  // Resolve brand slug from shop info (cached same key as the shop layout).
  const { data: shopInfo } = useQuery({
    queryKey: ["shop", "info", shopSlug],
    queryFn: () =>
      apiFetch<{ data: { id: string; slug: string; name: string; brand_slug?: string | null } }>(
        `/api/v1/shops/${shopSlug}`
      ),
    staleTime: 5 * 60 * 1000,
  });
  const brandSlug = shopInfo?.data?.brand_slug ?? "";

  // Categories + products live at brand scope.
  const { data: categoriesData } = useCategories(brandSlug, { per_page: 200 });
  const { data: productsData } = useProducts(brandSlug, { per_page: 500 });
  const categoryOptions = (categoriesData?.data ?? []).map((c) => ({
    value: c.id,
    label: c.name,
  }));
  const productOptions = (productsData?.data ?? []).map((p) => ({
    value: p.id,
    label: p.name,
  }));

  const createMutation = useCreateShopPromotion(shopSlug);
  const updateMutation = useUpdateShopPromotion(shopSlug);
  const isPending = createMutation.isPending || updateMutation.isPending;

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

  function toIso(local: string): string {
    return local ? new Date(local).toISOString() : "";
  }

  async function handleSubmit() {
    setFieldErrors({});
    const i18n = buildI18nPayload({ name: form.name, description: form.description });
    for (const loc of Object.keys(i18n) as Array<keyof typeof i18n>) {
      if (!i18n[loc]?.name?.trim()) delete i18n[loc];
    }

    const showCategoryPicker =
      form.applies_to === MenuPromotionAppliesTo.Categories ||
      form.applies_to === MenuPromotionAppliesTo.Mixed;
    const showProductPicker =
      form.applies_to === MenuPromotionAppliesTo.Products ||
      form.applies_to === MenuPromotionAppliesTo.Mixed;

    const useSpecificHours = form.time_mode === "specific_hours";
    const useSpecificDays = form.weekday_mode === "specific_days";

    const base: CreateMenuPromotionInput | UpdateMenuPromotionInput = {
      name: (form.name[DEFAULT_LOCALE] ?? "").trim(),
      description: (form.description[DEFAULT_LOCALE] ?? "").trim() || null,
      discount_percent: form.discount_percent,
      applies_to: form.applies_to,
      applicable_category_ids: showCategoryPicker ? form.applicable_category_ids : [],
      applicable_product_ids: showProductPicker ? form.applicable_product_ids : [],
      daily_time_from: useSpecificHours ? form.daily_time_from || null : null,
      daily_time_to: useSpecificHours ? form.daily_time_to || null : null,
      weekdays: useSpecificDays && form.weekdays.length > 0 ? form.weekdays.map(Number) : null,
      valid_from: toIso(form.valid_from),
      valid_until: toIso(form.valid_until),
      stacking_mode: form.stacking_mode,
      is_active: form.is_active,
      ...i18n,
    };

    try {
      if (isEdit && promotion) {
        await updateMutation.mutateAsync({ id: promotion.id, data: base });
        router.push(`/shop/${shopSlug}/promotions/${promotion.id}`);
      } else {
        const res = await createMutation.mutateAsync(base as CreateMenuPromotionInput);
        router.push(`/shop/${shopSlug}/promotions/${res.data.id}`);
      }
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const body = err.body as { errors?: Record<string, string[]> };
        if (body.errors) setFieldErrors(body.errors);
      }
    }
  }

  const title = isEdit ? t("shop.promotions.edit_title") : t("shop.promotions.new_title");
  const description = isEdit
    ? t("shop.promotions.edit_description")
    : t("shop.promotions.new_description");

  const timeFilled =
    form.time_mode === "all_day" || (!!form.daily_time_from && !!form.daily_time_to);
  const daysFilled = form.weekday_mode === "all_days" || form.weekdays.length > 0;

  const canSubmit =
    !!(form.name[DEFAULT_LOCALE] ?? "").trim() &&
    form.discount_percent > 0 &&
    form.discount_percent <= 100 &&
    !!form.valid_from &&
    !!form.valid_until &&
    timeFilled &&
    daysFilled;

  const weekdays = [
    { value: "1", label: t("common.weekday.mon"), fullLabel: t("common.weekday_full.mon") },
    { value: "2", label: t("common.weekday.tue"), fullLabel: t("common.weekday_full.tue") },
    { value: "3", label: t("common.weekday.wed"), fullLabel: t("common.weekday_full.wed") },
    { value: "4", label: t("common.weekday.thu"), fullLabel: t("common.weekday_full.thu") },
    { value: "5", label: t("common.weekday.fri"), fullLabel: t("common.weekday_full.fri") },
    { value: "6", label: t("common.weekday.sat"), fullLabel: t("common.weekday_full.sat") },
    { value: "7", label: t("common.weekday.sun"), fullLabel: t("common.weekday_full.sun") },
  ];

  const showCategoryPicker =
    form.applies_to === MenuPromotionAppliesTo.Categories ||
    form.applies_to === MenuPromotionAppliesTo.Mixed;
  const showProductPicker =
    form.applies_to === MenuPromotionAppliesTo.Products ||
    form.applies_to === MenuPromotionAppliesTo.Mixed;

  return (
    <>
      <PageHeader title={title} description={description}>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8"
          onClick={() => router.back()}
          disabled={isPending}
        >
          {t("common.cancel")}
        </Button>
        <Button
          type="button"
          size="sm"
          className="h-8 gap-1.5"
          onClick={handleSubmit}
          disabled={isPending || !canSubmit}
        >
          {isPending ? <Spinner className="size-3.5" /> : <Save className="size-3.5" />}
          {t("common.save")}
        </Button>
      </PageHeader>

      <PageContent>
        <div className="mx-auto flex max-w-3xl flex-col gap-4">
          {locked && (
            <Card className="border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
              {t("shop.promotions.locked_banner", { count: usedCount })}
            </Card>
          )}

          {/* 1. Identity */}
          <Card className="p-4">
            <div className="mb-3 text-sm font-semibold">
              {t("shop.promotions.section.identity")}
            </div>
            <div className="flex flex-col gap-4">
              <Field label={t("shop.promotions.field.name")} required error={fieldErrors.name?.[0]}>
                <Input
                  translatable
                  value={form.name as TranslatableValue}
                  onChange={(v) => update("name", v as Record<string, string>)}
                  maxLength={255}
                  className="h-9"
                />
              </Field>
              <Field
                label={t("shop.promotions.field.description")}
                error={fieldErrors.description?.[0]}
              >
                <Textarea
                  translatable
                  value={form.description as TranslatableValue}
                  onChange={(v) => update("description", v as Record<string, string>)}
                  rows={3}
                  maxLength={1000}
                  className="field-sizing-fixed"
                />
              </Field>
            </div>
          </Card>

          {/* 2. Discount */}
          <Card className="p-4">
            <div className="mb-3 text-sm font-semibold">
              {t("shop.promotions.section.discount")}
            </div>
            <Field
              label={t("shop.promotions.field.discount_percent")}
              required
              hint={t("shop.promotions.hint.discount_percent")}
              error={fieldErrors.discount_percent?.[0]}
            >
              <LockedWrapper locked={locked} reason={t("shop.promotions.lock_reason.discount")}>
                <Input
                  type="number"
                  min={0.01}
                  max={100}
                  step={0.5}
                  value={String(form.discount_percent)}
                  onChange={(e) => update("discount_percent", Number(e.target.value))}
                  className="h-9 max-w-[160px]"
                  disabled={locked}
                />
              </LockedWrapper>
            </Field>
          </Card>

          {/* 3. Scope */}
          <Card className="p-4">
            <div className="mb-3 text-sm font-semibold">{t("shop.promotions.section.scope")}</div>
            <LockedWrapper locked={locked} reason={t("shop.promotions.lock_reason.scope")}>
              <RadioGroup
                value={form.applies_to}
                onValueChange={(v) => update("applies_to", v as MenuPromotionAppliesTo)}
                disabled={locked}
                className="flex flex-col gap-2"
              >
                {(
                  [
                    MenuPromotionAppliesTo.AllItems,
                    MenuPromotionAppliesTo.Categories,
                    MenuPromotionAppliesTo.Products,
                    MenuPromotionAppliesTo.Mixed,
                  ] as const
                ).map((v) => (
                  <label key={v} className="flex items-center gap-2 text-sm">
                    <RadioGroupItem value={v} />
                    {t(`shop.promotions.scope.${v}`)}
                  </label>
                ))}
              </RadioGroup>
            </LockedWrapper>
            {showCategoryPicker && (
              <div className="mt-3">
                <Field
                  label={t("shop.promotions.field.applicable_categories")}
                  required
                  error={fieldErrors.applicable_category_ids?.[0]}
                >
                  <MultiCombobox
                    options={categoryOptions}
                    value={form.applicable_category_ids}
                    onChange={(v) => update("applicable_category_ids", v)}
                    placeholder={t("shop.promotions.placeholder.pick_categories")}
                    searchPlaceholder={t("shop.promotions.placeholder.search_categories")}
                    className="h-9 w-full"
                  />
                </Field>
              </div>
            )}
            {showProductPicker && (
              <div className="mt-3">
                <Field
                  label={t("shop.promotions.field.applicable_products")}
                  required
                  error={fieldErrors.applicable_product_ids?.[0]}
                >
                  <MultiCombobox
                    options={productOptions}
                    value={form.applicable_product_ids}
                    onChange={(v) => update("applicable_product_ids", v)}
                    placeholder={t("shop.promotions.placeholder.pick_products")}
                    searchPlaceholder={t("shop.promotions.placeholder.search_products")}
                    className="h-9 w-full"
                  />
                </Field>
              </div>
            )}
          </Card>

          {/* 4. Time window */}
          <Card className="p-4">
            <div className="mb-3 text-sm font-semibold">{t("shop.promotions.section.window")}</div>
            <div className="mb-3 rounded-md border border-amber-200 bg-amber-50 p-2.5 text-[11px] text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
              {t("shop.promotions.window_schedule_note")}
            </div>

            {/* Date range (always shown) */}
            <div className="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2">
              <Field
                label={t("shop.promotions.field.valid_from")}
                required
                error={fieldErrors.valid_from?.[0]}
              >
                <Input
                  type="datetime-local"
                  value={form.valid_from}
                  onChange={(e) => update("valid_from", e.target.value)}
                  className="h-9"
                />
              </Field>
              <Field
                label={t("shop.promotions.field.valid_until")}
                required
                error={fieldErrors.valid_until?.[0]}
              >
                <Input
                  type="datetime-local"
                  value={form.valid_until}
                  onChange={(e) => update("valid_until", e.target.value)}
                  className="h-9"
                />
              </Field>
            </div>

            {/* Time-of-day mode picker */}
            <div className="mb-4">
              <Label className="mb-2 block text-xs font-medium text-muted-foreground">
                {t("shop.promotions.field.time_mode")}
              </Label>
              <RadioGroup
                value={form.time_mode}
                onValueChange={(v) => update("time_mode", v as TimeMode)}
                className="flex flex-col gap-1.5"
              >
                <label className="flex items-center gap-2 text-sm">
                  <RadioGroupItem value="all_day" />
                  {t("shop.promotions.time_mode.all_day")}
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <RadioGroupItem value="specific_hours" />
                  {t("shop.promotions.time_mode.specific_hours")}
                </label>
              </RadioGroup>
              {form.time_mode === "specific_hours" && (
                <div className="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                  <Field
                    label={t("shop.promotions.field.daily_time_from")}
                    required
                    hint={t("shop.promotions.hint.cross_midnight")}
                    error={fieldErrors.daily_time_from?.[0]}
                  >
                    <Input
                      type="time"
                      value={form.daily_time_from}
                      onChange={(e) => update("daily_time_from", e.target.value)}
                      className="h-9"
                    />
                  </Field>
                  <Field
                    label={t("shop.promotions.field.daily_time_to")}
                    required
                    error={fieldErrors.daily_time_to?.[0]}
                  >
                    <Input
                      type="time"
                      value={form.daily_time_to}
                      onChange={(e) => update("daily_time_to", e.target.value)}
                      className="h-9"
                    />
                  </Field>
                </div>
              )}
            </div>

            {/* Weekday mode picker */}
            <div>
              <Label className="mb-2 block text-xs font-medium text-muted-foreground">
                {t("shop.promotions.field.weekday_mode")}
              </Label>
              <RadioGroup
                value={form.weekday_mode}
                onValueChange={(v) => update("weekday_mode", v as WeekdayMode)}
                className="flex flex-col gap-1.5"
              >
                <label className="flex items-center gap-2 text-sm">
                  <RadioGroupItem value="all_days" />
                  {t("shop.promotions.weekday_mode.all_days")}
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <RadioGroupItem value="specific_days" />
                  {t("shop.promotions.weekday_mode.specific_days")}
                </label>
              </RadioGroup>
              {form.weekday_mode === "specific_days" && (
                <div className="mt-3">
                  <ToggleGroup
                    type="multiple"
                    value={form.weekdays}
                    onValueChange={(v) => update("weekdays", v)}
                    className="flex flex-wrap gap-2"
                  >
                    {weekdays.map((d) => {
                      const isWeekend = d.value === "6" || d.value === "7";
                      return (
                        <ToggleGroupItem
                          key={d.value}
                          value={d.value}
                          aria-label={d.fullLabel}
                          title={d.fullLabel}
                          className={`h-11 w-12 rounded-md border border-input bg-background text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground data-[state=on]:shadow-sm ${
                            isWeekend ? "text-rose-600 dark:text-rose-400" : ""
                          }`}
                        >
                          {d.label}
                        </ToggleGroupItem>
                      );
                    })}
                  </ToggleGroup>
                  <div className="mt-2 flex flex-wrap gap-2 text-[11px]">
                    <button
                      type="button"
                      className="text-primary hover:underline"
                      onClick={() => update("weekdays", ["1", "2", "3", "4", "5"])}
                    >
                      {t("shop.promotions.weekday_preset.weekdays")}
                    </button>
                    <span className="text-muted-foreground">·</span>
                    <button
                      type="button"
                      className="text-primary hover:underline"
                      onClick={() => update("weekdays", ["6", "7"])}
                    >
                      {t("shop.promotions.weekday_preset.weekend")}
                    </button>
                    <span className="text-muted-foreground">·</span>
                    <button
                      type="button"
                      className="text-primary hover:underline"
                      onClick={() => update("weekdays", ["1", "2", "3", "4", "5", "6", "7"])}
                    >
                      {t("shop.promotions.weekday_preset.all")}
                    </button>
                    <span className="text-muted-foreground">·</span>
                    <button
                      type="button"
                      className="text-muted-foreground hover:underline"
                      onClick={() => update("weekdays", [])}
                    >
                      {t("shop.promotions.weekday_preset.clear")}
                    </button>
                  </div>
                  {fieldErrors.weekdays?.[0] && (
                    <p className="mt-1 text-[11px] text-red-500">{fieldErrors.weekdays[0]}</p>
                  )}
                </div>
              )}
            </div>
          </Card>

          {/* 4b. Live summary preview */}
          <ScheduleSummary form={form} t={t} />

          {/* 5. Stacking */}
          <Card className="p-4">
            <div className="mb-3 text-sm font-semibold">
              {t("shop.promotions.section.stacking")}
            </div>
            <Select
              value={form.stacking_mode}
              onValueChange={(v) => update("stacking_mode", v as MenuPromotionStackingMode)}
            >
              <SelectTrigger className="h-9 text-sm">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={MenuPromotionStackingMode.StackableWithCoupons}>
                  {t("shop.promotions.stacking.stackable_long")}
                </SelectItem>
                <SelectItem value={MenuPromotionStackingMode.ExclusiveWithCoupons}>
                  {t("shop.promotions.stacking.exclusive_long")}
                </SelectItem>
              </SelectContent>
            </Select>
          </Card>

          {/* 6. Active */}
          <Card className="p-4">
            <label className="flex cursor-pointer items-center justify-between gap-2 text-sm">
              <div>
                <div className="font-medium">{t("shop.promotions.field.is_active")}</div>
                <div className="text-xs text-muted-foreground">
                  {t("shop.promotions.hint.is_active")}
                </div>
              </div>
              <Switch checked={form.is_active} onCheckedChange={(v) => update("is_active", v)} />
            </label>
          </Card>
        </div>
      </PageContent>
    </>
  );
}

function Field({
  label,
  required,
  hint,
  error,
  children,
}: {
  label: string;
  required?: boolean;
  hint?: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <Label className="text-xs font-medium text-muted-foreground">
        {label}
        {required && <span className="ml-0.5 text-destructive">*</span>}
      </Label>
      {children}
      {hint && !error && <p className="text-[11px] text-muted-foreground">{hint}</p>}
      {error && <p className="text-[11px] text-red-500">{error}</p>}
    </div>
  );
}

function LockedWrapper({
  locked,
  reason,
  children,
}: {
  locked: boolean;
  reason: string;
  children: React.ReactNode;
}) {
  if (!locked) return <>{children}</>;
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <div className="cursor-not-allowed">{children}</div>
      </TooltipTrigger>
      <TooltipContent>{reason}</TooltipContent>
    </Tooltip>
  );
}

/**
 * Live, human-readable summary of when the promotion will actually apply.
 * Renders the same `form` shape the user is editing — so they see the
 * effect of every toggle / time change without saving.
 */
function ScheduleSummary({
  form,
  t,
}: {
  form: FormState;
  t: (k: string, p?: Record<string, string | number>) => string;
}) {
  const dateRangeReady = !!form.valid_from && !!form.valid_until;
  if (!dateRangeReady) {
    return (
      <Card className="border-dashed p-4 text-sm text-muted-foreground">
        {t("shop.promotions.summary.empty")}
      </Card>
    );
  }

  const fmtDate = (iso: string) => {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    const pad = (n: number) => String(n).padStart(2, "0");
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
  };

  const dateLine = t("shop.promotions.summary.date_range", {
    from: fmtDate(form.valid_from),
    to: fmtDate(form.valid_until),
  });

  let timeLine: string;
  if (form.time_mode === "all_day") {
    timeLine = t("shop.promotions.summary.time_all_day");
  } else if (!form.daily_time_from || !form.daily_time_to) {
    timeLine = t("shop.promotions.summary.time_incomplete");
  } else if (form.daily_time_from < form.daily_time_to) {
    timeLine = t("shop.promotions.summary.time_range", {
      from: form.daily_time_from,
      to: form.daily_time_to,
    });
  } else {
    timeLine = t("shop.promotions.summary.time_cross_midnight", {
      from: form.daily_time_from,
      to: form.daily_time_to,
    });
  }

  let daysLine: string;
  if (form.weekday_mode === "all_days") {
    daysLine = t("shop.promotions.summary.weekdays_all");
  } else if (form.weekdays.length === 0) {
    daysLine = t("shop.promotions.summary.weekdays_incomplete");
  } else if (form.weekdays.length === 7) {
    daysLine = t("shop.promotions.summary.weekdays_all");
  } else {
    const sorted = [...form.weekdays].map(Number).sort((a, b) => a - b);
    const labels = sorted.map((d) => t(`common.weekday.${WEEKDAY_KEYS[d - 1]}`));
    daysLine = t("shop.promotions.summary.weekdays_list", { days: labels.join(", ") });
  }

  return (
    <Card className="border-primary/30 bg-primary/5 p-4 dark:bg-primary/10">
      <div className="mb-2 flex items-center gap-1.5 text-sm font-semibold text-primary">
        <Calendar className="h-4 w-4" aria-hidden />
        {t("shop.promotions.summary.title")}
      </div>
      <ul className="space-y-1 text-sm">
        <li>
          <span className="text-muted-foreground">•</span> {dateLine}
        </li>
        <li>
          <span className="text-muted-foreground">•</span> {daysLine}
        </li>
        <li>
          <span className="text-muted-foreground">•</span> {timeLine}
        </li>
      </ul>
    </Card>
  );
}

const WEEKDAY_KEYS = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"] as const;
