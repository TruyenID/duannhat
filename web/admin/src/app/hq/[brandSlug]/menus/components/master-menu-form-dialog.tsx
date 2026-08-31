"use client";

import { useEffect, useRef, useState } from "react";

import { Button } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import { Textarea } from "@godxjp/ui";
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
import { Spinner } from "@godxjp/ui";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import { useCreateMasterMenu, useUpdateMenu } from "@/hooks/api/use-menus";
import type { Menu } from "@/services/menu-service";
import { buildI18nPayload, DEFAULT_LOCALE, emptyLocaleMap } from "@/types/models/payload-helpers";
import { fillLocalesFallback } from "@/lib/i18n-fill";

export interface MasterMenuFormDialogProps {
  brandSlug: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** When provided, dialog opens in edit mode and only updates name/description. */
  menu?: Menu | null;
}

// #463 — which ordering flow the menu shows in. "Both" (default + every legacy
// menu) shows in takeaway + dine-in; "Takeaway"/"DineIn" restrict to one flow.
type ServiceType = "Takeaway" | "DineIn" | "Both";

interface FormState {
  name: Record<string, string>;
  description: Record<string, string>;
  service_type: ServiceType;
}

function emptyForm(): FormState {
  return { name: emptyLocaleMap(), description: emptyLocaleMap(), service_type: "Both" };
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
      {error && <span role="alert" className="text-xs text-destructive">{error}</span>}
    </div>
  );
}

export function MasterMenuFormDialog({
  brandSlug,
  open,
  onOpenChange,
  menu,
}: MasterMenuFormDialogProps) {
  const dialogRef = useRef<HTMLDivElement>(null);
  const { t } = useTranslation();
  const isEdit = !!menu;
  const [form, setForm] = useState<FormState>(() => emptyForm());
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);
  // Tracks user edits — drives the unsaved-changes guard on close. Set only by
  // the `update` helper; the hydrate effect resets it so opening the dialog
  // never counts as dirty.
  const [dirty, setDirty] = useState(false);

  const createMutation = useCreateMasterMenu(brandSlug);
  const updateMutation = useUpdateMenu(brandSlug);
  const isPending = createMutation.isPending || updateMutation.isPending;

  useEffect(() => {
    if (!open) return;
    if (menu) {
      const name = emptyLocaleMap();
      const description = emptyLocaleMap();
      for (const locale of ["ja", "en", "vi"] as const) {
        name[locale] = menu.translations?.[locale]?.name ?? "";
        description[locale] = menu.translations?.[locale]?.description ?? "";
      }
      if (!Object.values(name).some((value) => value.trim())) name[DEFAULT_LOCALE] = menu.name;
      if (!Object.values(description).some((value) => value.trim())) {
        description[DEFAULT_LOCALE] = menu.description ?? "";
      }
      setForm({
        name,
        description,
        service_type: (menu.service_type as ServiceType) ?? "Both",
      });
    } else {
      setForm(emptyForm());
    }
    setFieldErrors({});
    setDirty(false);
  }, [open, menu]);

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setDirty(true);
    setForm((prev) => ({ ...prev, [key]: value }));
    setFieldErrors((prev) => {
      if (!prev[key]) return prev;
      const next = { ...prev };
      delete next[key];
      return next;
    });
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

  async function handleSubmit() {
    const localizedNames = fillLocalesFallback(form.name);
    const hasDescription = Object.values(form.description).some((value) => value.trim());
    const localizedDescriptions = hasDescription
      ? fillLocalesFallback(form.description)
      : form.description;
    const payload = {
      name: localizedNames[DEFAULT_LOCALE],
      description: hasDescription ? localizedDescriptions[DEFAULT_LOCALE] : null,
      ...buildI18nPayload({ name: localizedNames, description: localizedDescriptions }),
      service_type: form.service_type,
    };

    try {
      if (isEdit && menu) {
        // Master menus only expose name + description for editing — the
        // template doesn't carry valid_from/to or priority semantics.
        await updateMutation.mutateAsync({
          id: menu.id,
          data: { ...payload, updated_at: menu.updated_at },
        });
      } else {
        await createMutation.mutateAsync(payload);
      }
      onOpenChange(false);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        const body = e.body as { errors?: Record<string, string[]> };
        if (body.errors) {
          setFieldErrors(body.errors);
          requestAnimationFrame(() => {
            dialogRef.current
              ?.querySelector<HTMLElement>('input[data-translatable], textarea[data-translatable]')
              ?.focus();
          });
        }
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
        <DialogContent
          ref={dialogRef}
          data-slot="master-menu-form-dialog"
          className="flex max-h-[90vh] flex-col gap-0 sm:max-w-lg"
        >
          <DialogHeader>
            <DialogTitle>
              {isEdit
                ? t("hq.menus.master_form.edit_title")
                : t("hq.menus.master_form.create_title")}
            </DialogTitle>
            <DialogDescription>{t("hq.menus.master_form.desc")}</DialogDescription>
          </DialogHeader>

          <div className="flex flex-1 flex-col gap-3 overflow-y-auto px-1 py-3 text-sm">
            <Field
              label={t("common.name")}
              required
              error={
                fieldErrors["ja.name"]?.[0] ??
                fieldErrors["en.name"]?.[0] ??
                fieldErrors["vi.name"]?.[0] ??
                fieldErrors.name?.[0]
              }
            >
              <Input
                translatable
                autoFocus
                placeholder={t("hq.menus.master_form.name_placeholder")}
                value={form.name as TranslatableValue}
                onChange={(value) => update("name", value as Record<string, string>)}
              />
            </Field>

            <Field
              label={t("common.description")}
              error={
                fieldErrors["ja.description"]?.[0] ??
                fieldErrors["en.description"]?.[0] ??
                fieldErrors["vi.description"]?.[0] ??
                fieldErrors.description?.[0]
              }
            >
              <Textarea
                translatable
                rows={3}
                maxLength={1000}
                placeholder={t("hq.menus.master_form.desc_placeholder")}
                value={form.description as TranslatableValue}
                onChange={(value) => update("description", value as Record<string, string>)}
              />
            </Field>

            {/* #463 — service-type gate: which customer flow shows this menu. */}
            <Field
              label={t("hq.menus.form.service_type")}
              error={fieldErrors.service_type?.[0]}
            >
              <div className="grid grid-cols-3 gap-2">
                {(["Both", "Takeaway", "DineIn"] as const).map((value) => {
                  const selected = form.service_type === value;
                  const labelKey =
                    value === "Both"
                      ? "hq.menus.form.service_type_both"
                      : value === "Takeaway"
                        ? "hq.menus.form.service_type_takeaway"
                        : "hq.menus.form.service_type_dinein";
                  return (
                    <button
                      key={value}
                      type="button"
                      aria-pressed={selected}
                      onClick={() => update("service_type", value)}
                      className={`rounded-md border px-3 py-2 text-xs font-medium transition-colors ${
                        selected
                          ? "border-primary bg-primary/10 text-primary"
                          : "border-input text-muted-foreground hover:bg-muted/50"
                      }`}
                    >
                      {t(labelKey)}
                    </button>
                  );
                })}
              </div>
              <p className="text-xs text-muted-foreground">
                {t("hq.menus.form.service_type_hint")}
              </p>
            </Field>
          </div>

          <DialogFooter className="border-t pt-3">
            <Button variant="outline" size="sm" onClick={requestClose} disabled={isPending}>
              {t("common.cancel")}
            </Button>
            <Button
              size="sm"
              onClick={handleSubmit}
              disabled={!Object.values(form.name).some((value) => value.trim()) || isPending}
            >
              {isPending && <Spinner className="mr-1.5 size-3.5" />}
              {isEdit ? t("common.save") : t("common.create")}
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
