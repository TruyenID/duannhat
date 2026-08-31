"use client";

/**
 * CouponForm — shared create/edit form for HQ coupons (plan-019 S2 / S4).
 *
 * Lock rule (S4): when `coupon.times_used > 0`, the discount math + code are
 * frozen — the user can still edit name/description/validity/scope/limits but
 * the value of money already redeemed cannot be retroactively changed.
 *
 * Sections (single column, max-w-3xl):
 *   1. Identity      — code, name (translatable), description (translatable)
 *   2. Discount math — type, value, percent cap (conditional), min subtotal
 *   3. Validity      — valid_from, valid_until
 *   4. Scope         — applicable branches (empty = all shops in brand)
 *   5. Limits        — usage_limit_total, usage_limit_per_customer
 *   6. Activation    — "Create paused" switch (create-only)
 */

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { Save } from "lucide-react";

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  Button,
  Card,
  Input,
  Label,
  MultiCombobox,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Switch,
  Textarea,
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@godxjp/ui";
import type { TranslatableValue } from "@godxjp/ui";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import { useShops } from "@/hooks/api/use-shops";
import { useCreateCoupon, useUpdateCoupon } from "@/hooks/api/use-coupons";
import type { Coupon, CreateCouponInput, UpdateCouponInput } from "@/services/coupon-service";
import { CouponDiscountType } from "@/types/models/enum/CouponDiscountType";
import { CouponStatus } from "@/types/models/enum/CouponStatus";
import { buildI18nPayload, DEFAULT_LOCALE, emptyLocaleMap } from "@/types/models/payload-helpers";
import { fillLocalesFallback } from "@/lib/i18n-fill";

interface CouponFormProps {
  brandSlug: string;
  /** Coupon being edited; null/undefined → create mode. */
  coupon?: Coupon | null;
}

interface FormState {
  code: string;
  name: Record<string, string>;
  description: Record<string, string>;
  discount_type: CouponDiscountType;
  discount_value: number;
  max_discount_cap: number | null;
  min_order_subtotal: number;
  usage_limit_total: number | null;
  usage_limit_per_customer: number;
  valid_from: string;
  valid_until: string;
  applicable_branch_ids: string[];
  create_paused: boolean;
}

function emptyForm(): FormState {
  return {
    code: "",
    name: emptyLocaleMap(),
    description: emptyLocaleMap(),
    discount_type: CouponDiscountType.Fixed,
    discount_value: 0,
    max_discount_cap: null,
    min_order_subtotal: 0,
    usage_limit_total: null,
    usage_limit_per_customer: 0,
    valid_from: "",
    valid_until: "",
    applicable_branch_ids: [],
    create_paused: false,
  };
}

function hydrate(coupon: Coupon): FormState {
  const name = emptyLocaleMap();
  const description = emptyLocaleMap();
  name[DEFAULT_LOCALE] = coupon.name ?? "";
  description[DEFAULT_LOCALE] = coupon.description ?? "";
  // Backend serializes Astrotomic translations under `translations.{loc}`
  // (see CouponResourceBase). Older code paths may also mirror locale
  // keys at the top level — read both shapes defensively.
  const ext = coupon as unknown as {
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
  return {
    code: coupon.code ?? "",
    name,
    description,
    discount_type: coupon.discount_type,
    discount_value: Number(coupon.discount_value),
    max_discount_cap: coupon.max_discount_cap !== null ? Number(coupon.max_discount_cap) : null,
    min_order_subtotal: Number(coupon.min_order_subtotal),
    usage_limit_total: coupon.usage_limit_total ?? null,
    usage_limit_per_customer: coupon.usage_limit_per_customer,
    valid_from: toLocalDatetime(coupon.valid_from),
    valid_until: toLocalDatetime(coupon.valid_until),
    applicable_branch_ids: coupon.applicable_branch_ids ?? [],
    create_paused: false,
  };
}

function localeMapEqual(a: Record<string, string>, b: Record<string, string>): boolean {
  const keys = new Set([...Object.keys(a), ...Object.keys(b)]);
  for (const k of keys) {
    if ((a[k] ?? "") !== (b[k] ?? "")) return false;
  }
  return true;
}

function arrayEqual(a: string[], b: string[]): boolean {
  if (a.length !== b.length) return false;
  const sortedA = [...a].sort();
  const sortedB = [...b].sort();
  return sortedA.every((v, i) => v === sortedB[i]);
}

function formsEqual(a: FormState, b: FormState): boolean {
  return (
    a.code === b.code &&
    localeMapEqual(a.name, b.name) &&
    localeMapEqual(a.description, b.description) &&
    a.discount_type === b.discount_type &&
    a.discount_value === b.discount_value &&
    a.max_discount_cap === b.max_discount_cap &&
    a.min_order_subtotal === b.min_order_subtotal &&
    a.usage_limit_total === b.usage_limit_total &&
    a.usage_limit_per_customer === b.usage_limit_per_customer &&
    a.valid_from === b.valid_from &&
    a.valid_until === b.valid_until &&
    arrayEqual(a.applicable_branch_ids, b.applicable_branch_ids) &&
    a.create_paused === b.create_paused
  );
}

/** ISO → `<input type="datetime-local">` value in the user's local TZ. */
function toLocalDatetime(iso: string | null | undefined): string {
  if (!iso) return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function CouponForm({ brandSlug, coupon }: CouponFormProps) {
  const { t } = useTranslation();
  const router = useRouter();
  const isEdit = !!coupon;
  const locked = (coupon?.times_used ?? 0) > 0;

  const initialForm = useMemo(() => (coupon ? hydrate(coupon) : emptyForm()), [coupon]);
  const [form, setForm] = useState<FormState>(initialForm);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);
  const [savedAndExiting, setSavedAndExiting] = useState(false);

  useEffect(() => {
    if (coupon) setForm(hydrate(coupon));
  }, [coupon]);

  const isDirty = useMemo(() => formsEqual(form, initialForm) === false, [form, initialForm]);

  // Block the tab from closing / hard refresh when there are unsaved edits.
  // Skipped during the save→navigate transition so the success path isn't
  // interrupted by the browser's native "Leave site?" prompt.
  useEffect(() => {
    if (!isDirty || savedAndExiting) return;
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = "";
    };
    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [isDirty, savedAndExiting]);

  const { data: shopsData } = useShops(brandSlug);
  const shopOptions = (shopsData?.data ?? []).map((s) => ({
    value: s.id,
    label: s.name,
  }));

  const createMutation = useCreateCoupon(brandSlug);
  const updateMutation = useUpdateCoupon(brandSlug);
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
    if (!local) return "";
    // <input type="datetime-local"> emits "YYYY-MM-DDTHH:mm" — extend to ISO.
    return new Date(local).toISOString();
  }

  // Pick the first non-empty locale (ja → en → vi) so the user can fill ANY
  // single language — consistent with materials/recipes. The top-level scalar
  // mirrors this; empty locales are back-filled on submit (fillLocalesFallback)
  // so Astrotomic fallback always renders something instead of an empty name.
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
    // Strip empty-required locales (translatable-forms.md rule 3).
    for (const loc of Object.keys(i18n) as Array<keyof typeof i18n>) {
      if (!i18n[loc]?.name?.trim()) delete i18n[loc];
    }
    const base: CreateCouponInput | UpdateCouponInput = {
      code: form.code.trim().toUpperCase(),
      name: effectiveName,
      description: effectiveDescription || null,
      discount_type: form.discount_type,
      discount_value: form.discount_value,
      max_discount_cap:
        form.discount_type === CouponDiscountType.Percent ? form.max_discount_cap : null,
      min_order_subtotal: form.min_order_subtotal,
      usage_limit_total: form.usage_limit_total,
      usage_limit_per_customer: form.usage_limit_per_customer,
      valid_from: toIso(form.valid_from),
      valid_until: toIso(form.valid_until),
      applicable_branch_ids: form.applicable_branch_ids,
      ...i18n,
    };
    // Create as Draft by default (active immediately after valid_from).
    // "Create paused" switch creates Paused status for prep-only coupons.
    if (!isEdit) {
      (base as CreateCouponInput).status = form.create_paused
        ? CouponStatus.Paused
        : CouponStatus.Draft;
    }

    try {
      if (isEdit && coupon) {
        await updateMutation.mutateAsync({ id: coupon.id, data: base });
        setSavedAndExiting(true);
        router.push(`/hq/${brandSlug}/coupons/${coupon.id}`);
      } else {
        const res = await createMutation.mutateAsync(base as CreateCouponInput);
        setSavedAndExiting(true);
        router.push(`/hq/${brandSlug}/coupons/${res.data.id}`);
      }
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const body = err.body as { errors?: Record<string, string[]> };
        if (body.errors) setFieldErrors(body.errors);
      }
    }
  }

  function handleCancel() {
    if (isDirty) {
      setConfirmExitOpen(true);
      return;
    }
    router.back();
  }

  const title = isEdit ? t("hq.coupons.edit_title") : t("hq.coupons.new_title");
  const description = isEdit ? t("hq.coupons.edit_description") : t("hq.coupons.new_description");
  const canSubmit =
    !!form.code.trim() &&
    !!effectiveName &&
    !!form.valid_from &&
    !!form.valid_until &&
    form.discount_value > 0 &&
    isDirty;

  return (
    <>
      <PageHeader title={title} description={description}>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8"
          onClick={handleCancel}
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
              {t("hq.coupons.locked_banner", { count: coupon!.times_used })}
            </Card>
          )}

          {/* 1. Identity */}
          <Card className="p-4">
            <div className="mb-3 text-sm font-semibold">{t("hq.coupons.section.identity")}</div>
            <div className="flex flex-col gap-4">
              <Field label={t("hq.coupons.field.code")} required error={fieldErrors.code?.[0]}>
                <LockedWrapper locked={locked} reason={t("hq.coupons.lock_reason.code")}>
                  <Input
                    value={form.code}
                    onChange={(e) => update("code", e.target.value.toUpperCase())}
                    placeholder="WELCOME10"
                    maxLength={50}
                    className="h-9 font-mono"
                    disabled={locked}
                  />
                </LockedWrapper>
              </Field>
              <Field label={t("hq.coupons.field.name")} required error={fieldErrors.name?.[0]}>
                <Input
                  translatable
                  value={form.name as TranslatableValue}
                  onChange={(v) => update("name", v as Record<string, string>)}
                  maxLength={255}
                  className="h-9"
                />
              </Field>
              <Field label={t("hq.coupons.field.description")} error={fieldErrors.description?.[0]}>
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

          {/* 2. Discount math */}
          <Card className="p-4">
            <div className="mb-3 text-sm font-semibold">{t("hq.coupons.section.discount")}</div>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <Field
                label={t("hq.coupons.field.discount_type")}
                required
                error={fieldErrors.discount_type?.[0]}
              >
                <LockedWrapper locked={locked} reason={t("hq.coupons.lock_reason.discount")}>
                  <Select
                    value={form.discount_type}
                    onValueChange={(v) => update("discount_type", v as CouponDiscountType)}
                    disabled={locked}
                  >
                    <SelectTrigger className="h-9 text-sm">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value={CouponDiscountType.Fixed}>
                        {t("hq.coupons.discount_type.fixed")}
                      </SelectItem>
                      <SelectItem value={CouponDiscountType.Percent}>
                        {t("hq.coupons.discount_type.percent")}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                </LockedWrapper>
              </Field>
              <Field
                label={t("hq.coupons.field.discount_value")}
                required
                hint={
                  form.discount_type === CouponDiscountType.Percent
                    ? t("hq.coupons.hint.percent")
                    : t("hq.coupons.hint.fixed")
                }
                error={fieldErrors.discount_value?.[0]}
              >
                <LockedWrapper locked={locked} reason={t("hq.coupons.lock_reason.discount")}>
                  <Input
                    type="number"
                    min={0}
                    max={form.discount_type === CouponDiscountType.Percent ? 100 : undefined}
                    step={form.discount_type === CouponDiscountType.Percent ? 0.1 : 1000}
                    value={String(form.discount_value)}
                    onChange={(e) => update("discount_value", Number(e.target.value))}
                    className="h-9"
                    disabled={locked}
                  />
                </LockedWrapper>
              </Field>
              {form.discount_type === CouponDiscountType.Percent && (
                <Field
                  label={t("hq.coupons.field.max_discount_cap")}
                  hint={t("hq.coupons.hint.cap")}
                  error={fieldErrors.max_discount_cap?.[0]}
                >
                  <LockedWrapper locked={locked} reason={t("hq.coupons.lock_reason.discount")}>
                    <Input
                      type="number"
                      min={0}
                      step={1000}
                      value={form.max_discount_cap == null ? "" : String(form.max_discount_cap)}
                      onChange={(e) =>
                        update(
                          "max_discount_cap",
                          e.target.value === "" ? null : Number(e.target.value)
                        )
                      }
                      className="h-9"
                      disabled={locked}
                    />
                  </LockedWrapper>
                </Field>
              )}
              <Field
                label={t("hq.coupons.field.min_order_subtotal")}
                hint={t("hq.coupons.hint.min_subtotal")}
                error={fieldErrors.min_order_subtotal?.[0]}
              >
                <Input
                  type="number"
                  min={0}
                  step={1000}
                  value={String(form.min_order_subtotal)}
                  onChange={(e) => update("min_order_subtotal", Number(e.target.value))}
                  className="h-9"
                />
              </Field>
            </div>
          </Card>

          {/* 3. Validity */}
          <Card className="p-4">
            <div className="mb-3 text-sm font-semibold">{t("hq.coupons.section.validity")}</div>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <Field
                label={t("hq.coupons.field.valid_from")}
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
                label={t("hq.coupons.field.valid_until")}
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
          </Card>

          {/* 4. Scope */}
          <Card className="p-4">
            <div className="mb-3 text-sm font-semibold">{t("hq.coupons.section.scope")}</div>
            <Field
              label={t("hq.coupons.field.applicable_branches")}
              hint={t("hq.coupons.hint.applicable_branches")}
              error={fieldErrors.applicable_branch_ids?.[0]}
            >
              <MultiCombobox
                options={shopOptions}
                value={form.applicable_branch_ids}
                onChange={(v) => update("applicable_branch_ids", v)}
                placeholder={t("hq.coupons.placeholder.all_shops")}
                searchPlaceholder={t("hq.coupons.placeholder.search_shops")}
                className="h-9 w-full"
              />
            </Field>
          </Card>

          {/* 5. Limits */}
          <Card className="p-4">
            <div className="mb-3 text-sm font-semibold">{t("hq.coupons.section.limits")}</div>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <Field
                label={t("hq.coupons.field.usage_limit_total")}
                hint={t("hq.coupons.hint.usage_limit_total")}
                error={fieldErrors.usage_limit_total?.[0]}
              >
                <Input
                  type="number"
                  min={0}
                  step={1}
                  value={form.usage_limit_total == null ? "" : String(form.usage_limit_total)}
                  onChange={(e) =>
                    update(
                      "usage_limit_total",
                      e.target.value === "" ? null : Number(e.target.value)
                    )
                  }
                  placeholder={t("hq.coupons.placeholder.unlimited")}
                  className="h-9"
                />
              </Field>
              <Field
                label={t("hq.coupons.field.usage_limit_per_customer")}
                hint={t("hq.coupons.hint.usage_limit_per_customer")}
                error={fieldErrors.usage_limit_per_customer?.[0]}
              >
                <Input
                  type="number"
                  min={0}
                  step={1}
                  value={String(form.usage_limit_per_customer)}
                  onChange={(e) => update("usage_limit_per_customer", Number(e.target.value))}
                  className="h-9"
                />
              </Field>
            </div>
          </Card>

          {/* 6. Activation (create only) */}
          {!isEdit && (
            <Card className="p-4">
              <label className="flex cursor-pointer items-center justify-between gap-2 text-sm">
                <div>
                  <div className="font-medium">{t("hq.coupons.field.create_paused")}</div>
                  <div className="text-xs text-muted-foreground">
                    {t("hq.coupons.hint.create_paused")}
                  </div>
                </div>
                <Switch
                  checked={form.create_paused}
                  onCheckedChange={(v) => update("create_paused", v)}
                />
              </label>
            </Card>
          )}
        </div>
      </PageContent>

      <AlertDialog open={confirmExitOpen} onOpenChange={setConfirmExitOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("common.unsaved.title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("common.unsaved.desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isPending}>
              {t("common.unsaved.continue_editing")}
            </AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                setConfirmExitOpen(false);
                setSavedAndExiting(true);
                router.back();
              }}
              disabled={isPending}
            >
              {t("common.unsaved.discard")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}

interface FieldProps {
  label: string;
  required?: boolean;
  hint?: string;
  error?: string;
  children: React.ReactNode;
}

function Field({ label, required, hint, error, children }: FieldProps) {
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
