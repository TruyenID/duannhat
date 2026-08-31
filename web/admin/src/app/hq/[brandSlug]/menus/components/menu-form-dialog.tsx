"use client";

import { useEffect, useState } from "react";

import { Button } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import { Textarea } from "@godxjp/ui";
import type { TranslatableValue } from "@godxjp/ui";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@godxjp/ui";
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
import { ChevronDown } from "lucide-react";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import { useCreateMenu, useUpdateMenu } from "@/hooks/api/use-menus";
import { useShops } from "@/hooks/api/use-shops";
import type { Menu } from "@/services/menu-service";
import { buildI18nPayload, DEFAULT_LOCALE, emptyLocaleMap } from "@/types/models/payload-helpers";
import { fillLocalesFallback } from "@/lib/i18n-fill";

export interface MenuFormDialogProps {
  brandSlug: string;
  brandId?: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  menu?: Menu | null;
}

// #463 — which ordering flow the menu shows in. "Both" (default + every legacy
// menu) shows in takeaway + dine-in; "Takeaway"/"DineIn" restrict to one flow.
type ServiceType = "Takeaway" | "DineIn" | "Both";

interface FormState {
  branch_id: string;
  name: Record<string, string>;
  description: Record<string, string>;
  service_type: ServiceType;
  // Stored as "YYYY-MM-DD" for <input type="date">, or "" when unset.
  // Always initialized from the existing menu on edit open — never left as ""
  // when the menu has a date, to prevent accidental null-write on PUT.
  valid_from: string;
  valid_to: string;
}

function emptyForm(): FormState {
  return {
    branch_id: "",
    name: emptyLocaleMap(),
    description: emptyLocaleMap(),
    service_type: "Both",
    valid_from: "",
    valid_to: "",
  };
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

export function MenuFormDialog({ brandSlug, open, onOpenChange, menu }: MenuFormDialogProps) {
  const { t } = useTranslation();
  const isEdit = !!menu;
  const [form, setForm] = useState<FormState>(() => emptyForm());
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  // Auto-open the validity section when the menu already has date values.
  const [validityOpen, setValidityOpen] = useState(false);
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);
  // Tracks user edits — drives the unsaved-changes guard on close. Set only by
  // the `update` helper (all field inputs go through it); the hydrate effect
  // resets it so opening the dialog never counts as dirty.
  const [dirty, setDirty] = useState(false);

  const createMutation = useCreateMenu(brandSlug);
  const updateMutation = useUpdateMenu(brandSlug);
  const isPending = createMutation.isPending || updateMutation.isPending;

  // Branch list scoped to the current brand. Used as the source for the
  // required branch select — every branch menu MUST belong to a branch.
  const { data: shopsResponse, isLoading: shopsLoading } = useShops(brandSlug, {
    per_page: 100,
  });
  const branches = shopsResponse?.data ?? [];

  useEffect(() => {
    if (!open) return;
    if (menu) {
      // Slice to "YYYY-MM-DD" for <input type="date"> compatibility.
      // Slicing avoids timezone conversion issues that arise when constructing
      // a Date object from an ISO timestamp (e.g. "2026-01-28T00:00:00Z" in
      // UTC-7 would display as Jan 27 if parsed as a Date and re-formatted).
      const validFrom = menu.valid_from ? menu.valid_from.slice(0, 10) : "";
      const validTo = menu.valid_to ? menu.valid_to.slice(0, 10) : "";
      const name = emptyLocaleMap();
      const description = emptyLocaleMap();
      for (const locale of ["ja", "en", "vi"] as const) {
        name[locale] = menu.translations?.[locale]?.name ?? "";
        description[locale] = menu.translations?.[locale]?.description ?? "";
      }
      if (!Object.values(name).some((value) => value.trim())) {
        name[DEFAULT_LOCALE] = menu.name;
      }
      if (!Object.values(description).some((value) => value.trim())) {
        description[DEFAULT_LOCALE] = menu.description ?? "";
      }
      setForm({
        branch_id: menu.branch_id ?? "",
        name,
        description,
        service_type: (menu.service_type as ServiceType) ?? "Both",
        valid_from: validFrom,
        valid_to: validTo,
      });
      setValidityOpen(!!(menu.valid_from || menu.valid_to));
    } else {
      setForm(emptyForm());
      setValidityOpen(false);
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
    const i18n = buildI18nPayload({
      name: localizedNames,
      description: localizedDescriptions,
    });
    const effectiveName = localizedNames[DEFAULT_LOCALE];
    const effectiveDescription = hasDescription
      ? localizedDescriptions[DEFAULT_LOCALE]
      : null;
    const basePayload = {
      name: effectiveName,
      description: effectiveDescription,
      ...i18n,
      service_type: form.service_type,
    };

    try {
      if (isEdit && menu) {
        // Always include valid_from/valid_to in the update payload so the PUT
        // never sends absent fields (which would leave backend values untouched
        // in a PATCH-semantics scenario but could silently null them if the
        // backend treats absent nullable fields as null).
        // "" converts to null (user cleared the field intentionally).
        await updateMutation.mutateAsync({
          id: menu.id,
          data: {
            updated_at: menu.updated_at,
            ...basePayload,
            valid_from: form.valid_from || null,
            valid_to: form.valid_to || null,
          },
        });
      } else {
        // Create branch menu: branch_id required, is_master forced to false.
        // valid_from/valid_to are intentionally omitted — set via edit form.
        // Master menus go through the dedicated MasterMenuFormDialog.
        await createMutation.mutateAsync({
          ...basePayload,
          branch_id: form.branch_id,
          is_master: false,
        });
      }
      onOpenChange(false);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        const body = e.body as { errors?: Record<string, string[]> };
        if (body.errors) setFieldErrors(body.errors);
      }
    }
  }

  const canSubmit =
    Object.values(form.name).some((value) => value.trim()) &&
    (isEdit || !!form.branch_id) &&
    !isPending;

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
          data-slot="menu-form-dialog"
          className="flex max-h-[90vh] flex-col gap-0 sm:max-w-lg"
        >
          <DialogHeader>
            <DialogTitle>
              {isEdit ? t("hq.menus.form.edit_title") : t("hq.menus.form.create_title")}
            </DialogTitle>
            <DialogDescription>
              {isEdit ? t("hq.menus.form.edit_desc") : t("hq.menus.form.create_desc")}
            </DialogDescription>
          </DialogHeader>

          <div className="flex flex-1 flex-col gap-3 overflow-y-auto px-1 py-3 text-sm">
            {!isEdit && (
              <Field label={t("hq.menus.form.branch")} required error={fieldErrors.branch_id?.[0]}>
                <Select
                  value={form.branch_id || "__none__"}
                  onValueChange={(v) => update("branch_id", v === "__none__" ? "" : v)}
                  disabled={shopsLoading}
                >
                  <SelectTrigger className="h-9 w-full text-sm">
                    <SelectValue
                      placeholder={
                        shopsLoading
                          ? t("hq.menus.form.loading_branches")
                          : t("hq.menus.form.select_branch")
                      }
                    />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__none__">
                      {shopsLoading
                        ? t("hq.menus.form.loading_branches")
                        : t("hq.menus.form.select_branch")}
                    </SelectItem>
                    {branches.map((b) => (
                      <SelectItem key={b.id} value={b.id}>
                        {b.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
            )}

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
                autoFocus={isEdit}
                placeholder={t("hq.menus.form.name_placeholder")}
                value={form.name as TranslatableValue}
                onChange={(value) => update("name", value as Record<string, string>)}
                maxLength={255}
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
                rows={2}
                maxLength={1000}
                placeholder={t("hq.menus.form.desc_placeholder")}
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

            {isEdit && (
              <Collapsible open={validityOpen} onOpenChange={setValidityOpen}>
                <CollapsibleTrigger asChild>
                  <button
                    type="button"
                    className="flex w-full items-center justify-between rounded-md border border-dashed px-3 py-2 text-xs font-medium text-muted-foreground hover:bg-muted/50"
                  >
                    {t("menu.validity_period")}
                    <ChevronDown
                      className={`size-3.5 transition-transform ${validityOpen ? "rotate-180" : ""}`}
                    />
                  </button>
                </CollapsibleTrigger>
                <CollapsibleContent>
                  <div className="mt-2 flex flex-col gap-2 rounded-md border bg-muted/30 p-3">
                    <p className="text-xs text-muted-foreground">
                      {t("menu.validity_period_hint")}
                    </p>
                    <div className="grid grid-cols-2 gap-2">
                      <Field
                        label={t("hq.menus.form.valid_from")}
                        error={fieldErrors.valid_from?.[0]}
                      >
                        <input
                          type="date"
                          className="h-9 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                          value={form.valid_from}
                          onChange={(e) => update("valid_from", e.target.value)}
                          max={form.valid_to || undefined}
                        />
                      </Field>
                      <Field label={t("hq.menus.form.valid_to")} error={fieldErrors.valid_to?.[0]}>
                        <input
                          type="date"
                          className="h-9 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                          value={form.valid_to}
                          onChange={(e) => update("valid_to", e.target.value)}
                          min={form.valid_from || undefined}
                        />
                      </Field>
                    </div>
                  </div>
                </CollapsibleContent>
              </Collapsible>
            )}
          </div>

          <DialogFooter className="border-t pt-3">
            <Button variant="outline" size="sm" onClick={requestClose} disabled={isPending}>
              {t("common.cancel")}
            </Button>
            <Button size="sm" onClick={handleSubmit} disabled={!canSubmit}>
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
