"use client";

/**
 * HQ — SKU (variant) edit page.
 *
 * Route: /hq/[brandSlug]/products/[id]/skus/[skuId]
 *
 * Layout mirrors HQ menu-detail (/hq/[brand]/menus/[menuId]/items):
 *   ┌───────────────────── PageHeader ─────────────────────────┐
 *   │ [←] SKU name                           [Delete] [Save]   │
 *   ├──────────┬───────────────────────────────────────────────┤
 *   │ Sidebar  │ Right pane                                    │
 *   │ w-[320px]│  Card "Các thuộc tính"                         │
 *   │  variant │   [Kiểu dáng] [Kích thước] [Màu sắc]   [img]   │
 *   │   list   │  Card "Chi tiết biến thể"                      │
 *   │          │   [Giá bán]                                   │
 *   └──────────┴───────────────────────────────────────────────┘
 *
 * Selling_price = cost_price is fixed in BE update payload for now — material
 * & recipe feature is not yet executed, so the FE only exposes the single
 * price field. Both properties are sent identical on save.
 *
 * Gallery upload uses the shared file module:
 *   - Picker opens a dialog with the current draft image list.
 *   - "Thêm hình ảnh" → POST /files/upload (temp, 12h expiry).
 *   - "Xác nhận" → POST /skus/{sku}/images/sync (attach permanent + revert removed to temp).
 *   - "Hủy" or close → dialog discards local edits; temp files expire via cron.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import Image from "next/image";
import Link from "next/link";
import { useParams, useRouter, useSearchParams } from "next/navigation";
import { toast } from "sonner";
import { ArrowLeft, HelpCircle, ImageIcon, Layers, Plus, Save, Trash2 } from "lucide-react";

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
  CardContent,
  CardHeader,
  CardTitle,
  Combobox,
  type ComboboxOption,
  Input,
  Popover,
  PopoverContent,
  PopoverTrigger,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
} from "@godxjp/ui";

import { SkuGalleryDialog } from "../../components/sku-gallery-dialog";

import { ApiError } from "@/lib/api";
import { resolveOptionName, resolveOptionValueLabel } from "@/lib/option-i18n";
import { toOptionSlug } from "@/lib/option-slug";
import { useProduct } from "@/hooks/api/use-products";
import {
  useCreateProductSku,
  useDeleteProductSku,
  useProductSku,
  useSyncSkuImages,
  useUpdateProductSku,
} from "@/hooks/api/use-product-skus";
import { useRecipeLookup } from "@/hooks/api/use-recipes";
import { productOptionValueService } from "@/services/product-option-value-service";
import type { ProductImageFile } from "@/services/product-image-service";
import type { ProductSku } from "@/services/product-sku-service";
import { useTranslation } from "@/providers/app-provider";
import { currencySymbol, currencySymbolPosition } from "@/lib/currency";

// =============================================================================
//  Helpers
// =============================================================================

// This slug becomes an option value `value`, whose backend rule is
// `^[a-z0-9_]+$` — underscore-joined, not the hyphen shape used by product /
// shop / category slugs. A fully Japanese label slugifies to "", so this goes
// through the same `value_<hash>` fallback as options-builder (#2097).
function slugify(raw: string): string {
  return toOptionSlug(raw, "value");
}

function skuCombo(sku: ProductSku): string[] {
  return [sku.option_value1, sku.option_value2, sku.option_value3]
    .map((v) => (v ? resolveOptionValueLabel(v) : null))
    .filter((label): label is string => Boolean(label));
}

function skuLabel(sku: ProductSku, fallback: string): string {
  const combo = skuCombo(sku);
  if (combo.length > 0) return combo.join(" / ");
  return sku.name || fallback;
}

// =============================================================================
//  Sidebar — list of variants
// =============================================================================

interface SidebarItemProps {
  sku: ProductSku;
  active: boolean;
  href: string;
  label: string;
  /** Called with the target href when clicked. Parent can call
   *  `e.preventDefault()` to block navigation and handle it manually
   *  (e.g. unsaved-changes guard). */
  onClick?: (e: React.MouseEvent<HTMLAnchorElement>, href: string) => void;
}

function SidebarItem({ sku, active, href, label, onClick }: SidebarItemProps) {
  // Card style copied from hq/[brand]/menus/[menuId]/items DraggableProductCard,
  // with an additional "active" variant (solid primary fill) since SKUs are
  // selected via routing instead of dragged.
  return (
    <Link
      href={href}
      onClick={(e) => onClick?.(e, href)}
      data-slot="sku-sidebar-item"
      className={`flex items-center gap-2.5 rounded-md border p-2 text-sm transition-all select-none ${
        active
          ? "border-primary bg-primary text-primary-foreground shadow-sm"
          : "border-border bg-card text-foreground hover:border-primary/40 hover:shadow-sm"
      }`}
    >
      <div
        className={`flex size-8 shrink-0 items-center justify-center overflow-hidden rounded ${
          active ? "bg-primary-foreground/15" : "bg-muted"
        }`}
      >
        {sku.image_url ? (
          <Image
            src={sku.image_url}
            alt={label}
            width={32}
            height={32}
            className="h-full w-full object-cover"
          />
        ) : (
          <ImageIcon
            className={`size-3.5 ${active ? "text-primary-foreground/80" : "text-muted-foreground"}`}
          />
        )}
      </div>
      <span className="min-w-0 flex-1 truncate font-medium">{label}</span>
    </Link>
  );
}

// =============================================================================
//  Page
// =============================================================================

export default function SkuEditPage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const params = useParams<{ brandSlug: string; id: string; skuId: string }>();
  const brandSlug = params.brandSlug;
  const productId = params.id;
  const skuId = params.skuId;
  const { t, locale } = useTranslation();
  // ¥ sits before the number, ₫ after it — the adornment has to move with it.
  const symbolFirst = currencySymbolPosition(locale) === "prefix";

  // Product owns the sidebar variant list + option-name labels.
  const productQuery = useProduct(brandSlug, productId);
  const product = productQuery.data?.data;
  const options = product?.options ?? [];
  const siblings = useMemo(() => product?.skus ?? [], [product]);

  const skuQuery = useProductSku(brandSlug, skuId);
  const sku = skuQuery.data?.data;

  const update = useUpdateProductSku(brandSlug, productId);
  const deleteOne = useDeleteProductSku(brandSlug, productId);
  const syncImages = useSyncSkuImages(brandSlug, productId);
  const createSku = useCreateProductSku(brandSlug, productId);

  // Local editable state — selling_price, cost_price are kept in sync in the
  // PUT payload per user spec (material/recipe not yet implemented so
  // "giá bán = giá vốn" for now). `skuCode` maps to ProductSku.sku (max:50,
  // nullable) — BE ConvertEmptyStringsToNull middleware turns "" into NULL.
  const [price, setPrice] = useState<string>("");
  const [skuCode, setSkuCode] = useState<string>("");
  const [inventoryMode, setInventoryMode] = useState<"made_to_order" | "track_stock">(
    "made_to_order"
  );
  // Recipe drives multiple downstream flows — order-close material
  // deduction (track_stock), ProductionOrder auto-derive items, allergen
  // rollup, and the kitchen BOM. Visible at every inventory_mode so the
  // operator can link the recipe up front.
  const [recipeId, setRecipeId] = useState<string>("");
  const [images, setImages] = useState<ProductImageFile[]>([]);
  const [hydrated, setHydrated] = useState(false);

  const recipeLookupQuery = useRecipeLookup(brandSlug);
  const recipeOptions = useMemo<ComboboxOption[]>(() => {
    const list = recipeLookupQuery.data?.data ?? [];
    return list.map((r) => ({
      value: `${r.id}|${r.name}${r.sku ? ` (${r.sku})` : ""}`,
      label: `${r.name}${r.sku ? ` (${r.sku})` : ""}`,
    }));
  }, [recipeLookupQuery.data]);
  const recipeComboboxValue = useMemo(() => {
    if (!recipeId) return "";
    return recipeOptions.find((o) => o.value.startsWith(`${recipeId}|`))?.value ?? "";
  }, [recipeId, recipeOptions]);

  const [deleteOpen, setDeleteOpen] = useState(false);
  const [skuInMenus, setSkuInMenus] = useState<Array<{ id: string; name: string }> | null>(null);
  const [galleryOpen, setGalleryOpen] = useState(false);
  const [showCreate, setShowCreate] = useState(false);
  const [createText, setCreateText] = useState<Record<string, string>>({});
  const [createSkuCode, setCreateSkuCode] = useState("");
  const [createPrice, setCreatePrice] = useState("");
  const [createImages, setCreateImages] = useState<ProductImageFile[]>([]);
  const [createGalleryOpen, setCreateGalleryOpen] = useState(false);
  // Unsaved-changes guard: `pendingHref` holds the route we were trying to
  // navigate to so the dialog's "save and exit" / "exit without saving" can
  // route there after the user confirms. `null` = the dialog is closed.
  const [pendingHref, setPendingHref] = useState<string | null>(null);

  // Hydrate once per SKU load — reset when navigating between variants.
  useEffect(() => {
    if (!sku) return;
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setPrice(sku.selling_price ?? "");
    setSkuCode(sku.sku ?? "");
    setInventoryMode(
      (sku.inventory_mode === "track_stock" ? "track_stock" : "made_to_order") as
        | "made_to_order"
        | "track_stock"
    );
    setRecipeId(sku.recipe_id ?? "");
    setImages((sku.gallery ?? []).slice().sort((a, b) => a.sort_order - b.sort_order));
    setHydrated(true);
  }, [sku]);

  // Reset hydration marker when the route sku id changes so the effect above
  // reliably re-seeds the form for the new variant.
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setHydrated(false);
  }, [skuId]);

  const productFallback = product?.name ?? "";
  const headerTitle = showCreate
    ? t("hq.products.sku_edit.create.title")
    : sku
      ? skuLabel(sku, productFallback)
      : t("hq.products.sku_edit.title");
  // Simple product = product without configured options. The default SKU
  // inherits its image from `product.gallery`, so the per-SKU gallery picker
  // is hidden — uploading at both levels would be redundant.
  const hasVariantImages = options.length > 0;

  const priceNumber = Number(price);
  const priceInvalid = price.trim() === "" || Number.isNaN(priceNumber) || priceNumber < 0;
  // Max 50 chars per BE rule `sku.max:50`. Empty string will be persisted as
  // NULL (Laravel ConvertEmptyStringsToNull).
  const skuCodeInvalid = skuCode.length > 50;

  const hasChanges = useMemo(() => {
    if (!hydrated || !sku) return false;
    const currentMode = sku.inventory_mode === "track_stock" ? "track_stock" : "made_to_order";
    return (
      price !== (sku.selling_price ?? "") ||
      skuCode !== (sku.sku ?? "") ||
      inventoryMode !== currentMode ||
      recipeId !== (sku.recipe_id ?? "")
    );
  }, [hydrated, sku, price, skuCode, inventoryMode, recipeId]);

  const canSubmit = hydrated && hasChanges && !priceInvalid && !skuCodeInvalid && !update.isPending;

  const sortedOptions = useMemo(
    () => [...options].sort((a, b) => a.position - b.position),
    [options]
  );

  // Auto-open create form when navigated with ?new=1
  const autoOpenDone = useRef(false);
  useEffect(() => {
    if (autoOpenDone.current || !product || !searchParams.get("new")) return;
    autoOpenDone.current = true;
    setCreateText(Object.fromEntries(sortedOptions.map((o) => [o.id, ""])));
    setCreateSkuCode("");
    setCreatePrice("");
    setCreateImages([]);
    setShowCreate(true);
    const url = new URL(window.location.href);
    url.searchParams.delete("new");
    window.history.replaceState({}, "", url.toString());
  }, [product, searchParams, sortedOptions]);

  const createPriceNum = createPrice === "" ? 0 : Number(createPrice);
  const createPriceInvalid =
    createPrice !== "" && (Number.isNaN(createPriceNum) || createPriceNum < 0);
  const createSkuCodeInvalid = createSkuCode.length > 50;

  const allOptionsFilled = sortedOptions.every((o) => (createText[o.id] ?? "").trim().length > 0);

  const canCreate =
    !createPriceInvalid &&
    !createSkuCodeInvalid &&
    !createSku.isPending &&
    !syncImages.isPending &&
    (sortedOptions.length === 0 || allOptionsFilled);

  async function handleSave(): Promise<boolean> {
    if (!sku) return false;
    try {
      // The operator edits the SELLING price. cost_price stays 0 / auto-computed:
      // with is_cost_override=false the backend forces cost_price=cost_price_auto
      // (ProductSkuService::update), so the future recipe/material cost flows in
      // automatically (issue #875). Empty sku code → null (BE
      // ConvertEmptyStringsToNull also handles it, but being explicit avoids
      // sending whitespace-only strings).
      await update.mutateAsync({
        skuId: sku.id,
        data: {
          sku: skuCode.trim() || null,
          selling_price: price,
          cost_price: 0,
          is_cost_override: false,
          inventory_mode: inventoryMode,
          // Recipe link is independent of inventory_mode — the kitchen
          // needs the BOM regardless of whether the SKU tracks stock.
          // Plan-024 follow-up: removed the track_stock gate so the link
          // survives a mode switch.
          recipe_id: recipeId || null,
        },
      });
      return true;
    } catch {
      /* toast fired by hook */
      return false;
    }
  }

  function openCreate() {
    setCreateText(Object.fromEntries(sortedOptions.map((o) => [o.id, ""])));
    setCreateSkuCode("");
    setCreatePrice("");
    setCreateImages([]);
    setShowCreate(true);
  }

  async function handleCreate() {
    if (!canCreate) return;
    const payload: Parameters<typeof createSku.mutateAsync>[0] = {};

    // Resolve each option field: reuse existing option value if label matches,
    // otherwise create a new option value on the fly and use its id.
    for (const option of sortedOptions) {
      const text = (createText[option.id] ?? "").trim();
      const match = (option.values ?? []).find(
        (v) => resolveOptionValueLabel(v).toLowerCase() === text.toLowerCase()
      );

      let valueId: string;
      if (match) {
        valueId = match.id;
      } else {
        const slug = slugify(text);
        try {
          const created = await productOptionValueService.create(brandSlug, option.id, {
            value: slug,
            label: text,
          });
          valueId = created.data.id;
        } catch (e) {
          // Prefer the first field-level error (e.g. "This slug already exists
          // for the selected option.") over the generic top-level message
          // ("The given data was invalid.") that Laravel always returns.
          const body = (e as { body?: Record<string, unknown> })?.body;
          const fieldError = (body?.errors as Record<string, string[]>)?.value?.[0];
          toast.error(
            fieldError ?? (e instanceof Error ? e.message : t("toast.product.sku_create_failed"))
          );
          return;
        }
      }

      (payload as Record<string, string>)[`option_value${option.position}_id`] = valueId;
    }

    if (createSkuCode.trim()) payload.sku = createSkuCode.trim();
    const price = createPrice === "" ? 0 : createPriceNum;
    // Standalone create() does NOT run the cost-override mirror, so set
    // cost_price explicitly to 0 (auto-computed later) with is_cost_override=false
    // (issue #875).
    payload.selling_price = price;
    payload.cost_price = 0;
    payload.is_cost_override = false;

    try {
      const result = await createSku.mutateAsync(payload);
      if (createImages.length > 0) {
        await syncImages.mutateAsync({
          skuId: result.data.id,
          fileIds: createImages.map((img) => img.id),
        });
      }
      setShowCreate(false);
      router.push(`/hq/${brandSlug}/products/${productId}/skus/${result.data.id}`);
    } catch {
      /* toast fired by hook */
    }
  }

  // Route away safely: if there are unsaved edits, pop the confirm dialog so
  // the user decides. Otherwise navigate immediately.
  const requestNavigate = useCallback(
    (href: string) => {
      if (hasChanges) {
        setPendingHref(href);
        return;
      }
      router.push(href);
    },
    [hasChanges, router]
  );

  // Browser-level guard — fires on tab close / hard navigation / refresh.
  useEffect(() => {
    if (!hasChanges) return;
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = "";
    };
    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [hasChanges]);

  async function handleDelete() {
    if (!sku) return;
    try {
      await deleteOne.mutateAsync(sku.id);
      setDeleteOpen(false);
      // Stay on the SKU workspace: jump to the next remaining sibling so the
      // user can keep editing. Only fall back to the product detail page when
      // the product has no SKUs left.
      const nextSibling = siblings.find((item) => item.id !== sku.id);
      if (nextSibling) {
        router.push(`/hq/${brandSlug}/products/${productId}/skus/${nextSibling.id}`);
      } else {
        router.push(`/hq/${brandSlug}/products/${productId}`);
      }
    } catch (err) {
      if (err instanceof ApiError && err.status === 409 && err.body.error === "SKU_IN_MENU") {
        setDeleteOpen(false);
        setSkuInMenus(err.body.blocking_menus as Array<{ id: string; name: string }>);
        return;
      }
      /* other errors toasted by hook */
    }
  }

  async function handleGalleryConfirm(next: ProductImageFile[]) {
    if (!sku) return;
    try {
      const result = await syncImages.mutateAsync({
        skuId: sku.id,
        fileIds: next.map((img) => img.id),
      });
      setImages(result.data.slice().sort((a, b) => a.sort_order - b.sort_order));
    } catch {
      /* toast fired by hook */
    }
  }

  // ---------------------------------------------------------------------------
  //  Loading / error
  // ---------------------------------------------------------------------------

  if (productQuery.isLoading || skuQuery.isLoading || !sku || !product || !hydrated) {
    return (
      <div className="flex h-full flex-col">
        <div className="sticky top-0 z-30 flex h-12 shrink-0 items-center justify-between border-b bg-background px-4">
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => router.push(`/hq/${brandSlug}/products/${productId}`)}
              className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
              aria-label={t("common.back")}
            >
              <ArrowLeft className="size-4" />
            </button>
            <h1 className="text-lg font-semibold">{t("hq.products.sku_edit.title")}</h1>
          </div>
        </div>
        <div className="flex flex-1 items-center justify-center text-sm text-muted-foreground">
          <Spinner className="mr-2 size-4" />
          {t("common.loading")}
        </div>
      </div>
    );
  }

  if (skuQuery.isError) {
    return (
      <div className="flex h-full flex-col">
        <div className="sticky top-0 z-30 flex h-12 shrink-0 items-center justify-between border-b bg-background px-4">
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => router.push(`/hq/${brandSlug}/products/${productId}`)}
              className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
              aria-label={t("common.back")}
            >
              <ArrowLeft className="size-4" />
            </button>
            <h1 className="text-lg font-semibold">{t("hq.products.sku_edit.title")}</h1>
          </div>
        </div>
        <div className="flex flex-1 items-center justify-center p-6">
          <div className="rounded-md border border-destructive/50 bg-destructive/5 p-4 text-sm text-destructive">
            {t("common.load_error")}
          </div>
        </div>
      </div>
    );
  }

  const disabledAll = update.isPending || deleteOne.isPending || syncImages.isPending;

  // ---------------------------------------------------------------------------
  //  Render
  // ---------------------------------------------------------------------------

  return (
    <div className="flex h-full flex-col" data-slot="sku-edit-page">
      {/* Header — back + title only. Save/Delete actions live at the bottom
          of the right pane so the primary workflow stays anchored near the
          form fields instead of the top bar. */}
      <div className="sticky top-0 z-30 flex h-12 shrink-0 items-center justify-between border-b bg-background px-4">
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => {
              // Back arrow always navigates to the parent product detail page.
              // Closing the create form is the "Hủy" button's job — overloading
              // back to do both confused users (it looked like back was broken
              // because the URL didn't change after a click).
              if (showCreate) setShowCreate(false);
              requestNavigate(`/hq/${brandSlug}/products/${productId}`);
            }}
            className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
            aria-label={t("common.back")}
          >
            <ArrowLeft className="size-4" />
          </button>
          <h1 className="text-lg font-semibold">{headerTitle}</h1>
        </div>
        {options.length > 0 && !showCreate && (
          <Button
            type="button"
            size="sm"
            variant="outline"
            className="gap-1.5"
            onClick={openCreate}
          >
            <Plus className="size-3.5" />
            {t("hq.products.sku_edit.sidebar.new_sku")}
          </Button>
        )}
      </div>

      {/* Body */}
      <div className="flex min-h-0 flex-1">
        {/* Left: variant list — matches /hq/[brand]/menus/[menuId]/items layout */}
        <div className="flex w-[320px] shrink-0 flex-col border-r bg-background">
          <div className="space-y-2 border-b p-3">
            <div className="flex items-center gap-1.5 text-sm font-medium text-foreground">
              <Layers className="size-3.5 text-primary" />
              {t("hq.products.sku_edit.sidebar.title")}
              <span className="ml-auto text-xs text-muted-foreground">{siblings.length}</span>
            </div>
          </div>
          <div className="flex-1 space-y-1.5 overflow-y-auto p-2">
            {siblings.map((item) => (
              <SidebarItem
                key={item.id}
                sku={item}
                label={skuLabel(item, productFallback)}
                href={`/hq/${brandSlug}/products/${productId}/skus/${item.id}`}
                active={!showCreate && item.id === sku.id}
                onClick={(e, href) => {
                  if (!showCreate && item.id === sku.id) return;
                  if (hasChanges) {
                    e.preventDefault();
                    setPendingHref(href);
                    return;
                  }
                  if (showCreate) {
                    e.preventDefault();
                    setShowCreate(false);
                    router.push(href);
                  }
                }}
              />
            ))}
            {siblings.length === 0 && (
              <div className="py-8 text-center text-xs text-muted-foreground">
                {t("hq.products.sku_edit.sidebar.empty")}
              </div>
            )}
          </div>
        </div>

        {/* Right: detail panes */}
        <div className="min-w-0 flex-1 space-y-4 overflow-y-auto p-6">
          {/* ── Create new SKU form ── */}
          {showCreate && (
            <>
              <Card data-slot="sku-create-attributes-card">
                <CardHeader className="px-5 pt-4 pb-0">
                  <CardTitle className="text-sm font-semibold">
                    {t("hq.products.sku_edit.attributes.title")}
                  </CardTitle>
                </CardHeader>
                <CardContent className="px-5 pt-3 pb-5">
                  <div
                    className={
                      hasVariantImages
                        ? "grid gap-4 md:grid-cols-[minmax(0,1fr)_160px]"
                        : "flex flex-col gap-4"
                    }
                  >
                    <div className="flex flex-col gap-4">
                      {sortedOptions.map((option) => {
                        return (
                          <div key={option.id} className="flex flex-col gap-1">
                            <label className="text-xs font-medium text-muted-foreground">
                              {resolveOptionName(option)}
                            </label>
                            <Input
                              value={createText[option.id] ?? ""}
                              onChange={(e) =>
                                setCreateText((prev) => ({
                                  ...prev,
                                  [option.id]: e.target.value,
                                }))
                              }
                              disabled={createSku.isPending || syncImages.isPending}
                              className="h-9 text-sm"
                            />
                          </div>
                        );
                      })}
                    </div>

                    {hasVariantImages && (
                      <div className="flex w-full flex-col items-center gap-1.5">
                        <button
                          type="button"
                          onClick={() => setCreateGalleryOpen(true)}
                          disabled={createSku.isPending || syncImages.isPending}
                          className="relative flex aspect-square w-full flex-col items-center justify-center gap-1.5 overflow-hidden rounded-md border border-dashed bg-muted/20 text-muted-foreground transition hover:border-primary/50 hover:bg-muted/40 disabled:cursor-not-allowed disabled:opacity-60"
                          aria-label={t("hq.products.sku_edit.gallery.open")}
                        >
                          {createImages[0] ? (
                            <Image
                              src={createImages[0].url}
                              alt={createImages[0].original_name}
                              fill
                              sizes="200px"
                              className="object-cover"
                            />
                          ) : (
                            <>
                              <ImageIcon className="size-5 opacity-70" />
                              <span className="text-[11px] font-medium">
                                {t("hq.products.sku_edit.gallery.open")}
                              </span>
                            </>
                          )}
                        </button>
                        <Button
                          type="button"
                          variant="link"
                          size="sm"
                          className="h-auto px-0 text-xs text-primary"
                          onClick={() => setCreateGalleryOpen(true)}
                          disabled={createSku.isPending || syncImages.isPending}
                        >
                          {createImages.length > 0
                            ? t("hq.products.sku_edit.gallery.edit_existing", {
                                n: createImages.length,
                              })
                            : t("hq.products.sku_edit.gallery.pick_existing")}
                        </Button>
                      </div>
                    )}
                  </div>
                </CardContent>
              </Card>

              <Card data-slot="sku-create-pricing-card">
                <CardHeader className="px-5 pt-4 pb-0">
                  <CardTitle className="text-sm font-semibold">
                    {t("hq.products.sku_edit.pricing.title")}
                  </CardTitle>
                </CardHeader>
                <CardContent className="px-5 pt-3 pb-5">
                  <div className="flex flex-wrap gap-x-4 gap-y-4">
                    <div className="flex min-w-[240px] flex-1 flex-col gap-1">
                      <label className="text-xs font-medium text-muted-foreground">
                        {t("hq.products.sku_edit.pricing.sku_code")}
                      </label>
                      <Input
                        value={createSkuCode}
                        onChange={(e) => setCreateSkuCode(e.target.value)}
                        maxLength={50}
                        placeholder={t("hq.products.sku_edit.pricing.sku_code_placeholder")}
                        className="h-9 text-sm"
                        disabled={createSku.isPending}
                      />
                      {createSkuCodeInvalid && (
                        <span className="text-xs text-destructive">
                          {t("hq.products.sku_edit.pricing.sku_code_too_long")}
                        </span>
                      )}
                    </div>
                    <div className="flex min-w-[240px] flex-1 flex-col gap-1">
                      <label className="text-xs font-medium text-muted-foreground">
                        {t("hq.products.sku_edit.pricing.selling_price")}
                      </label>
                      <div className="relative">
                        <Input
                          type="number"
                          min="0"
                          value={createPrice}
                          onChange={(e) => setCreatePrice(e.target.value)}
                          placeholder="0"
                          className={
                            symbolFirst
                              ? "h-9 pl-8 text-right text-sm font-medium"
                              : "h-9 pr-8 text-right text-sm font-medium"
                          }
                          disabled={createSku.isPending}
                        />
                        <span
                          className={`pointer-events-none absolute top-1/2 -translate-y-1/2 text-xs text-muted-foreground ${
                            symbolFirst ? "left-3" : "right-3"
                          }`}
                        >
                          {currencySymbol(locale)}
                        </span>
                      </div>
                      {createPriceInvalid && (
                        <span className="text-xs text-destructive">
                          {t("hq.products.sku_edit.pricing.invalid")}
                        </span>
                      )}
                    </div>
                  </div>
                </CardContent>
              </Card>

              <div className="flex items-center justify-between gap-2 pt-2">
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-9"
                  onClick={() => setShowCreate(false)}
                  disabled={createSku.isPending}
                >
                  {t("common.cancel")}
                </Button>
                <Button
                  type="button"
                  size="sm"
                  className="h-9 gap-1.5"
                  onClick={() => void handleCreate()}
                  disabled={!canCreate}
                >
                  {createSku.isPending && <Spinner className="size-3.5" />}
                  {t("hq.products.sku_edit.create.submit")}
                </Button>
              </div>
            </>
          )}

          {/* ── Edit existing SKU ── */}
          {!showCreate && (
            <>
              {/* Attributes — read-only pills showing current option values. */}
              <Card data-slot="sku-attributes-card">
                <CardHeader className="px-5 pt-4 pb-0">
                  <CardTitle className="text-sm font-semibold">
                    {t("hq.products.sku_edit.attributes.title")}
                  </CardTitle>
                </CardHeader>
                <CardContent className="px-5 pt-3 pb-5">
                  <div
                    className={
                      hasVariantImages
                        ? "grid gap-4 md:grid-cols-[minmax(0,1fr)_160px]"
                        : "flex flex-col gap-4"
                    }
                  >
                    <div className="flex flex-col gap-4">
                      {[sku.option_value1, sku.option_value2, sku.option_value3].map((val, idx) => {
                        if (!val) return null;
                        const opt = options[idx];
                        const optionName = opt
                          ? resolveOptionName(opt)
                          : t("hq.products.sku_edit.attributes.option_fallback", { n: idx + 1 });
                        return (
                          <div key={`opt-${idx}`} className="flex flex-col gap-1">
                            <label className="text-xs font-medium text-muted-foreground">
                              {optionName}
                            </label>
                            <Input
                              value={resolveOptionValueLabel(val)}
                              readOnly
                              className="h-9 bg-muted/30 text-sm"
                            />
                          </div>
                        );
                      })}
                      {skuCombo(sku).length === 0 && (
                        <p className="text-xs text-muted-foreground italic">
                          {t("hq.products.sku_edit.attributes.no_options")}
                        </p>
                      )}
                    </div>

                    {hasVariantImages && (
                      <div className="flex w-full flex-col items-center gap-1.5">
                        <button
                          type="button"
                          onClick={() => setGalleryOpen(true)}
                          disabled={disabledAll}
                          className="relative flex aspect-square w-full flex-col items-center justify-center gap-1.5 overflow-hidden rounded-md border border-dashed bg-muted/20 text-muted-foreground transition hover:border-primary/50 hover:bg-muted/40 disabled:cursor-not-allowed disabled:opacity-60"
                          aria-label={t("hq.products.sku_edit.gallery.open")}
                        >
                          {images[0] ? (
                            <Image
                              src={images[0].url}
                              alt={images[0].original_name}
                              fill
                              sizes="200px"
                              className="object-cover"
                            />
                          ) : (
                            <>
                              <ImageIcon className="size-5 opacity-70" />
                              <span className="text-[11px] font-medium">
                                {t("hq.products.sku_edit.gallery.open")}
                              </span>
                            </>
                          )}
                        </button>
                        <Button
                          type="button"
                          variant="link"
                          size="sm"
                          className="h-auto px-0 text-xs text-primary"
                          onClick={() => setGalleryOpen(true)}
                          disabled={disabledAll}
                        >
                          {images.length > 0
                            ? t("hq.products.sku_edit.gallery.edit_existing", { n: images.length })
                            : t("hq.products.sku_edit.gallery.pick_existing")}
                        </Button>
                      </div>
                    )}
                  </div>
                </CardContent>
              </Card>

              <Card data-slot="sku-pricing-card">
                <CardHeader className="px-5 pt-4 pb-0">
                  <CardTitle className="text-sm font-semibold">
                    {t("hq.products.sku_edit.pricing.title")}
                  </CardTitle>
                </CardHeader>
                <CardContent className="px-5 pt-3 pb-5">
                  <div className="flex flex-wrap gap-x-4 gap-y-4">
                    <div className="flex min-w-[240px] flex-1 flex-col gap-1">
                      <label className="text-xs font-medium text-muted-foreground">
                        {t("hq.products.sku_edit.pricing.sku_code")}
                      </label>
                      <Input
                        value={skuCode}
                        onChange={(e) => setSkuCode(e.target.value)}
                        maxLength={50}
                        placeholder={t("hq.products.sku_edit.pricing.sku_code_placeholder")}
                        className="h-9 text-sm"
                      />
                      {skuCodeInvalid && (
                        <span className="text-xs text-destructive">
                          {t("hq.products.sku_edit.pricing.sku_code_too_long")}
                        </span>
                      )}
                    </div>
                    <div className="flex min-w-[240px] flex-1 flex-col gap-1">
                      <label className="text-xs font-medium text-muted-foreground">
                        {t("hq.products.sku_edit.pricing.selling_price")}
                      </label>
                      <div className="relative">
                        <Input
                          type="number"
                          min="0"
                          value={price}
                          onChange={(e) => setPrice(e.target.value)}
                          placeholder="0"
                          className={
                            symbolFirst
                              ? "h-9 pl-8 text-right text-sm font-medium"
                              : "h-9 pr-8 text-right text-sm font-medium"
                          }
                        />
                        <span
                          className={`pointer-events-none absolute top-1/2 -translate-y-1/2 text-xs text-muted-foreground ${
                            symbolFirst ? "left-3" : "right-3"
                          }`}
                        >
                          {currencySymbol(locale)}
                        </span>
                      </div>
                      {priceInvalid && price !== "" && (
                        <span className="text-xs text-destructive">
                          {t("hq.products.sku_edit.pricing.invalid")}
                        </span>
                      )}
                    </div>
                    {/* Plan-024 — inventory mode controls order-close stock deduction.
                        Hint moved into a "?" popover next to the label (matches
                        the recipe form pattern) so the long explainer doesn't
                        push the form vertically by 2 lines per row. */}
                    <div className="flex min-w-[240px] flex-1 flex-col gap-1">
                      <div className="flex items-center gap-1">
                        <label className="text-xs font-medium text-muted-foreground">
                          {t("hq.products.sku_edit.pricing.inventory_mode")}
                        </label>
                        <Popover>
                          <PopoverTrigger asChild>
                            <button
                              type="button"
                              className="inline-flex size-5 cursor-pointer items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                              aria-label={t("common.help")}
                            >
                              <HelpCircle className="size-3.5" />
                            </button>
                          </PopoverTrigger>
                          <PopoverContent
                            align="start"
                            className="max-w-sm space-y-2 text-xs leading-relaxed text-muted-foreground"
                          >
                            <div className="flex gap-2">
                              <span className="mt-[2px] inline-block size-1.5 shrink-0 rounded-full bg-muted-foreground/60" />
                              <span>
                                <span className="font-semibold text-foreground">
                                  {t("hq.products.sku_edit.pricing.inventory_mode_made_to_order")}:
                                </span>{" "}
                                {t(
                                  "hq.products.sku_edit.pricing.inventory_mode_hint_made_to_order"
                                ).replace(/^[^:]+:\s*/u, "")}
                              </span>
                            </div>
                            <div className="flex gap-2">
                              <span className="mt-[2px] inline-block size-1.5 shrink-0 rounded-full bg-muted-foreground/60" />
                              <span>
                                <span className="font-semibold text-foreground">
                                  {t("hq.products.sku_edit.pricing.inventory_mode_track_stock")}:
                                </span>{" "}
                                {t(
                                  "hq.products.sku_edit.pricing.inventory_mode_hint_track_stock"
                                ).replace(/^[^:]+:\s*/u, "")}
                              </span>
                            </div>
                          </PopoverContent>
                        </Popover>
                      </div>
                      <Select
                        value={inventoryMode}
                        onValueChange={(v) =>
                          setInventoryMode(v as "made_to_order" | "track_stock")
                        }
                      >
                        <SelectTrigger className="h-9 text-sm">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="made_to_order">
                            {t("hq.products.sku_edit.pricing.inventory_mode_made_to_order")}
                          </SelectItem>
                          <SelectItem value="track_stock">
                            {t("hq.products.sku_edit.pricing.inventory_mode_track_stock")}
                          </SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                    {/* Recipe picker — shown for every SKU regardless of
                        inventory_mode. Recipe is also consumed by the
                        Production Order auto-derive flow, displayed in
                        allergen rollups, and used by the kitchen to know
                        how to prepare the dish — gating by track_stock
                        hid that link from operators of made-to-order
                        SKUs. */}
                    <div className="flex min-w-[240px] flex-1 flex-col gap-1">
                      <div className="flex items-center gap-1">
                        <label className="text-xs font-medium text-muted-foreground">
                          {t("hq.products.sku_edit.pricing.recipe")}
                        </label>
                        <Popover>
                          <PopoverTrigger asChild>
                            <button
                              type="button"
                              className="inline-flex size-5 cursor-pointer items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                              aria-label={t("common.help")}
                            >
                              <HelpCircle className="size-3.5" />
                            </button>
                          </PopoverTrigger>
                          <PopoverContent
                            align="start"
                            className="max-w-sm text-xs leading-relaxed text-muted-foreground"
                          >
                            {t("hq.products.sku_edit.pricing.recipe_hint")}
                          </PopoverContent>
                        </Popover>
                      </div>
                      <Combobox
                        options={recipeOptions}
                        value={recipeComboboxValue}
                        onChange={(encoded) => {
                          if (!encoded) {
                            setRecipeId("");
                            return;
                          }
                          setRecipeId(encoded.split("|")[0]);
                        }}
                        placeholder={t("hq.products.sku_edit.pricing.recipe_placeholder")}
                        searchPlaceholder={t(
                          "hq.products.sku_edit.pricing.recipe_search_placeholder"
                        )}
                        clearable
                      />
                    </div>
                  </div>
                </CardContent>
              </Card>

              <div className="flex items-center justify-between gap-2 pt-2">
                <Button
                  type="button"
                  variant="destructive"
                  size="sm"
                  className="h-9 gap-1.5"
                  onClick={() => setDeleteOpen(true)}
                  disabled={disabledAll}
                >
                  <Trash2 className="size-3.5" />
                  {t("hq.products.sku_edit.delete_variant")}
                </Button>
                <Button
                  type="button"
                  size="sm"
                  className="h-9 gap-1.5"
                  onClick={() => {
                    void handleSave();
                  }}
                  disabled={!canSubmit || disabledAll}
                >
                  {update.isPending ? (
                    <Spinner className="size-3.5" />
                  ) : (
                    <Save className="size-3.5" />
                  )}
                  {t("hq.products.sku_edit.update")}
                </Button>
              </div>
            </>
          )}
        </div>
      </div>

      {/* Gallery dialog — only mounted for variant SKUs. Simple products
          inherit their image from the product gallery, so the dialog is
          unreachable in that case. */}
      {hasVariantImages && !showCreate && (
        <SkuGalleryDialog
          open={galleryOpen}
          onOpenChange={setGalleryOpen}
          initial={images}
          onConfirm={handleGalleryConfirm}
        />
      )}

      {hasVariantImages && showCreate && (
        <SkuGalleryDialog
          open={createGalleryOpen}
          onOpenChange={setCreateGalleryOpen}
          initial={createImages}
          onConfirm={(next) => setCreateImages(next)}
        />
      )}

      {/* Delete confirm */}
      <AlertDialog open={deleteOpen} onOpenChange={setDeleteOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.products.sku_edit.delete.title")}</AlertDialogTitle>
            <AlertDialogDescription>
              {t("hq.products.sku_edit.delete.desc", { name: headerTitle })}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleteOne.isPending}>
              {t("common.cancel")}
            </AlertDialogCancel>
            <AlertDialogAction
              onClick={(e) => {
                e.preventDefault();
                void handleDelete();
              }}
              disabled={deleteOne.isPending}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {deleteOne.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("common.delete")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* SKU_IN_MENU: shown when delete is blocked because the SKU is in a menu */}
      <AlertDialog open={skuInMenus !== null} onOpenChange={(open) => !open && setSkuInMenus(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.products.options.sku_in_menu_title")}</AlertDialogTitle>
            <AlertDialogDescription asChild>
              <div>
                <p className="mb-2">{t("hq.products.options.sku_in_menu_desc")}</p>
                <ul className="space-y-0.5 text-sm">
                  {(skuInMenus ?? []).map((m) => (
                    <li key={m.id} className="text-muted-foreground">
                      {m.name}
                    </li>
                  ))}
                </ul>
              </div>
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogAction onClick={() => setSkuInMenus(null)}>
              {t("common.close")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Unsaved-changes guard — opens whenever a navigation intent is blocked
          by `requestNavigate` or a SidebarItem click while `hasChanges` is
          true. Mirrors the 3-button pattern used on /products/[id]. */}
      <AlertDialog
        open={pendingHref !== null}
        onOpenChange={(open) => {
          if (!open) setPendingHref(null);
        }}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.products.unsaved.title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("hq.products.unsaved.desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={update.isPending}>
              {t("hq.products.unsaved.continue_editing")}
            </AlertDialogCancel>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => {
                const target = pendingHref;
                setPendingHref(null);
                if (target) router.push(target);
              }}
              disabled={update.isPending}
            >
              {t("hq.products.unsaved.exit_without_saving")}
            </Button>
            <AlertDialogAction
              onClick={async (e) => {
                e.preventDefault();
                const target = pendingHref;
                const ok = await handleSave();
                if (ok && target) {
                  setPendingHref(null);
                  router.push(target);
                }
              }}
              disabled={!canSubmit || update.isPending}
            >
              {update.isPending ? (
                <Spinner className="mr-1.5 size-3.5" />
              ) : (
                <Save className="mr-1.5 size-3.5" />
              )}
              {t("hq.products.sku_edit.update")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
