"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";

import { Button } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import { Textarea } from "@godxjp/ui";
import { Checkbox } from "@godxjp/ui";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@godxjp/ui";
import type { TranslatableValue } from "@godxjp/ui";
import { ApiError } from "@/lib/api";
import { toResourceSlug } from "@/lib/option-slug";
import { useCreateProduct } from "@/hooks/api/use-products";
import { useProductTypeLookup } from "@/hooks/api/use-product-types";
import { useCategoryLookup } from "@/hooks/api/use-categories";
import { Spinner } from "@godxjp/ui";
import type { CreateProductInput, ProductStatus } from "@/services/product-service";
import { DEFAULT_LOCALE } from "@/i18n";
import { buildI18nPayload, emptyLocaleMap } from "@/types/models/payload-helpers";
import { useTranslation } from "@/providers/app-provider";

interface ProductCreateDialogProps {
  brandSlug: string;
  /** Brand UUID — currently unused on create (backend resolves it via ResolveBrandFromSlug) but kept for symmetry with the category dialog. */
  brandId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

interface FormState {
  name: Record<string, string>;
  slug: string;
  product_type_id: string;
  description: Record<string, string>;
  status: ProductStatus;
  is_hidden: boolean;
  category_ids: string[];
}

function buildEmptyForm(): FormState {
  return {
    name: emptyLocaleMap(),
    slug: "",
    product_type_id: "",
    description: emptyLocaleMap(),
    status: "draft",
    is_hidden: false,
    category_ids: [],
  };
}

/**
 * Slugify a (possibly non-ASCII) product name. A fully Japanese name slugifies
 * to "" — see @/lib/option-slug for the `product-<hash>` fallback that keeps
 * the derived slug non-empty and readable-when-it-can-be.
 */
function slugify(raw: string): string {
  return toResourceSlug(raw, "product");
}

export function ProductCreateDialog({ brandSlug, open, onOpenChange }: ProductCreateDialogProps) {
  const router = useRouter();
  const { t } = useTranslation();
  const [form, setForm] = useState<FormState>(buildEmptyForm);
  const [slugTouched, setSlugTouched] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  // Resolve the "effective" name: prefer DEFAULT_LOCALE (ja), else the first
  // non-empty locale the user actually filled in. Avoids the silent-disable
  // trap when app UI locale differs from DEFAULT_LOCALE — mirrors the logic
  // in products/new/page.tsx.
  const effectiveName = useMemo(() => {
    const preferred = form.name[DEFAULT_LOCALE]?.trim();
    if (preferred) return preferred;
    return (
      Object.values(form.name)
        .find((v) => v?.trim())
        ?.trim() ?? ""
    );
  }, [form.name]);

  const create = useCreateProduct(brandSlug);

  // Typed hooks thread `locale` into the queryKey so a language switch
  // refetches these lookups with the new Accept-Language header.
  const productTypes = useProductTypeLookup(brandSlug);
  const categories = useCategoryLookup(brandSlug);

  // Reset on open/close.
  useEffect(() => {
    if (!open) {
      setForm(buildEmptyForm());
      setSlugTouched(false);
      setFieldErrors({});
    }
  }, [open]);

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

  function onNameChange(value: TranslatableValue) {
    update("name", value as Record<string, string>);
    if (!slugTouched) {
      // Slug derives from the default-locale value (schema: slug is NOT
      // translatable — 1 product = 1 slug). Fall back to any non-empty
      // locale so the slug still auto-populates if user types in en/vi first.
      const source =
        value[DEFAULT_LOCALE]?.trim() || Object.values(value).find((v) => v?.trim()) || "";
      update("slug", slugify(source));
    }
  }

  function toggleCategory(id: string) {
    setForm((prev) => {
      const has = prev.category_ids.includes(id);
      return {
        ...prev,
        category_ids: has ? prev.category_ids.filter((c) => c !== id) : [...prev.category_ids, id],
      };
    });
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFieldErrors({});

    // Build locale overrides, then drop any locale whose `name` is empty.
    // `product_translations.name` is NOT NULL and Laravel's
    // ConvertEmptyStringsToNull middleware turns "" → null, so sending an
    // empty name triggers an integrity constraint violation. Mirrors the
    // same guard in products/new/page.tsx.
    const i18nPayload = buildI18nPayload({
      name: form.name,
      description: form.description,
    });
    for (const locale of Object.keys(i18nPayload)) {
      if (!i18nPayload[locale as keyof typeof i18nPayload]?.name?.trim()) {
        delete i18nPayload[locale as keyof typeof i18nPayload];
      }
    }

    const effectiveDescription =
      form.description[DEFAULT_LOCALE]?.trim() ||
      Object.values(form.description)
        .find((v) => v?.trim())
        ?.trim() ||
      "";

    const payload: CreateProductInput = {
      name: form.name[DEFAULT_LOCALE] ?? "",
      product_type_id: form.product_type_id,
      description: effectiveDescription || undefined,
      status: form.status,
      is_hidden: form.is_hidden,
      category_ids: form.category_ids.length > 0 ? form.category_ids : undefined,
      // Locale overrides — sent under `ja`/`en`/`vi` keys per backend rules.
      ...i18nPayload,
    };

    try {
      const result = await create.mutateAsync(payload);
      const newId = result.data.id;
      onOpenChange(false);
      router.push(`/hq/${brandSlug}/products/${newId}`);
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const body = err.body as { errors?: Record<string, string[]> };
        if (body.errors) {
          setFieldErrors(body.errors);
        }
      }
      // Non-422 errors are surfaced via the hook's toast.
    }
  }

  const isPending = create.isPending;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[90vh] flex-col gap-0 sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{t("hq.products.quick_add")}</DialogTitle>
          <DialogDescription>{t("hq.products.quick_add_desc")}</DialogDescription>
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
              onChange={onNameChange}
              maxLength={255}
              autoFocus
            />
          </Field>

          <Field label="Slug" error={fieldErrors.slug?.[0]}>
            <Input
              value={form.slug}
              onChange={(e) => {
                update("slug", e.target.value);
                setSlugTouched(true);
              }}
              maxLength={255}
              placeholder="auto-generated from name"
            />
          </Field>

          <Field
            label={t("hq.products.sidebar.product_type")}
            required
            error={fieldErrors.product_type_id?.[0]}
          >
            <Select
              value={form.product_type_id || undefined}
              onValueChange={(v) => update("product_type_id", v)}
              disabled={productTypes.isLoading}
            >
              <SelectTrigger className="h-8 w-full text-sm">
                <SelectValue placeholder={t("hq.products.sidebar.select_type")} />
              </SelectTrigger>
              <SelectContent>
                {(productTypes.data?.data ?? []).map((pt) => (
                  <SelectItem key={pt.id} value={pt.id}>
                    {pt.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </Field>

          <Field
            label={t("hq.products.sidebar.menu_categories")}
            error={fieldErrors.category_ids?.[0]}
          >
            {categories.isLoading ? (
              <span className="text-xs text-muted-foreground">{t("common.loading")}</span>
            ) : (categories.data?.data ?? []).length === 0 ? (
              <span className="text-xs text-muted-foreground">
                {t("hq.products.sidebar.no_categories")}
              </span>
            ) : (
              <div className="max-h-32 overflow-y-auto rounded-md border p-2">
                {(categories.data?.data ?? []).map((c) => (
                  <label key={c.id} className="flex items-center gap-2 py-0.5 text-sm">
                    <Checkbox
                      checked={form.category_ids.includes(c.id)}
                      onCheckedChange={() => toggleCategory(c.id)}
                    />
                    {c.name}
                  </label>
                ))}
              </div>
            )}
          </Field>

          <Field label={t("common.description")} error={fieldErrors.description?.[0]}>
            <Textarea
              translatable
              value={form.description as TranslatableValue}
              onChange={(v) => update("description", v as Record<string, string>)}
              rows={3}
              maxLength={1000}
              className="field-sizing-fixed"
            />
          </Field>

          {/* Status is NOT selectable here. New products always start as
              `draft`; promotion to active/inactive only happens through the
              approval workflow (submit → approve → activate/deactivate).
              Offering active/inactive at create time invited an illegal
              draft→active jump the backend now rejects with 422
              INVALID_STATUS_TRANSITION. See godx-jp/godx-tempo#124. */}

          <label className="flex items-center gap-2 text-sm">
            <Checkbox
              checked={form.is_hidden}
              onCheckedChange={(v) => update("is_hidden", v === true)}
            />
            {t("hq.products.sidebar.hide_from_menu")}
          </label>
        </form>

        <DialogFooter className="border-t pt-3">
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => onOpenChange(false)}
            disabled={isPending}
          >
            {t("common.cancel")}
          </Button>
          <Button
            type="button"
            size="sm"
            onClick={handleSubmit}
            disabled={isPending || !effectiveName || !form.product_type_id}
          >
            {isPending && <Spinner className="mr-1.5 size-3.5" />}
            {t("common.create")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
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
