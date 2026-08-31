"use client";

import { useEffect, useState } from "react";

import { Button, Checkbox, Input, Spinner } from "@godxjp/ui";
import type { TranslatableValue } from "@godxjp/ui";
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@godxjp/ui";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";

import { ApiError } from "@/lib/api";
import { useCreateAllergen, useUpdateAllergen } from "@/hooks/api/use-allergens";
import type {
  Allergen,
  CreateAllergenInput,
  UpdateAllergenInput,
} from "@/services/allergen-service";
import {
  AllergenJurisdiction,
  AllergenJurisdictionValues,
} from "@/types/models/enum/AllergenJurisdiction";
import { AllergenSeverity, AllergenSeverityValues } from "@/types/models/enum/AllergenSeverity";
import { useTranslation } from "@/providers/app-provider";
import { buildI18nPayload, DEFAULT_LOCALE, emptyLocaleMap } from "@/types/models/payload-helpers";

export interface AllergenFormDialogProps {
  brandSlug: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Allergen being edited; null/undefined means create mode */
  allergen?: Allergen | null;
}

interface FormState {
  code: string;
  name: Record<string, string>;
  jurisdiction: AllergenJurisdiction;
  severity: AllergenSeverity;
  is_active: boolean;
}

function emptyForm(): FormState {
  return {
    code: "",
    name: emptyLocaleMap(),
    jurisdiction: AllergenJurisdiction.Jp,
    severity: AllergenSeverity.Mandatory,
    is_active: true,
  };
}

function hydrateForm(allergen: Allergen): FormState {
  const name = emptyLocaleMap();
  if (allergen.translations) {
    for (const locale of ["ja", "en", "vi"] as const) {
      name[locale] = allergen.translations[locale]?.name ?? "";
    }
  } else {
    name[DEFAULT_LOCALE] = allergen.name ?? "";
  }
  return {
    code: allergen.code ?? "",
    name,
    jurisdiction: allergen.jurisdiction,
    severity: allergen.severity,
    is_active: allergen.is_active,
  };
}

export function AllergenFormDialog({
  brandSlug,
  open,
  onOpenChange,
  allergen,
}: AllergenFormDialogProps) {
  const { t } = useTranslation();
  const isEdit = !!allergen;
  const [form, setForm] = useState<FormState>(emptyForm);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);
  // Tracks user edits — drives the unsaved-changes guard on close. Set only by
  // the `update` helper (all field inputs go through it); the hydrate effect
  // resets it so opening the dialog never counts as dirty.
  const [dirty, setDirty] = useState(false);

  const createMutation = useCreateAllergen(brandSlug);
  const updateMutation = useUpdateAllergen(brandSlug);
  const isPending = createMutation.isPending || updateMutation.isPending;

  useEffect(() => {
    if (!open) {
      setFieldErrors({});
      return;
    }
    setForm(allergen ? hydrateForm(allergen) : emptyForm());
    setFieldErrors({});
    setDirty(false);
  }, [open, allergen]);

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

  // Guards every close path (Cancel button, the X, Esc, overlay click — all
  // route through the Dialog's onOpenChange): confirm before discarding a
  // dirty form, otherwise close immediately.
  function requestClose() {
    if (dirty && !isPending) {
      setConfirmExitOpen(true);
    } else {
      onOpenChange(false);
    }
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFieldErrors({});

    const i18n = buildI18nPayload({ name: form.name });
    for (const locale of Object.keys(i18n) as Array<keyof typeof i18n>) {
      if (!i18n[locale]?.name?.trim()) delete i18n[locale];
    }

    const payload = {
      code: form.code.trim(),
      name: (form.name[DEFAULT_LOCALE] ?? "").trim(),
      jurisdiction: form.jurisdiction,
      severity: form.severity,
      is_active: form.is_active,
      ...i18n,
    };

    try {
      if (isEdit && allergen) {
        await updateMutation.mutateAsync({ id: allergen.id, data: payload as UpdateAllergenInput });
      } else {
        await createMutation.mutateAsync(payload as CreateAllergenInput);
      }
      onOpenChange(false);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        const body = e.body as { errors?: Record<string, string[]> };
        if (body.errors) setFieldErrors(body.errors);
      }
    }
  }

  return (
    <>
      <Dialog
        open={open}
        onOpenChange={(next) => {
          // Intercept close attempts so a dirty form prompts for confirmation;
          // opening (next === true) always passes through.
          if (next) {
            onOpenChange(true);
          } else {
            requestClose();
          }
        }}
      >
        <DialogContent className="flex max-h-[90vh] flex-col gap-0 sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>
              {isEdit ? t("hq.allergens.dialog.edit_title") : t("hq.allergens.dialog.create_title")}
            </DialogTitle>
            <DialogDescription>
              {isEdit ? t("hq.allergens.dialog.edit_desc") : t("hq.allergens.dialog.create_desc")}
            </DialogDescription>
          </DialogHeader>

          <form
            onSubmit={handleSubmit}
            className="flex flex-1 flex-col gap-3 overflow-y-auto px-1 py-3 text-sm"
          >
            <Field label={t("hq.allergens.field.code")} required error={fieldErrors.code?.[0]}>
              <Input
                value={form.code}
                // Backend rule is `^[a-z0-9_]+$` (lowercase, digits, underscore).
                // Sanitize as the user types so the code can never violate it:
                // lowercase, spaces → underscore, strip anything else.
                onChange={(e) =>
                  update(
                    "code",
                    e.target.value
                      .toLowerCase()
                      .replace(/\s+/g, "_")
                      .replace(/[^a-z0-9_]/g, "")
                  )
                }
                required
                maxLength={60}
                placeholder={t("hq.allergens.field.code_placeholder")}
                autoFocus={!isEdit}
                disabled={isEdit}
              />
            </Field>

            <Field
              label={t("hq.allergens.field.name")}
              required
              error={fieldErrors["ja.name"]?.[0] ?? fieldErrors.name?.[0]}
            >
              <Input
                translatable
                value={form.name as TranslatableValue}
                onChange={(value) => update("name", value as Record<string, string>)}
                maxLength={120}
                placeholder={t("hq.allergens.field.name_placeholder")}
              />
            </Field>

            <div className="grid grid-cols-2 gap-3">
              <Field
                label={t("hq.allergens.field.jurisdiction")}
                required
                error={fieldErrors.jurisdiction?.[0]}
              >
                <Select
                  value={form.jurisdiction}
                  onValueChange={(v) => update("jurisdiction", v as AllergenJurisdiction)}
                >
                  <SelectTrigger className="h-8 text-xs">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {AllergenJurisdictionValues.map((j) => (
                      <SelectItem key={j} value={j}>
                        {t(`hq.allergens.jurisdiction.${j}`)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>

              <Field
                label={t("hq.allergens.field.severity")}
                required
                error={fieldErrors.severity?.[0]}
              >
                <Select
                  value={form.severity}
                  onValueChange={(v) => update("severity", v as AllergenSeverity)}
                >
                  <SelectTrigger className="h-8 text-xs">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {AllergenSeverityValues.map((s) => (
                      <SelectItem key={s} value={s}>
                        {t(`hq.allergens.severity.${s}`)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
            </div>

            <label className="flex items-center gap-2 text-sm">
              <Checkbox
                checked={form.is_active}
                onCheckedChange={(v) => update("is_active", v === true)}
              />
              {t("hq.allergens.field.is_active")}
            </label>
          </form>

          <DialogFooter className="border-t pt-3">
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={requestClose}
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

      {/* Unsaved-changes guard — confirm before discarding a dirty form. */}
      <AlertDialog open={confirmExitOpen} onOpenChange={setConfirmExitOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.products.unsaved.title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("hq.products.unsaved.desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isPending}>
              {t("hq.products.unsaved.continue_editing")}
            </AlertDialogCancel>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => {
                setConfirmExitOpen(false);
                onOpenChange(false);
              }}
              disabled={isPending}
            >
              {t("hq.products.unsaved.exit_without_saving")}
            </Button>
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
