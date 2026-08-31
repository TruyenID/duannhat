"use client";

import { useEffect, useState } from "react";

import { Button } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import { Checkbox } from "@godxjp/ui";
import { Switch } from "@godxjp/ui";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";
import type { TranslatableValue } from "@godxjp/ui";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@godxjp/ui";
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
import { ApiError } from "@/lib/api";
import { useCreateTaxType, useUpdateTaxType } from "@/hooks/api/use-tax-types";
import type {
  CreateTaxTypeInput,
  TaxClassificationValue,
  TaxType,
  UpdateTaxTypeInput,
} from "@/services/tax-type-service";
import { useTranslation } from "@/providers/app-provider";
import { buildI18nPayload, DEFAULT_LOCALE, emptyLocaleMap } from "@/types/models/payload-helpers";

import { Spinner } from "@godxjp/ui";

interface TaxTypeFormDialogProps {
  brandSlug: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** TaxType being edited; null/undefined means create mode */
  taxType?: TaxType | null;
  /** Whether another default already exists — powers the "replaces current default" warning. */
  hasExistingDefault?: boolean;
}

/**
 * #1367 — Radix Select không nhận `""` làm value, nên "chưa phân loại" đi bằng
 * sentinel này (cùng cách `product-sidebar` xử tax type kế thừa).
 */
const UNCLASSIFIED_SENTINEL = "__unclassified__";

/** Thứ tự hiển thị: 課税 trước, rồi ba loại 0% theo mức độ "xa phạm vi" dần. */
const CLASSIFICATIONS: TaxClassificationValue[] = [
  "taxable",
  "exempt",
  "out_of_scope",
  "zero_rated",
];

interface FormState {
  code: string;
  name: Record<string, string>;
  rate: string;
  classification: string;
  is_default: boolean;
  is_active: boolean;
}

function emptyForm(): FormState {
  return {
    code: "",
    name: emptyLocaleMap(),
    rate: "10",
    // Mặc định KHÔNG chọn sẵn `taxable`: loại thuế mới rất có thể là một trong
    // ba loại 0%, và chọn sẵn là biến một ô bắt-người-nghĩ thành một ô bấm-qua.
    classification: UNCLASSIFIED_SENTINEL,
    is_default: false,
    is_active: true,
  };
}

export function TaxTypeFormDialog({
  brandSlug,
  open,
  onOpenChange,
  taxType,
  hasExistingDefault = false,
}: TaxTypeFormDialogProps) {
  const { t } = useTranslation();
  const isEdit = !!taxType;
  const [form, setForm] = useState<FormState>(emptyForm);
  const [initialForm, setInitialForm] = useState<FormState>(emptyForm);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [confirmDiscard, setConfirmDiscard] = useState(false);

  const createMutation = useCreateTaxType(brandSlug);
  const updateMutation = useUpdateTaxType(brandSlug);
  const isPending = createMutation.isPending || updateMutation.isPending;

  // Reset form when dialog opens or taxType changes.
  useEffect(() => {
    if (!open) {
      setFieldErrors({});
      setConfirmDiscard(false);
      return;
    }
    let next: FormState;
    if (taxType) {
      const name = emptyLocaleMap();
      const tr = taxType.translations;
      if (tr) {
        for (const locale of ["ja", "en", "vi"] as const) {
          name[locale] = tr[locale]?.name ?? "";
        }
      } else {
        name[DEFAULT_LOCALE] = taxType.name ?? "";
      }
      next = {
        code: taxType.code ?? "",
        name,
        rate: String(taxType.rate ?? 0),
        classification: taxType.classification ?? UNCLASSIFIED_SENTINEL,
        is_default: taxType.is_default,
        is_active: taxType.is_active,
      };
    } else {
      next = emptyForm();
    }
    setForm(next);
    setInitialForm(next);
    setFieldErrors({});
  }, [open, taxType]);

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((prev) => ({ ...prev, [key]: value }));
    if (fieldErrors[key]) {
      setFieldErrors((prev) => {
        const next = { ...prev };
        delete next[key];
        return next;
      });
    }
  }

  function isDirty(): boolean {
    return JSON.stringify(form) !== JSON.stringify(initialForm);
  }

  function handleCancel() {
    if (isDirty()) {
      setConfirmDiscard(true);
    } else {
      onOpenChange(false);
    }
  }

  // Warn only when turning ON a new default that would displace an existing one.
  const showDefaultWarning =
    form.is_default && hasExistingDefault && !(taxType?.is_default ?? false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFieldErrors({});

    const i18n = buildI18nPayload({ name: form.name });
    // tax_type_translations.name is NOT NULL — drop locales where the required
    // field is empty. See docs/contributing/translatable-forms.md § Rule 3.
    for (const locale of Object.keys(i18n) as Array<keyof typeof i18n>) {
      if (!i18n[locale]?.name?.trim()) delete i18n[locale];
    }
    const topName =
      form.name[DEFAULT_LOCALE]?.trim() || form.name["en"]?.trim() || form.name["vi"]?.trim() || "";

    const payload = {
      code: form.code.trim(),
      name: topName,
      rate: Number(form.rate) || 0,
      classification:
        form.classification === UNCLASSIFIED_SENTINEL
          ? null
          : (form.classification as TaxClassificationValue),
      is_default: form.is_default,
      is_active: form.is_active,
      ...i18n,
    };

    try {
      if (isEdit && taxType) {
        await updateMutation.mutateAsync({
          id: taxType.id,
          data: payload as unknown as UpdateTaxTypeInput,
        });
      } else {
        await createMutation.mutateAsync(payload as unknown as CreateTaxTypeInput);
      }
      onOpenChange(false);
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const body = err.body as { errors?: Record<string, string[]> };
        if (body.errors) {
          setFieldErrors(body.errors);
        }
      }
      // Non-422 errors are surfaced via the hook's toast already.
    }
  }

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="flex max-h-[90vh] flex-col gap-0 sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>
              {isEdit ? t("hq.tax_types.dialog.edit_title") : t("hq.tax_types.dialog.create_title")}
            </DialogTitle>
            <DialogDescription>
              {isEdit ? t("hq.tax_types.dialog.edit_desc") : t("hq.tax_types.dialog.create_desc")}
            </DialogDescription>
          </DialogHeader>

          <form
            onSubmit={handleSubmit}
            className="flex flex-1 flex-col gap-3 overflow-y-auto px-1 py-3 text-sm"
          >
            <Field label={t("hq.tax_types.field.code")} required error={fieldErrors.code?.[0]}>
              <Input
                value={form.code}
                onChange={(e) => update("code", e.target.value.toUpperCase())}
                required
                maxLength={50}
                placeholder={t("hq.tax_types.placeholder.code")}
                autoFocus={!isEdit}
                disabled={isEdit}
              />
              {isEdit && (
                <span className="text-xs text-muted-foreground">
                  {t("hq.tax_types.field.code_immutable")}
                </span>
              )}
            </Field>

            <Field
              label={t("hq.tax_types.field.name")}
              required
              error={fieldErrors["ja.name"]?.[0] ?? fieldErrors.name?.[0]}
            >
              <Input
                translatable
                value={form.name as TranslatableValue}
                onChange={(value) => update("name", value as Record<string, string>)}
                maxLength={100}
                placeholder={t("hq.tax_types.placeholder.name")}
              />
            </Field>

            <Field label={t("hq.tax_types.field.rate")} required error={fieldErrors.rate?.[0]}>
              <div className="relative">
                <Input
                  type="number"
                  inputMode="decimal"
                  min={0}
                  max={100}
                  step="0.01"
                  value={form.rate}
                  onChange={(e) => update("rate", e.target.value)}
                  className="pr-8"
                />
                <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">
                  %
                </span>
              </div>
            </Field>
            <p className="-mt-1 text-xs text-muted-foreground">
              {t("hq.tax_types.field.rate_hint")}
            </p>

            {/*
              #1367 — trục phân loại, tách khỏi thuế suất. Đặt NGAY DƯỚI ô thuế
              suất vì đó là chỗ người dùng dễ nhầm hai thứ này làm một: ba loại
              0% trông giống hệt nhau trên màn danh sách nếu chỉ nhìn %.
            */}
            <Field
              label={t("hq.tax_types.field.classification")}
              error={fieldErrors.classification?.[0]}
            >
              <Select
                value={form.classification}
                onValueChange={(v) => update("classification", v)}
              >
                <SelectTrigger className="h-9 w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={UNCLASSIFIED_SENTINEL}>
                    {t("hq.tax_types.classification.unclassified")}
                  </SelectItem>
                  {CLASSIFICATIONS.map((c) => (
                    <SelectItem key={c} value={c}>
                      {t(`hq.tax_types.classification.${c}`)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>
            <p className="-mt-1 text-xs text-muted-foreground">
              {t("hq.tax_types.field.classification_hint")}
            </p>

            <label className="flex items-center gap-2 text-sm">
              <Checkbox
                checked={form.is_default}
                onCheckedChange={(v) => update("is_default", v === true)}
              />
              {t("hq.tax_types.field.is_default")}
            </label>
            {showDefaultWarning && (
              <p className="-mt-1 ml-6 text-xs text-amber-600">
                {t("hq.tax_types.field.is_default_warning")}
              </p>
            )}

            <div className="flex items-center gap-3">
              <Switch
                id="tax-type-active"
                checked={form.is_active}
                onCheckedChange={(v) => update("is_active", v)}
              />
              <label htmlFor="tax-type-active" className="cursor-pointer text-sm">
                {t("hq.tax_types.field.active")}
              </label>
            </div>
          </form>

          <DialogFooter className="border-t pt-3">
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={handleCancel}
              disabled={isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button type="button" size="sm" onClick={handleSubmit} disabled={isPending}>
              {isPending && <Spinner className="mr-1.5 size-3.5" />}
              {isEdit ? t("common.save_changes") : t("common.create")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={confirmDiscard} onOpenChange={setConfirmDiscard}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("common.discard_changes")}</AlertDialogTitle>
            <AlertDialogDescription>{t("common.discard_changes_desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.continue_editing")}</AlertDialogCancel>
            <AlertDialogAction onClick={() => onOpenChange(false)}>
              {t("common.discard")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}

function Field({
  label,
  required,
  error,
  children,
}: {
  label: string;
  required?: boolean;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex flex-col gap-1">
      <label className="text-xs font-medium text-muted-foreground">
        {label}
        {required && <span className="ml-0.5 text-destructive">*</span>}
      </label>
      {children}
      {error && <span className="text-xs text-destructive">{error}</span>}
    </div>
  );
}
