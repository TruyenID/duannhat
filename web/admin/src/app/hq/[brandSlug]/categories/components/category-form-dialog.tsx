"use client";

import { useEffect, useMemo, useState } from "react";

import { Button } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import { Checkbox } from "@godxjp/ui";
import { Textarea } from "@godxjp/ui";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";
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
import { Spinner } from "@godxjp/ui";
import {
  useCategoryLookup,
  useCreateCategory,
  useUpdateCategory,
} from "@/hooks/api/use-categories";
import type { TranslatableValue } from "@godxjp/ui";
import type {
  Category,
  CreateCategoryInput,
  UpdateCategoryInput,
} from "@/services/category-service";
import { buildI18nPayload, DEFAULT_LOCALE, emptyLocaleMap } from "@/types/models/payload-helpers";
import { fillLocalesFallback } from "@/lib/i18n-fill";
import { toResourceSlug } from "@/lib/option-slug";
import { useTranslation } from "@/providers/app-provider";

// A fully Japanese category name (`飲み物`, `前菜`) slugifies to "" — see
// @/lib/option-slug for the `category-<hash>` fallback.
function slugify(raw: string): string {
  return toResourceSlug(raw, "category");
}

interface CategoryFormDialogProps {
  brandSlug: string;
  brandId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Category being edited; null/undefined means create mode */
  category?: Category | null;
  /** All loaded categories — used to compute descendant ids when editing */
  allCategories: Category[];
  /** Pre-fill parent_id and lock it (used from detail page "Add child" button) */
  initialParentId?: string;
}

interface FormState {
  name: Record<string, string>;
  sku: string;
  slug: string;
  description: Record<string, string>;
  image_url: string;
  parent_id: string;
  is_active: boolean;
}

function emptyForm(parentId = ""): FormState {
  return {
    name: emptyLocaleMap(),
    sku: "",
    slug: "",
    description: emptyLocaleMap(),
    image_url: "",
    parent_id: parentId,
    is_active: true,
  };
}

function collectDescendantIds(rootId: string, all: Category[]): Set<string> {
  const descendants = new Set<string>([rootId]);
  let added = true;
  while (added) {
    added = false;
    for (const c of all) {
      if (c.parent_id && descendants.has(c.parent_id) && !descendants.has(c.id)) {
        descendants.add(c.id);
        added = true;
      }
    }
  }
  return descendants;
}

export function CategoryFormDialog({
  brandSlug,
  brandId,
  open,
  onOpenChange,
  category,
  allCategories,
  initialParentId,
}: CategoryFormDialogProps) {
  const { t } = useTranslation();
  const isEdit = !!category;
  const [form, setForm] = useState<FormState>(() => emptyForm());
  const [initialForm, setInitialForm] = useState<FormState>(() => emptyForm());
  const [slugTouched, setSlugTouched] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [confirmDiscard, setConfirmDiscard] = useState(false);
  const lookup = useCategoryLookup(brandSlug);
  const createMutation = useCreateCategory(brandSlug);
  const updateMutation = useUpdateCategory(brandSlug);
  const isPending = createMutation.isPending || updateMutation.isPending;

  // Reset form when dialog opens or category changes.
  useEffect(() => {
    if (!open) {
      setFieldErrors({});
      setConfirmDiscard(false);
      return;
    }
    let next: FormState;
    if (category) {
      const name = emptyLocaleMap();
      const description = emptyLocaleMap();
      const t = category.translations;
      if (t) {
        for (const locale of ["ja", "en", "vi"] as const) {
          name[locale] = t[locale]?.name ?? "";
          description[locale] = t[locale]?.description ?? "";
        }
      } else {
        name[DEFAULT_LOCALE] = category.name ?? "";
        description[DEFAULT_LOCALE] = category.description ?? "";
      }
      next = {
        name,
        sku: category.sku ?? "",
        slug: category.slug ?? "",
        description,
        image_url: category.image_url ?? "",
        parent_id: category.parent_id ?? "",
        is_active: category.is_active,
      };
    } else {
      next = emptyForm(initialParentId);
      setSlugTouched(false);
    }
    setForm(next);
    setInitialForm(next);
    setFieldErrors({});
  }, [open, category]);

  const excludedParentIds = useMemo(() => {
    if (!isEdit || !category) {
      return new Set<string>();
    }
    return collectDescendantIds(category.id, allCategories);
  }, [isEdit, category, allCategories]);

  const parentOptions = useMemo(() => {
    const items = lookup.data?.data ?? [];
    return items.filter((item) => !excludedParentIds.has(item.id));
  }, [lookup.data, excludedParentIds]);

  function onNameChange(value: TranslatableValue) {
    update("name", value);
    if (!slugTouched && !isEdit) {
      const source =
        value[DEFAULT_LOCALE]?.trim() || Object.values(value).find((v) => v?.trim()) || "";
      update("slug", slugify(source));
    }
  }

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

    const effectiveName =
      form.name[DEFAULT_LOCALE]?.trim() || form.name["en"]?.trim() || form.name["vi"]?.trim() || "";
    const effectiveDescription =
      form.description[DEFAULT_LOCALE]?.trim() ||
      Object.values(form.description).find((v) => v?.trim()) ||
      "";

    const i18n = buildI18nPayload({
      name: fillLocalesFallback(form.name),
      description: effectiveDescription ? fillLocalesFallback(form.description) : form.description,
    });
    for (const locale of Object.keys(i18n) as Array<keyof typeof i18n>) {
      if (!i18n[locale]?.name?.trim()) delete i18n[locale];
    }

    const payload = {
      name: effectiveName,
      description: effectiveDescription || undefined,
      ...i18n,
      sku: form.sku.trim() || undefined,
      slug: form.slug.trim() || undefined,
      image_url: form.image_url.trim() || undefined,
      parent_id: form.parent_id || null,
      is_active: form.is_active,
    };

    try {
      if (isEdit && category) {
        await updateMutation.mutateAsync({
          id: category.id,
          data: payload as unknown as UpdateCategoryInput,
        });
      } else {
        await createMutation.mutateAsync({
          ...(payload as unknown as CreateCategoryInput),
          brand_id: brandId,
        });
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
                ? t("hq.categories.form.edit_title")
                : initialParentId
                  ? t("hq.categories.form.title_create_child")
                  : t("hq.categories.form.new_title")}
            </DialogTitle>
            <DialogDescription>
              {isEdit ? t("hq.categories.form.edit_desc") : t("hq.categories.form.new_desc")}
            </DialogDescription>
          </DialogHeader>

          <form
            onSubmit={handleSubmit}
            className="flex flex-1 flex-col gap-3 overflow-y-auto px-1 py-3 text-sm"
          >
            <Field
              label={t("common.name")}
              required
              error={fieldErrors["ja.name"]?.[0] ?? fieldErrors.name?.[0]}
            >
              <Input
                translatable
                value={form.name as TranslatableValue}
                onChange={(val) => onNameChange(val as TranslatableValue)}
                maxLength={100}
                autoFocus
              />
            </Field>

            <Field label={t("product.sku")} error={fieldErrors.sku?.[0]}>
              <Input
                value={form.sku}
                onChange={(e) => update("sku", e.target.value)}
                maxLength={50}
                placeholder={t("hq.categories.form.sku_placeholder")}
              />
            </Field>

            <Field label={t("common.slug")} error={fieldErrors.slug?.[0]}>
              <Input
                value={form.slug}
                onChange={(e) => {
                  setSlugTouched(true);
                  update("slug", e.target.value);
                }}
                maxLength={100}
                placeholder={t("hq.categories.form.slug_placeholder")}
              />
            </Field>

            <Field label={t("common.parent")} error={fieldErrors.parent_id?.[0]}>
              {initialParentId && !isEdit ? (
                <div className="flex h-8 items-center rounded-md border border-input bg-muted px-2 text-sm text-muted-foreground">
                  {parentOptions.find((o) => o.id === initialParentId)?.name ?? initialParentId}
                </div>
              ) : (
                <Select
                  value={form.parent_id || "__none__"}
                  onValueChange={(v) => update("parent_id", v === "__none__" ? "" : v)}
                  disabled={lookup.isLoading}
                >
                  <SelectTrigger className="h-9 w-full text-sm">
                    <SelectValue placeholder={t("hq.categories.form.parent_none")} />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__none__">{t("hq.categories.form.parent_none")}</SelectItem>
                    {parentOptions.map((opt) => (
                      <SelectItem key={opt.id} value={opt.id}>
                        {opt.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </Field>

            <Field label={t("common.description")} error={fieldErrors.description?.[0]}>
              <Textarea
                translatable
                value={form.description as TranslatableValue}
                onChange={(val) => update("description", val as Record<string, string>)}
                rows={3}
                maxLength={1000}
                className="field-sizing-fixed"
              />
            </Field>

            <Field label={t("common.image_url")} error={fieldErrors.image_url?.[0]}>
              <Input
                value={form.image_url}
                onChange={(e) => update("image_url", e.target.value)}
                maxLength={500}
                placeholder="https://..."
                type="url"
              />
            </Field>

            <label className="flex items-center gap-2 text-sm">
              <Checkbox
                checked={form.is_active}
                onCheckedChange={(v) => update("is_active", v === true)}
              />
              {t("common.active_label")}
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
