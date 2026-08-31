"use client";

import { useMemo, useState } from "react";

import { Button, Input, Label, Spinner } from "@godxjp/ui";
import type { TranslatableValue } from "@godxjp/ui";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@godxjp/ui";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import { useCreateFloatingSection, useUpdateFloatingSection } from "@/hooks/api/use-floating-sections";
import type { FloatingSection } from "@/types/models/FloatingSection";
import { buildI18nPayload, DEFAULT_LOCALE, emptyLocaleMap } from "@/types/models/payload-helpers";

export interface FloatingSectionFormDialogProps {
  brandSlug: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** When set, dialog is in edit mode and pre-fills fields. */
  section?: FloatingSection | null;
}

interface FormState {
  name: Record<string, string>;
  start_date: string;
  end_date: string;
}

function emptyForm(section?: FloatingSection | null): FormState {
  const name = emptyLocaleMap();
  if (section) {
    for (const locale of ["ja", "en", "vi"] as const) {
      name[locale] = section.translations?.[locale]?.name ?? "";
    }
    // Fall back to the top-level default-locale mirror when no per-locale
    // translation row exists yet (rows created before this field went
    // translatable).
    if (!Object.values(name).some((value) => value.trim())) {
      name[DEFAULT_LOCALE] = section.name ?? "";
    }
  }
  return {
    name,
    start_date: section?.start_date ?? "",
    end_date: section?.end_date ?? "",
  };
}

// Top-level `name` mirror: prefer the default locale, else the first non-empty
// locale. Mirrors product-create-dialog.tsx's `effectiveName` — the single
// source of truth for both the submit-disabled guard and the payload mirror.
function effectiveNameOf(name: Record<string, string>): string {
  return (
    name[DEFAULT_LOCALE]?.trim() ||
    Object.values(name)
      .find((value) => value?.trim())
      ?.trim() ||
    ""
  );
}

// Mirrors menus/components/menu-form-dialog.tsx's create/edit dual-mode
// pattern — a floating section's name + optional date range are edited via
// this modal, never inline on the detail page (which only manages
// schedules/products).
export function FloatingSectionFormDialog({
  brandSlug,
  open,
  onOpenChange,
  section,
}: FloatingSectionFormDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent data-slot="floating-section-form-dialog" className="sm:max-w-md">
        {/* Only mounted while open, keyed by target — guarantees fresh form
            state on every open without a state-syncing effect. */}
        {open && (
          <FloatingSectionFormFields
            key={section?.id ?? "create"}
            brandSlug={brandSlug}
            section={section}
            onDone={() => onOpenChange(false)}
            onCancel={() => onOpenChange(false)}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

function FloatingSectionFormFields({
  brandSlug,
  section,
  onDone,
  onCancel,
}: {
  brandSlug: string;
  section?: FloatingSection | null;
  onDone: () => void;
  onCancel: () => void;
}) {
  const { t } = useTranslation();
  const isEdit = !!section;

  const [form, setForm] = useState<FormState>(() => emptyForm(section));
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const effectiveName = useMemo(() => effectiveNameOf(form.name), [form.name]);

  const createMutation = useCreateFloatingSection(brandSlug);
  const updateMutation = useUpdateFloatingSection(brandSlug);
  const isPending = createMutation.isPending || updateMutation.isPending;

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((prev) => ({ ...prev, [key]: value }));
    setFieldErrors((prev) => {
      // The name field's server errors are keyed per-locale (ja.name/en.name/
      // vi.name), so clear those too — not just prev[key].
      const errorKeys =
        key === "name" ? ["name", "ja.name", "en.name", "vi.name"] : [key as string];
      if (!errorKeys.some((k) => prev[k])) return prev;
      const next = { ...prev };
      for (const k of errorKeys) delete next[k];
      return next;
    });
  }

  async function handleSubmit() {
    // Follows the Product API standard (product-create-dialog.tsx), NOT the
    // Menu fill-fallback one. buildI18nPayload → { ja: {name}, en: {name},
    // vi: {name} }, then drop any locale whose name is empty (Rule 3):
    // floating_section_translations.name is NOT NULL and Laravel's
    // ConvertEmptyStringsToNull turns "" → null, so sending an empty name
    // would trip an integrity-constraint violation. The remaining locales are
    // the source of truth; the top-level `name` is only a mirror.
    const i18n = buildI18nPayload({ name: form.name });
    for (const locale of Object.keys(i18n) as Array<keyof typeof i18n>) {
      if (!i18n[locale]?.name?.trim()) delete i18n[locale];
    }
    const payload = {
      name: effectiveName,
      ...i18n,
      start_date: form.start_date.trim() || null,
      end_date: form.end_date.trim() || null,
    };

    try {
      if (isEdit && section) {
        await updateMutation.mutateAsync({ id: section.id, data: payload });
      } else {
        // Stay on the list — the row shows up via query invalidation.
        // Editing (schedule, products) happens by clicking into the new row.
        await createMutation.mutateAsync(payload);
      }
      onDone();
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        const body = e.body as { errors?: Record<string, string[]> };
        if (body.errors) setFieldErrors(body.errors);
      }
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>
          {isEdit ? t("hq.floating_sections.edit_title") : t("hq.floating_sections.create_title")}
        </DialogTitle>
        <DialogDescription>
          {isEdit ? t("hq.floating_sections.edit_desc") : t("hq.floating_sections.create_desc")}
        </DialogDescription>
      </DialogHeader>

      <div className="flex flex-col gap-4 py-2">
        <div className="flex flex-col gap-1.5">
          <Label className="text-xs font-medium text-muted-foreground">
            {t("common.name")}
            <span className="ml-0.5 text-destructive">*</span>
          </Label>
          <Input
            translatable
            autoFocus
            maxLength={255}
            placeholder={t("hq.floating_sections.name_placeholder")}
            value={form.name as TranslatableValue}
            onChange={(value) => update("name", value as Record<string, string>)}
          />
          {(fieldErrors["ja.name"]?.[0] ??
            fieldErrors["en.name"]?.[0] ??
            fieldErrors["vi.name"]?.[0] ??
            fieldErrors.name?.[0]) && (
            <p className="text-[11px] text-red-500">
              {fieldErrors["ja.name"]?.[0] ??
                fieldErrors["en.name"]?.[0] ??
                fieldErrors["vi.name"]?.[0] ??
                fieldErrors.name?.[0]}
            </p>
          )}
        </div>

        <div className="grid grid-cols-2 gap-3 border-t border-dashed pt-4">
          <div className="flex flex-col gap-1.5">
            <Label className="text-xs font-medium text-muted-foreground">
              {t("hq.menus.schedules.field_start_date")}
            </Label>
            <Input
              type="date"
              className="h-9"
              value={form.start_date}
              onChange={(e) => update("start_date", e.target.value)}
            />
          </div>
          <div className="flex flex-col gap-1.5">
            <Label className="text-xs font-medium text-muted-foreground">
              {t("hq.menus.schedules.field_end_date")}
            </Label>
            <Input
              type="date"
              className="h-9"
              value={form.end_date}
              onChange={(e) => update("end_date", e.target.value)}
            />
          </div>
        </div>
        <p className="-mt-2 text-xs text-muted-foreground">
          {t("hq.floating_sections.date_range_hint")}
        </p>
      </div>

      <DialogFooter>
        <Button variant="outline" size="sm" onClick={onCancel} disabled={isPending}>
          {t("common.cancel")}
        </Button>
        <Button
          size="sm"
          onClick={handleSubmit}
          disabled={!effectiveName || isPending}
        >
          {isPending && <Spinner className="mr-1.5 size-3.5" />}
          {isEdit ? t("common.save") : t("common.create")}
        </Button>
      </DialogFooter>
    </>
  );
}
