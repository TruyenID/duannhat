"use client";

import { useEffect, useState } from "react";

import { Button } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import { Checkbox } from "@godxjp/ui";
import { Textarea } from "@godxjp/ui";
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
import { useCreateProductType, useUpdateProductType } from "@/hooks/api/use-product-types";
import type {
  CreateProductTypeInput,
  ProductType,
  UpdateProductTypeInput,
} from "@/services/product-type-service";
import type { ProductTypeProductForm } from "@/types/models/enum/ProductTypeProductForm";
import { useTranslation } from "@/providers/app-provider";
import { buildI18nPayload, DEFAULT_LOCALE, emptyLocaleMap } from "@/types/models/payload-helpers";

import { Spinner } from "@godxjp/ui";
interface ProductTypeFormDialogProps {
  brandSlug: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** ProductType being edited; null/undefined means create mode */
  productType?: ProductType | null;
}

interface FormState {
  code: string;
  name: Record<string, string>;
  description: Record<string, string>;
  product_form: ProductTypeProductForm;
  has_recipe: boolean;
  is_inventory_tracked: boolean;
  icon: string;
  is_active: boolean;
}

function emptyForm(): FormState {
  return {
    code: "",
    name: emptyLocaleMap(),
    description: emptyLocaleMap(),
    product_form: "physical",
    has_recipe: false,
    is_inventory_tracked: true,
    icon: "",
    is_active: true,
  };
}

export function ProductTypeFormDialog({
  brandSlug,
  open,
  onOpenChange,
  productType,
}: ProductTypeFormDialogProps) {
  const { t } = useTranslation();
  const isEdit = !!productType;
  const [form, setForm] = useState<FormState>(emptyForm);
  const [initialForm, setInitialForm] = useState<FormState>(emptyForm);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [confirmDiscard, setConfirmDiscard] = useState(false);

  const createMutation = useCreateProductType(brandSlug);
  const updateMutation = useUpdateProductType(brandSlug);
  const isPending = createMutation.isPending || updateMutation.isPending;

  // Reset form when dialog opens or productType changes.
  useEffect(() => {
    if (!open) {
      setFieldErrors({});
      setConfirmDiscard(false);
      return;
    }
    let next: FormState;
    if (productType) {
      // Hydrate per-locale fields from the translations map returned by the
      // backend. Fall back to the top-level name in DEFAULT_LOCALE slot when
      // no translation data is present (e.g. legacy records).
      const name = emptyLocaleMap();
      const description = emptyLocaleMap();
      const t = productType.translations;
      if (t) {
        for (const locale of ["ja", "en", "vi"] as const) {
          name[locale] = t[locale]?.name ?? "";
          description[locale] = t[locale]?.description ?? "";
        }
      } else {
        name[DEFAULT_LOCALE] = productType.name ?? "";
        description[DEFAULT_LOCALE] = productType.description ?? "";
      }
      next = {
        code: productType.code ?? "",
        name,
        description,
        product_form: productType.product_form,
        has_recipe: productType.has_recipe,
        is_inventory_tracked: productType.is_inventory_tracked,
        icon: productType.icon ?? "",
        is_active: productType.is_active,
      };
    } else {
      next = emptyForm();
    }
    setForm(next);
    setInitialForm(next);
    setFieldErrors({});
  }, [open, productType]);

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

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFieldErrors({});

    const i18n = buildI18nPayload({ name: form.name, description: form.description });
    // product_type_translations.name is NOT NULL — drop locales where the
    // required field is empty so the sidecar insert doesn't violate the
    // constraint. See docs/contributing/translatable-forms.md § Rule 3.
    for (const locale of Object.keys(i18n) as Array<keyof typeof i18n>) {
      if (!i18n[locale]?.name?.trim()) delete i18n[locale];
    }
    const topName =
      form.name[DEFAULT_LOCALE]?.trim() || form.name["en"]?.trim() || form.name["vi"]?.trim() || "";
    const topDescription =
      form.description[DEFAULT_LOCALE]?.trim() ||
      form.description["en"]?.trim() ||
      form.description["vi"]?.trim() ||
      "";
    const payload = {
      code: form.code.trim(),
      name: topName,
      description: topDescription || undefined,
      product_form: form.product_form,
      has_recipe: form.has_recipe,
      is_inventory_tracked: form.is_inventory_tracked,
      icon: form.icon.trim() || undefined,
      is_active: form.is_active,
      ...i18n,
    };

    try {
      if (isEdit && productType) {
        await updateMutation.mutateAsync({
          id: productType.id,
          data: payload as unknown as UpdateProductTypeInput,
        });
      } else {
        await createMutation.mutateAsync(payload as unknown as CreateProductTypeInput);
      }
      onOpenChange(false);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        const body = e.body as { errors?: Record<string, string[]> };
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
              {isEdit
                ? t("hq.product_types.dialog.edit_title")
                : t("hq.product_types.dialog.create_title")}
            </DialogTitle>
            <DialogDescription>
              {isEdit
                ? t("hq.product_types.dialog.edit_desc")
                : t("hq.product_types.dialog.create_desc")}
            </DialogDescription>
          </DialogHeader>

          <form
            onSubmit={handleSubmit}
            className="flex flex-1 flex-col gap-3 overflow-y-auto px-1 py-3 text-sm"
          >
            <Field label={t("hq.product_types.field.code")} required error={fieldErrors.code?.[0]}>
              <Input
                value={form.code}
                onChange={(e) => update("code", e.target.value.toUpperCase())}
                required
                maxLength={50}
                placeholder={t("hq.product_types.placeholder.code")}
                autoFocus={!isEdit}
                disabled={isEdit}
              />
            </Field>

            <Field
              label={t("hq.product_types.field.name")}
              required
              error={fieldErrors["ja.name"]?.[0] ?? fieldErrors.name?.[0]}
            >
              <Input
                translatable
                value={form.name as TranslatableValue}
                onChange={(value) => update("name", value as Record<string, string>)}
                maxLength={100}
                placeholder={t("hq.product_types.placeholder.name")}
              />
            </Field>

            <Field
              label={t("hq.product_types.field.description")}
              error={fieldErrors.description?.[0]}
            >
              <Textarea
                translatable
                value={form.description as TranslatableValue}
                onChange={(value) => update("description", value as Record<string, string>)}
                rows={3}
                maxLength={1000}
                className="field-sizing-fixed"
              />
            </Field>

            <Field
              label={t("hq.product_types.field.product_form")}
              required
              error={fieldErrors.product_form?.[0]}
            >
              <div className="flex gap-2">
                {(["physical", "digital"] as const).map((value) => {
                  const active = form.product_form === value;
                  return (
                    <button
                      key={value}
                      type="button"
                      onClick={() => update("product_form", value)}
                      className={`h-8 flex-1 rounded-md border px-3 text-sm font-medium transition-colors ${
                        active
                          ? "border-primary bg-primary text-primary-foreground"
                          : "border-input bg-background hover:bg-accent"
                      }`}
                    >
                      {value === "physical"
                        ? t("hq.product_types.form.physical")
                        : t("hq.product_types.form.digital")}
                    </button>
                  );
                })}
              </div>
            </Field>

            <Field label={t("hq.product_types.field.icon")} error={fieldErrors.icon?.[0]}>
              <Input
                value={form.icon}
                onChange={(e) => update("icon", e.target.value)}
                maxLength={50}
                placeholder={t("hq.product_types.placeholder.icon")}
              />
            </Field>

            <label className="flex items-center gap-2 text-sm">
              <Checkbox
                checked={form.has_recipe}
                onCheckedChange={(v) => update("has_recipe", v === true)}
              />
              {t("hq.product_types.field.has_recipe")}
            </label>

            <label className="flex items-center gap-2 text-sm">
              <Checkbox
                checked={form.is_inventory_tracked}
                onCheckedChange={(v) => update("is_inventory_tracked", v === true)}
              />
              {t("hq.product_types.field.track_inventory")}
            </label>

            <label className="flex items-center gap-2 text-sm">
              <Checkbox
                checked={form.is_active}
                onCheckedChange={(v) => update("is_active", v === true)}
              />
              {t("hq.product_types.field.active")}
            </label>
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
