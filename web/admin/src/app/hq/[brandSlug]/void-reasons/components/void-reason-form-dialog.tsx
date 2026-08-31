"use client";

/**
 * VoidReasonFormDialog — create/edit a brand void reason (plan-051 #1149).
 *
 * Follows the AllergenFormDialog pattern: translatable label via
 * `<Input translatable />`, unsaved-changes guard on every close path,
 * 422 field errors surfaced inline. `stock_effect` is the consequential
 * field — each option carries an explanation of what happens to stock that
 * was ALREADY deducted when a line is voided with this reason.
 */

import { useState } from "react";

import { Button, Input, Spinner, Switch } from "@godxjp/ui";
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
import { useCreateVoidReason, useUpdateVoidReason } from "@/hooks/api/use-void-reasons";
import type {
  CreateVoidReasonInput,
  UpdateVoidReasonInput,
  VoidReason,
  VoidStockEffect,
} from "@/services/void-reason-service";
import { VoidStockEffectValues } from "@/services/void-reason-service";
import { useTranslation } from "@/providers/app-provider";
import { buildI18nPayload, DEFAULT_LOCALE, emptyLocaleMap } from "@/types/models/payload-helpers";

export interface VoidReasonFormDialogProps {
  brandSlug: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Reason being edited; null/undefined means create mode. */
  voidReason?: VoidReason | null;
}

interface FormState {
  label: Record<string, string>;
  stock_effect: VoidStockEffect;
  requires_note: boolean;
  sort_order: string;
  is_active: boolean;
}

function emptyForm(): FormState {
  return {
    label: emptyLocaleMap(),
    stock_effect: "restock",
    requires_note: false,
    sort_order: "0",
    is_active: true,
  };
}

function hydrateForm(reason: VoidReason): FormState {
  const label = emptyLocaleMap();
  let touchedAny = false;
  if (reason.translations) {
    for (const locale of ["ja", "en", "vi"] as const) {
      const value = reason.translations[locale]?.label;
      if (value !== undefined && value !== null) {
        label[locale] = value;
        touchedAny = true;
      }
    }
  }
  if (!touchedAny) {
    label[DEFAULT_LOCALE] = reason.label ?? "";
  }
  return {
    label,
    stock_effect: reason.stock_effect,
    requires_note: reason.requires_note,
    sort_order: String(reason.sort_order ?? 0),
    is_active: reason.is_active,
  };
}

export function VoidReasonFormDialog({
  brandSlug,
  open,
  onOpenChange,
  voidReason,
}: VoidReasonFormDialogProps) {
  const { t } = useTranslation();
  const isEdit = !!voidReason;
  const [form, setForm] = useState<FormState>(emptyForm);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);
  const [dirty, setDirty] = useState(false);

  const createMutation = useCreateVoidReason(brandSlug);
  const updateMutation = useUpdateVoidReason(brandSlug);
  const isPending = createMutation.isPending || updateMutation.isPending;

  // Re-hydrate on every open (and when switching the edited row) via the
  // render-time "adjust state when props change" pattern — avoids a
  // setState-in-effect cascade.
  const hydrationKey = open ? `${voidReason?.id ?? "create"}` : null;
  const [prevHydrationKey, setPrevHydrationKey] = useState<string | null>(null);
  if (hydrationKey !== prevHydrationKey) {
    setPrevHydrationKey(hydrationKey);
    if (hydrationKey !== null) {
      setForm(voidReason ? hydrateForm(voidReason) : emptyForm());
      setDirty(false);
    }
    setFieldErrors({});
  }

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

    const i18n = buildI18nPayload({ label: form.label });
    for (const locale of Object.keys(i18n) as Array<keyof typeof i18n>) {
      if (!i18n[locale]?.label?.trim()) delete i18n[locale];
    }

    // Top-level mirror = first non-empty locale (ja → en → vi) — the backend
    // also derives it server-side, but sending it keeps legacy readers whole.
    const effectiveLabel =
      form.label[DEFAULT_LOCALE]?.trim() ||
      form.label["en"]?.trim() ||
      form.label["vi"]?.trim() ||
      "";

    const sortOrderNum = parseInt(form.sort_order, 10);

    const payload = {
      label: effectiveLabel,
      stock_effect: form.stock_effect,
      requires_note: form.requires_note,
      is_active: form.is_active,
      sort_order: Number.isFinite(sortOrderNum) && sortOrderNum >= 0 ? sortOrderNum : 0,
      ...i18n,
    };

    try {
      if (isEdit && voidReason) {
        await updateMutation.mutateAsync({
          id: voidReason.id,
          data: payload as UpdateVoidReasonInput,
        });
      } else {
        await createMutation.mutateAsync(payload as CreateVoidReasonInput);
      }
      onOpenChange(false);
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const body = err.body as { errors?: Record<string, string[]> };
        if (body.errors) setFieldErrors(body.errors);
      }
    }
  }

  return (
    <>
      <Dialog
        open={open}
        onOpenChange={(next) => {
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
              {isEdit
                ? t("hq.void_reasons.dialog.edit_title")
                : t("hq.void_reasons.dialog.create_title")}
            </DialogTitle>
            <DialogDescription>
              {isEdit
                ? t("hq.void_reasons.dialog.edit_desc")
                : t("hq.void_reasons.dialog.create_desc")}
            </DialogDescription>
          </DialogHeader>

          <form
            onSubmit={handleSubmit}
            className="flex flex-1 flex-col gap-3 overflow-y-auto px-1 py-3 text-sm"
          >
            <Field
              label={t("hq.void_reasons.field.label")}
              required
              error={fieldErrors["ja.label"]?.[0] ?? fieldErrors.label?.[0]}
            >
              <Input
                translatable
                value={form.label as TranslatableValue}
                onChange={(value) => update("label", value as Record<string, string>)}
                maxLength={100}
                placeholder={t("hq.void_reasons.field.label_placeholder")}
              />
            </Field>

            <Field
              label={t("hq.void_reasons.field.stock_effect")}
              required
              error={fieldErrors.stock_effect?.[0]}
            >
              <Select
                value={form.stock_effect}
                onValueChange={(v) => update("stock_effect", v as VoidStockEffect)}
              >
                <SelectTrigger className="h-8 text-xs">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {VoidStockEffectValues.map((effect) => (
                    <SelectItem key={effect} value={effect}>
                      {t(`hq.void_reasons.stock_effect.${effect}`)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {/* Explain the picked option — what happens to stock that was
                  ALREADY deducted when a line is voided with this reason. */}
              <p className="text-xs text-muted-foreground">
                {t(`hq.void_reasons.stock_effect.${form.stock_effect}_desc`)}
              </p>
            </Field>

            <Field
              label={t("hq.void_reasons.field.requires_note")}
              error={fieldErrors.requires_note?.[0]}
            >
              <div className="flex items-center gap-3">
                <Switch
                  id="void-reason-requires-note"
                  checked={form.requires_note}
                  onCheckedChange={(v) => update("requires_note", v === true)}
                />
                <label
                  htmlFor="void-reason-requires-note"
                  className="cursor-pointer text-xs text-muted-foreground"
                >
                  {t("hq.void_reasons.field.requires_note_hint")}
                </label>
              </div>
            </Field>

            <div className="grid grid-cols-2 gap-3">
              <Field
                label={t("hq.void_reasons.field.sort_order")}
                error={fieldErrors.sort_order?.[0]}
              >
                <Input
                  type="number"
                  min={0}
                  value={form.sort_order}
                  onChange={(e) => update("sort_order", e.target.value)}
                />
              </Field>

              <Field label={t("common.status")} error={fieldErrors.is_active?.[0]}>
                <div className="flex h-8 items-center gap-3">
                  <Switch
                    id="void-reason-is-active"
                    checked={form.is_active}
                    onCheckedChange={(v) => update("is_active", v === true)}
                  />
                  <label
                    htmlFor="void-reason-is-active"
                    className="cursor-pointer text-xs text-muted-foreground"
                  >
                    {form.is_active ? t("common.active") : t("common.inactive")}
                  </label>
                </div>
              </Field>
            </div>
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
