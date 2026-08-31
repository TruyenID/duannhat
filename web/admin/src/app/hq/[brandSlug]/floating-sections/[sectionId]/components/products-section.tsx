"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import Image from "next/image";
import {
  Alert,
  AlertDescription,
  AlertTitle,
  Badge,
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  StatusBadge,
} from "@godxjp/ui";
import {
  GripVertical,
  ImageIcon,
  Package,
  RotateCcw,
  Search,
  Trash2,
  UtensilsCrossed,
} from "lucide-react";
import {
  DndContext,
  type DragEndEvent,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
} from "@dnd-kit/core";
import { SortableContext, arrayMove, useSortable, verticalListSortingStrategy } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { useTranslation } from "@/providers/app-provider";
import { formatPriceAmount } from "@/lib/currency";
import type { LocaleCode } from "@/i18n";
import { useAllProducts, useProduct } from "@/hooks/api/use-products";
import { useCategoryLookup } from "@/hooks/api/use-categories";
import { useProductTypeLookup } from "@/hooks/api/use-product-types";
import {
  useFloatingSection,
  useAddFloatingSectionProducts,
  useRemoveFloatingSectionProduct,
  useOverrideFloatingSectionSkuPrice,
  useResetFloatingSectionSkuPrice,
  useReorderFloatingSectionProducts,
  useUpdateFloatingSectionProductTaxType,
} from "@/hooks/api/use-floating-sections";
import { useTaxTypeLookup } from "@/hooks/api/use-tax-types";
import type { TaxTypeLookupItem } from "@/services/tax-type-service";
import type { FloatingSectionProduct } from "@/types/models/FloatingSectionProduct";
import type { FloatingSectionProductSku } from "@/types/models/FloatingSectionProductSku";
import type { Product } from "@/services/product-service";

// =========================================================================
//  Catalog row (left panel) — mirrors DraggableProductCard in
//  menus/[menuId]/items/page.tsx, minus dnd-kit (native HTML5 drag instead).
// =========================================================================

function CatalogRow({
  product,
  onClick,
  onDragStart,
}: {
  product: Product;
  onClick: () => void;
  onDragStart: (e: React.DragEvent<HTMLDivElement>) => void;
}) {
  const { t } = useTranslation();
  return (
    <div
      draggable
      onDragStart={onDragStart}
      onClick={onClick}
      className="flex cursor-grab items-center gap-2.5 rounded-md border bg-card p-2 transition-all select-none hover:border-primary/40 hover:shadow-sm active:cursor-grabbing"
    >
      <div className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
        {product.image_url ? (
          <Image
            src={product.image_url}
            alt={product.name}
            width={32}
            height={32}
            className="h-full w-full object-cover"
          />
        ) : (
          <ImageIcon className="size-3.5 text-muted-foreground" />
        )}
      </div>
      <div className="flex min-w-0 flex-1 flex-col">
        <span className="truncate text-sm font-medium">
          {product.name || t("hq.menus.items.untitled")}
        </span>
        <span className="text-[11px] text-muted-foreground">
          {t("hq.menus.items.variants_count", { n: product.active_skus_count ?? 0 })}
        </span>
      </div>
      <GripVertical className="size-3.5 shrink-0 text-muted-foreground/50" />
    </div>
  );
}

function formatPrice(value: number | null | undefined, locale: LocaleCode): string {
  if (value == null || Number.isNaN(value)) return "—";
  return formatPriceAmount(value, locale);
}

function variantLabel(sku: FloatingSectionProductSku): string {
  const productSku = sku.productSku;
  const parts = [
    productSku?.optionValue1?.label,
    productSku?.optionValue2?.label,
    productSku?.optionValue3?.label,
  ].filter(Boolean);
  return parts.length > 0 ? parts.join(" / ") : (productSku?.name ?? productSku?.sku ?? "—");
}

// =========================================================================
//  Sortable product row (right panel) — mirrors SortableMenuItem from
//  menus/[menuId]/items/page.tsx exactly: grip handle + thumbnail + name +
//  variants count + remove button, no inline expand. Clicking the row opens
//  SkuDetailDialog (this component's own equivalent of Menu's
//  ProductDetailDialog) for SKU-level price/toggle actions. Unlike Menu
//  (batch-save via a page-level Save button), reordering here saves
//  immediately per drop via reorderMutation, matching every other
//  FloatingSection interaction (toggle, price override, etc.).
// =========================================================================

// Per-item tax-type override dropdown — mirrors MenuItemTaxSelect on the menu
// items page. "Kế thừa" (inherit) = null = the item follows the product →
// branch → brand tax chain; picking a type overrides it for this floating item.
function FloatingItemTaxSelect({
  value,
  taxTypes,
  disabled,
  onChange,
}: {
  value: string | null;
  taxTypes: TaxTypeLookupItem[];
  disabled?: boolean;
  onChange: (taxTypeId: string | null) => void;
}) {
  const { t } = useTranslation();
  const INHERIT = "__inherit__";

  return (
    <Select
      value={value ?? INHERIT}
      disabled={disabled}
      onValueChange={(next) => onChange(next === INHERIT ? null : next)}
    >
      <SelectTrigger
        size="sm"
        className="h-7 w-[8.5rem] shrink-0 text-[11px]"
        aria-label={t("hq.menus.items.tax.aria")}
        onClick={(e) => e.stopPropagation()}
      >
        <SelectValue placeholder={t("hq.menus.items.tax.inherit")} />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value={INHERIT}>{t("hq.menus.items.tax.inherit")}</SelectItem>
        {taxTypes.map((tt) => (
          <SelectItem key={tt.id} value={tt.id}>
            {tt.name} ({tt.rate}%)
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

function SortableProductRow({
  p,
  product,
  taxTypes,
  onOpenDetail,
  onRemove,
  onTaxChange,
}: {
  p: FloatingSectionProduct;
  product: Product | undefined;
  taxTypes: TaxTypeLookupItem[];
  onOpenDetail: () => void;
  onRemove: () => void;
  onTaxChange: (taxTypeId: string | null) => void;
}) {
  const { t } = useTranslation();
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: p.id,
  });
  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
  };
  // Variant count: prefer the section's own saved SKUs, but a just-dragged-in
  // (draft-*) row has none yet, so fall back to the catalog product's active
  // SKU count — the same source the left catalog panel counts from — instead
  // of showing "0 biến thể".
  const savedSkuCount = p.skus?.length ?? 0;
  const variantCount = savedSkuCount > 0 ? savedSkuCount : (product?.active_skus_count ?? 0);

  return (
    <div
      ref={setNodeRef}
      style={style}
      onClick={onOpenDetail}
      className={[
        "flex cursor-pointer items-center gap-2 rounded-md border bg-card p-2 transition-all select-none hover:border-primary/40 hover:shadow-sm",
        isDragging ? "opacity-40" : "",
      ].join(" ")}
    >
      <button
        type="button"
        {...attributes}
        {...listeners}
        onClick={(e) => e.stopPropagation()}
        className="shrink-0 cursor-grab touch-none text-muted-foreground active:cursor-grabbing"
      >
        <GripVertical className="size-3.5" />
      </button>
      <div className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
        {product?.image_url ? (
          <Image
            src={product.image_url}
            alt={product.name}
            width={32}
            height={32}
            className="h-full w-full object-cover"
          />
        ) : (
          <ImageIcon className="size-3.5 text-muted-foreground" />
        )}
      </div>
      <div className="min-w-0 flex-1">
        <span className="block truncate text-sm font-medium">
          {product?.name ?? p.product?.name ?? t("hq.menus.items.untitled")}
        </span>
        <span className="text-[11px] text-muted-foreground">
          {t("hq.menus.items.variants_count", { n: variantCount })}
        </span>
      </div>

      {/* Per-item tax override (parity with the menu items page). */}
      {taxTypes.length > 0 && (
        <FloatingItemTaxSelect
          value={p.tax_type_id ?? null}
          taxTypes={taxTypes}
          onChange={onTaxChange}
        />
      )}

      <Button
        variant="ghost"
        size="icon"
        className="size-7 shrink-0 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
        onClick={(e) => {
          e.stopPropagation();
          onRemove();
        }}
      >
        <Trash2 className="size-3" />
      </Button>
    </div>
  );
}

// =========================================================================
//  Catalog product detail dialog (left panel, read-only preview) — mirrors
//  Menu's ProductDetailDialog: clicking a catalog row previews the base
//  catalog product (status, type, categories, options, active variants +
//  their catalog price). No add/remove/toggle here — only drag-and-drop adds
//  a product to the section, same split as Menu.
// =========================================================================

function CatalogProductDetailDialog({
  brandSlug,
  productId,
  open,
  onOpenChange,
}: {
  brandSlug: string;
  productId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t, locale } = useTranslation();
  const { data, isLoading } = useProduct(brandSlug, productId);
  const product = data?.data;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent aria-describedby={undefined} className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle className="text-base">
            {product?.name || t("hq.menus.items.product_detail")}
          </DialogTitle>
        </DialogHeader>

        {isLoading ? (
          <div className="flex items-center justify-center py-8">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        ) : product ? (
          <div className="flex flex-col gap-4 text-sm">
            <div className="grid grid-cols-2 gap-x-4 gap-y-2">
              <div>
                <span className="text-xs text-muted-foreground">{t("common.status")}</span>
                <div className="mt-0.5">
                  <StatusBadge status={product.status} />
                </div>
              </div>
              <div>
                <span className="text-xs text-muted-foreground">{t("common.type")}</span>
                <p className="mt-0.5 font-medium">{product.productType?.name ?? "—"}</p>
              </div>
              {product.categories && product.categories.length > 0 && (
                <div className="col-span-2">
                  <span className="text-xs text-muted-foreground">
                    {t("hq.products.sidebar.menu_categories")}
                  </span>
                  <div className="mt-0.5 flex flex-wrap gap-1">
                    {product.categories.map((c) => (
                      <Badge key={c.id} variant="outline" className="h-5 text-[10px]">
                        {c.name}
                      </Badge>
                    ))}
                  </div>
                </div>
              )}
            </div>

            {product.options && product.options.length > 0 && (
              <div>
                <span className="text-xs font-medium tracking-tight text-muted-foreground uppercase">
                  {t("hq.menus.items.options")}
                </span>
                <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1">
                  {product.options.map((opt) => (
                    <div key={opt.id} className="text-xs">
                      <span className="font-medium">{opt.name}:</span>{" "}
                      <span className="text-muted-foreground">
                        {opt.values?.map((v) => v.label).join(", ") ?? "—"}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {product.skus && product.skus.length > 0 && (
              <div>
                <span className="text-xs font-medium tracking-tight text-muted-foreground uppercase">
                  {t("hq.menus.items.variants_active")}
                </span>
                <div className="mt-1.5 max-h-52 divide-y overflow-y-auto rounded-md border">
                  {product.skus.map((sku) => {
                    const combo = [sku.option_value1, sku.option_value2, sku.option_value3]
                      .filter(Boolean)
                      .map((v) => v!.label)
                      .join(" / ");
                    const isSimpleSku = !product.options?.length && product.skus!.length === 1;
                    const label =
                      combo ||
                      sku.name ||
                      (isSimpleSku ? product.name : "") ||
                      t("hq.menus.items.default_variant");

                    return (
                      <div
                        key={sku.id}
                        className={`flex items-center gap-2.5 px-3 py-2.5 text-xs ${
                          !sku.is_active ? "opacity-50" : ""
                        }`}
                      >
                        <div className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
                          {sku.image_url ? (
                            <Image
                              src={sku.image_url}
                              alt={label}
                              width={32}
                              height={32}
                              className="h-full w-full object-cover"
                            />
                          ) : (
                            <ImageIcon className="size-3.5 text-muted-foreground" />
                          )}
                        </div>
                        <div className="min-w-0 flex-1">
                          <span className="font-medium">{label}</span>
                          {sku.sku && (
                            <code className="ml-1.5 rounded bg-muted px-1 py-0.5 text-[10px] text-muted-foreground">
                              {sku.sku}
                            </code>
                          )}
                        </div>
                        <span className="shrink-0 font-semibold tabular-nums">
                          {formatPriceAmount(sku.selling_price, locale)}
                        </span>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}
          </div>
        ) : (
          <div className="py-8 text-center text-sm text-muted-foreground">
            {t("hq.menus.items.product_not_found")}
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}

// =========================================================================
//  SKU detail dialog — Floating Section's equivalent of Menu's
//  ProductDetailDialog, same layout: title + Status/Type grid + a divide-y
//  variant list (thumbnail, name, SKU code, price on the right). Menu's
//  version is fully read-only; this one makes the price a button (opens the
//  existing edit-price dialog) so HQ can still set the promo price on the
//  master template. No per-SKU active/inactive toggle here — HQ only defines
//  the template; enabling/disabling a specific variant is the shop's call on
//  its own branch clone (see shop/[shopSlug]/floating-sections).
// =========================================================================

function SkuDetailDialog({
  open,
  onOpenChange,
  productName,
  productType,
  isActive,
  skus,
  productId,
  openEditPrice,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  productName: string;
  productType: string | null;
  isActive: boolean;
  skus: FloatingSectionProductSku[];
  productId: string;
  openEditPrice: (productId: string, sku: FloatingSectionProductSku) => void;
}) {
  const { t, locale } = useTranslation();

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent aria-describedby={undefined} className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle className="text-base">{productName}</DialogTitle>
        </DialogHeader>

        <div className="flex flex-col gap-4 text-sm">
          <div className="grid grid-cols-2 gap-x-4 gap-y-2">
            <div>
              <span className="text-xs text-muted-foreground">{t("common.status")}</span>
              <div className="mt-0.5">
                <StatusBadge status={isActive ? "active" : "inactive"} />
              </div>
            </div>
            <div>
              <span className="text-xs text-muted-foreground">{t("common.type")}</span>
              <p className="mt-0.5 font-medium">{productType ?? "—"}</p>
            </div>
          </div>

          {skus.length > 0 && (
            <div>
              <span className="text-xs font-medium tracking-tight text-muted-foreground uppercase">
                {t("hq.menus.items.variants_active")}
              </span>
              <div className="mt-1.5 max-h-64 divide-y overflow-y-auto rounded-md border">
                {skus.map((sku) => {
                  const label = variantLabel(sku);
                  return (
                    <div
                      key={sku.id}
                      className={`flex items-center gap-2.5 px-3 py-2.5 text-xs ${
                        !sku.is_active ? "opacity-50" : ""
                      }`}
                    >
                      <div className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
                        {sku.productSku?.image_url ? (
                          <Image
                            src={sku.productSku.image_url}
                            alt={label}
                            width={32}
                            height={32}
                            className="h-full w-full object-cover"
                          />
                        ) : (
                          <ImageIcon className="size-3.5 text-muted-foreground" />
                        )}
                      </div>
                      <div className="min-w-0 flex-1">
                        <span className="font-medium">{label}</span>
                        {sku.productSku?.sku && (
                          <code className="ml-1.5 rounded bg-muted px-1 py-0.5 text-[10px] text-muted-foreground">
                            {sku.productSku.sku}
                          </code>
                        )}
                      </div>
                      <button
                        type="button"
                        onClick={() => openEditPrice(productId, sku)}
                        className="shrink-0 font-semibold tabular-nums hover:underline"
                      >
                        {formatPrice(sku.selling_price, locale)}
                      </button>
                    </div>
                  );
                })}
              </div>
            </div>
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
}

// =========================================================================
//  Component
// =========================================================================

export function FloatingSectionProductsSection({
  brandSlug,
  sectionId,
  onStateChange,
}: {
  brandSlug: string;
  sectionId: string;
  /** Reports the product-list draft's Save/Cancel state on every change, so
   * the page-level header can render the Save/Cancel buttons + "unsaved"
   * badge there — mirrors Menu's items page, where those live in the
   * top-level header, not inside the panel. */
  onStateChange?: (state: {
    hasChanges: boolean;
    isSaving: boolean;
    onSave: () => void;
    onCancel: () => void;
  }) => void;
}) {
  const { t } = useTranslation();

  const { data, isLoading, isError, refetch } = useFloatingSection(brandSlug, sectionId);
  const fsProducts = useMemo<FloatingSectionProduct[]>(() => data?.data.products ?? [], [data]);

  const { data: taxTypeLookup } = useTaxTypeLookup(brandSlug);
  const taxTypes = useMemo(() => taxTypeLookup?.data ?? [], [taxTypeLookup]);
  const updateTaxType = useUpdateFloatingSectionProductTaxType(brandSlug);

  // Same fallback pattern as menus/[menuId]/items/page.tsx: the active-product
  // catalog is the primary source for name/image/variant count, with the
  // floating section's own embedded `product` relation as a fallback for
  // items that are no longer in the active catalog (e.g. paused since).
  const { data: allProducts, isLoading: catalogLoading } = useAllProducts(brandSlug, {
    status: "active",
    sort: "name",
  });
  const catalog = useMemo(() => allProducts ?? [], [allProducts]);

  const catalogMap = useMemo(() => {
    const map = new Map<string, Product>();
    for (const p of catalog) map.set(p.id, p);
    return map;
  }, [catalog]);

  const embeddedMap = useMemo(() => {
    const map = new Map<string, Product>();
    for (const fp of fsProducts) {
      if (fp.product) map.set(fp.product_id, fp.product);
    }
    return map;
  }, [fsProducts]);

  const productMap = useMemo(() => {
    if (!embeddedMap.size) return catalogMap;
    const map = new Map<string, Product>(catalogMap);
    for (const [id, p] of embeddedMap) {
      if (!map.has(id)) map.set(id, p);
    }
    return map;
  }, [catalogMap, embeddedMap]);

  const { data: categoriesResponse } = useCategoryLookup(brandSlug);
  const categories = useMemo(() => categoriesResponse?.data ?? [], [categoriesResponse]);

  const { data: productTypesResponse } = useProductTypeLookup(brandSlug);
  const productTypes = useMemo(() => productTypesResponse?.data ?? [], [productTypesResponse]);

  const [search, setSearch] = useState("");
  const [categoryFilter, setCategoryFilter] = useState<string>("__all__");
  const [productTypeFilter, setProductTypeFilter] = useState<string>("__all__");

  // Clicking a catalog row (left panel) opens a read-only preview — same
  // split as Menu's DraggableProductCard: click = inspect, drag = add.
  const [catalogDetailProductId, setCatalogDetailProductId] = useState<string | null>(null);

  const addMutation = useAddFloatingSectionProducts(brandSlug);
  const removeMutation = useRemoveFloatingSectionProduct(brandSlug);
  const overridePriceMutation = useOverrideFloatingSectionSkuPrice(brandSlug);
  const resetPriceMutation = useResetFloatingSectionSkuPrice(brandSlug);
  const reorderMutation = useReorderFloatingSectionProducts(brandSlug);

  const [editingSku, setEditingSku] = useState<{ productId: string; sku: FloatingSectionProductSku } | null>(
    null
  );
  const [editDraft, setEditDraft] = useState("");

  // ── Product list draft — mirrors Menu's items page: add/remove/reorder are
  // local-only until Save; nothing hits the API until the user commits. SKU
  // toggle/price actions stay auto-save (separate scope, per user request).
  const [draftIds, setDraftIds] = useState<string[]>([]);
  const [initialized, setInitialized] = useState(false);
  const [originalSnapshot, setOriginalSnapshot] = useState<string>("");
  const [isSaving, setIsSaving] = useState(false);

  useEffect(() => {
    if (initialized) return;
    const sorted = [...fsProducts].sort((a, b) => a.display_order - b.display_order);
    const ids = sorted.map((p) => p.product_id);
    setDraftIds(ids);
    setOriginalSnapshot(JSON.stringify(ids));
    setInitialized(true);
  }, [fsProducts, initialized]);

  const hasChanges = useMemo(
    () => initialized && JSON.stringify(draftIds) !== originalSnapshot,
    [draftIds, originalSnapshot, initialized]
  );

  const assignedIds = useMemo(() => new Set(draftIds), [draftIds]);
  const available = useMemo(() => {
    let result = catalog.filter((p) => !assignedIds.has(p.id));
    if (categoryFilter !== "__all__") {
      result = result.filter((p) => p.categories?.some((c) => c.id === categoryFilter));
    }
    if (productTypeFilter !== "__all__") {
      result = result.filter((p) => p.product_type_id === productTypeFilter);
    }
    if (search.trim()) {
      const q = search.trim().toLowerCase();
      result = result.filter((p) => p.name.toLowerCase().includes(q));
    }
    return result;
  }, [catalog, assignedIds, search, categoryFilter, productTypeFilter]);

  // Draft rows for the right panel — resolve each draft product_id to its
  // live FloatingSectionProduct (server-known SKUs/pricing) when it exists,
  // or a placeholder row (no SKUs yet — nothing to show until saved) for a
  // product just dragged in.
  const fsProductByProductId = useMemo(
    () => new Map(fsProducts.map((p) => [p.product_id, p])),
    [fsProducts]
  );
  const orderedProducts = useMemo(
    () =>
      draftIds.map((productId) => {
        const existing = fsProductByProductId.get(productId);
        if (existing) return existing;
        const placeholder: FloatingSectionProduct = {
          id: `draft-${productId}`,
          floating_section_id: sectionId,
          product_id: productId,
          is_active: true,
          display_order: 0,
          skus: [],
        };
        return placeholder;
      }),
    [draftIds, fsProductByProductId, sectionId]
  );

  // SKU detail dialog is keyed by row id — a not-yet-saved (draft-*) row has
  // no SKUs yet, so its dialog simply renders the empty state.
  const [detailProductRowId, setDetailProductRowId] = useState<string | null>(null);
  const detailProduct = useMemo(
    () => orderedProducts.find((p) => p.id === detailProductRowId) ?? null,
    [orderedProducts, detailProductRowId]
  );
  const sortableIds = useMemo(() => orderedProducts.map((p) => p.id), [orderedProducts]);
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }));

  function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    const fromIdx = orderedProducts.findIndex((p) => p.id === active.id);
    const toIdx = orderedProducts.findIndex((p) => p.id === over.id);
    if (fromIdx < 0 || toIdx < 0) return;
    setDraftIds((prev) => arrayMove(prev, fromIdx, toIdx));
  }

  function addProduct(productId: string) {
    if (assignedIds.has(productId)) return;
    setDraftIds((prev) => [...prev, productId]);
  }

  function removeProduct(productId: string) {
    setDraftIds((prev) => prev.filter((id) => id !== productId));
  }

  function handleCatalogDragStart(e: React.DragEvent<HTMLDivElement>, productId: string) {
    e.dataTransfer.setData("application/x-floating-section-product-id", productId);
    e.dataTransfer.effectAllowed = "copy";
  }

  function handlePanelDragOver(e: React.DragEvent<HTMLDivElement>) {
    if (e.dataTransfer.types.includes("application/x-floating-section-product-id")) {
      e.preventDefault();
      e.dataTransfer.dropEffect = "copy";
    }
  }

  function handlePanelDrop(e: React.DragEvent<HTMLDivElement>) {
    const productId = e.dataTransfer.getData("application/x-floating-section-product-id");
    if (productId) {
      e.preventDefault();
      addProduct(productId);
    }
  }

  const handleCancel = useCallback(() => {
    const ids = JSON.parse(originalSnapshot) as string[];
    setDraftIds(ids);
  }, [originalSnapshot]);

  const handleSave = useCallback(async () => {
    setIsSaving(true);
    try {
      const originalIds: string[] = JSON.parse(originalSnapshot);
      const originalSet = new Set(originalIds);
      const draftSet = new Set(draftIds);

      const toAdd = draftIds.filter((id) => !originalSet.has(id));
      const toRemove = originalIds.filter((id) => !draftSet.has(id));

      if (toRemove.length > 0) {
        for (const productId of toRemove) {
          const row = fsProductByProductId.get(productId);
          if (row) await removeMutation.mutateAsync({ sectionId, productId: row.id });
        }
      }

      let addedRows: FloatingSectionProduct[] = [];
      if (toAdd.length > 0) {
        const result = await addMutation.mutateAsync({
          sectionId,
          data: { product_ids: toAdd },
        });
        addedRows = result.data ?? [];
      }

      // Reorder using the authoritative row set the server just returned. We
      // re-fetch first so `ordered_ids` is derived from the live product rows
      // (the reorder endpoint 422s unless it covers EVERY row) rather than a
      // possibly-stale cache — otherwise a row the cache didn't know about
      // (e.g. a product already present server-side, which add() skips and
      // doesn't echo back) would be missing from ordered_ids and 422 the call.
      const refreshed = await refetch();
      const liveRows = refreshed.data?.data.products ?? [];
      const rowIdByProductId = new Map(liveRows.map((p) => [p.product_id, p.id]));
      const addedByProductId = new Map(addedRows.map((p) => [p.product_id, p.id]));
      const orderedRowIds = draftIds
        .map((productId) => rowIdByProductId.get(productId) ?? addedByProductId.get(productId))
        .filter((id): id is string => !!id);

      // Only reorder when we have an id for every draft product — a partial
      // list would 422. If something is missing (a rare cross-session drift),
      // skip the reorder rather than blow up; the add/remove already persisted.
      if (orderedRowIds.length === draftIds.length && orderedRowIds.length > 0) {
        await reorderMutation.mutateAsync({ sectionId, data: { ordered_ids: orderedRowIds } });
      }

      // Rebuild the snapshot from the server-confirmed order so a follow-up
      // save diffs against reality, not the pre-save draft. A second refetch
      // lets the reorder result land in the cache the draft syncs from.
      const finalRes = await refetch();
      const finalIds = (finalRes.data?.data.products ?? [])
        .slice()
        .sort((a, b) => a.display_order - b.display_order)
        .map((p) => p.product_id);
      setDraftIds(finalIds);
      setOriginalSnapshot(JSON.stringify(finalIds));
    } catch {
      // A mutation failed mid-way (partial save). Re-sync the draft to the
      // server's actual state so the UI reflects what really persisted and a
      // retry diffs cleanly instead of re-adding/re-removing. The failing
      // mutation's own onError toast already told the user.
      const res = await refetch();
      const serverIds = (res.data?.data.products ?? [])
        .slice()
        .sort((a, b) => a.display_order - b.display_order)
        .map((p) => p.product_id);
      setDraftIds(serverIds);
      setOriginalSnapshot(JSON.stringify(serverIds));
    } finally {
      setIsSaving(false);
    }
  }, [originalSnapshot, draftIds, fsProductByProductId, removeMutation, addMutation, reorderMutation, refetch, sectionId]);

  // Keep the latest handlers in a ref so the state report to the parent only
  // fires on primitive (hasChanges / isSaving) changes. Passing the callbacks
  // directly in the effect deps caused an update loop: every parent re-render
  // gave `data` a fresh reference → new fsProductByProductId → new handleSave
  // → effect re-ran → setState on the parent → repeat.
  const handlersRef = useRef({ handleSave, handleCancel });
  useEffect(() => {
    handlersRef.current = { handleSave, handleCancel };
  }, [handleSave, handleCancel]);

  useEffect(() => {
    onStateChange?.({
      hasChanges,
      isSaving,
      onSave: () => void handlersRef.current.handleSave(),
      onCancel: () => handlersRef.current.handleCancel(),
    });
  }, [hasChanges, isSaving, onStateChange]);

  function openEditPrice(productId: string, sku: FloatingSectionProductSku) {
    // Close the SKU list dialog first — two Radix Dialogs open at once (this
    // one nested inside SkuDetailDialog) broke focus/layout, rendering the
    // price input inline instead of in its own centered dialog.
    setDetailProductRowId(null);
    setEditDraft(String(sku.selling_price ?? 0));
    setEditingSku({ productId, sku });
  }

  function closeEditPrice() {
    const productId = editingSku?.productId ?? null;
    setEditingSku(null);
    if (productId) setDetailProductRowId(productId);
  }

  function handleSavePrice() {
    const next = Number(editDraft);
    if (!editingSku || !Number.isFinite(next) || next < 0) return;
    overridePriceMutation.mutate(
      { sectionId, productId: editingSku.productId, skuId: editingSku.sku.id, sellingPrice: next },
      { onSettled: closeEditPrice }
    );
  }

  if (isLoading) {
    return (
      <div className="flex min-h-0 flex-1 items-center justify-center">
        <Spinner className="size-5 text-muted-foreground" />
      </div>
    );
  }

  if (isError) {
    return (
      <div className="p-4">
        <Alert variant="destructive" className="py-2">
          <AlertTitle className="text-xs">{t("common.error_loading")}</AlertTitle>
          <AlertDescription>
            <Button
              variant="outline"
              size="sm"
              className="mt-1 h-6 text-xs"
              onClick={() => refetch()}
            >
              {t("common.retry")}
            </Button>
          </AlertDescription>
        </Alert>
      </div>
    );
  }

  return (
    <div className="flex min-h-0 flex-1">
      {/* Left panel: product catalog — mirrors menus/[menuId]/items/page.tsx */}
      <div className="flex w-[280px] shrink-0 flex-col border-r bg-background">
        <div className="space-y-2 border-b p-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-1.5 text-sm font-medium text-foreground">
              <Package className="size-3.5 text-primary" />
              {t("hq.menus.items.products_count", { n: available.length })}
            </div>
            {catalogLoading && <Spinner className="size-3.5 text-muted-foreground" />}
          </div>
          <div className="relative">
            <Search className="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder={t("hq.floating_sections.products.search_placeholder")}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="h-8 pl-8 text-sm"
            />
          </div>
          <Select value={categoryFilter} onValueChange={(v) => setCategoryFilter(v ?? "__all__")}>
            <SelectTrigger className="h-7 text-xs">
              <SelectValue placeholder={t("hq.menus.items.all_categories")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__all__">{t("hq.menus.items.all_categories")}</SelectItem>
              {categories.map((cat) => (
                <SelectItem key={cat.id} value={cat.id}>
                  {cat.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select value={productTypeFilter} onValueChange={(v) => setProductTypeFilter(v ?? "__all__")}>
            <SelectTrigger className="h-7 text-xs">
              <SelectValue placeholder={t("hq.menus.items.all_product_types")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__all__">{t("hq.menus.items.all_product_types")}</SelectItem>
              {productTypes.map((type) => (
                <SelectItem key={type.id} value={type.id}>
                  {type.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <p className="text-[10px] text-muted-foreground">
            {t("hq.floating_sections.products.drag_hint")}
          </p>
        </div>
        <div className="flex-1 space-y-1 overflow-y-auto p-2">
          {available.map((product) => (
            <CatalogRow
              key={product.id}
              product={product}
              onClick={() => setCatalogDetailProductId(product.id)}
              onDragStart={(e) => handleCatalogDragStart(e, product.id)}
            />
          ))}
          {available.length === 0 && !catalogLoading && (
            <div className="flex flex-col items-center justify-center py-8 text-center text-sm text-muted-foreground">
              <Package className="mb-2 size-8 text-muted-foreground/40" />
              {t("hq.menus.items.no_products")}
            </div>
          )}
        </div>
      </div>

      {/* Right panel: products already in this floating section */}
      <div
        className="min-w-0 flex-1 overflow-y-auto p-4"
        onDragOver={handlePanelDragOver}
        onDrop={handlePanelDrop}
      >
        <div className="rounded-md border bg-card">
          <div className="flex items-center justify-between border-b px-3 py-2">
            <div className="flex items-center gap-2 text-sm font-semibold">
              <UtensilsCrossed className="size-3.5 text-primary" />
              {t("hq.floating_sections.products.tab")}
              <Badge variant="outline" className="h-5 text-[10px]">
                {orderedProducts.length}
              </Badge>
            </div>
          </div>

          <div className="space-y-1 p-2">
            {orderedProducts.length === 0 ? (
              <div className="flex flex-col items-center justify-center py-8 text-center text-xs text-muted-foreground">
                {t("hq.floating_sections.products.empty_desc")}
              </div>
            ) : (
              <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
                <SortableContext items={sortableIds} strategy={verticalListSortingStrategy}>
                  {orderedProducts.map((p) => (
                    <SortableProductRow
                      key={p.id}
                      p={p}
                      product={productMap.get(p.product_id)}
                      taxTypes={taxTypes}
                      onOpenDetail={() => setDetailProductRowId(p.id)}
                      onRemove={() => removeProduct(p.product_id)}
                      onTaxChange={(taxTypeId) =>
                        updateTaxType.mutate({ sectionId, productId: p.id, taxTypeId })
                      }
                    />
                  ))}
                </SortableContext>
              </DndContext>
            )}
          </div>
        </div>
      </div>

      {catalogDetailProductId && (
        <CatalogProductDetailDialog
          brandSlug={brandSlug}
          productId={catalogDetailProductId}
          open={!!catalogDetailProductId}
          onOpenChange={(o) => {
            if (!o) setCatalogDetailProductId(null);
          }}
        />
      )}

      {detailProduct && (
        <SkuDetailDialog
          open={!!detailProduct}
          onOpenChange={(o) => {
            if (!o) setDetailProductRowId(null);
          }}
          productName={
            productMap.get(detailProduct.product_id)?.name ??
            detailProduct.product?.name ??
            t("hq.menus.items.untitled")
          }
          productType={
            productMap.get(detailProduct.product_id)?.productType?.name ??
            detailProduct.product?.productType?.name ??
            null
          }
          isActive={detailProduct.is_active}
          skus={detailProduct.skus ?? []}
          productId={detailProduct.id}
          openEditPrice={openEditPrice}
        />
      )}

      <Dialog
        open={!!editingSku}
        onOpenChange={(o) => {
          if (!o && !overridePriceMutation.isPending) closeEditPrice();
        }}
      >
        <DialogContent className="sm:max-w-xs">
          <DialogHeader>
            <DialogTitle>{t("shop.menu.detail.edit_price_title")}</DialogTitle>
            <DialogDescription>
              {editingSku
                ? t("shop.menu.detail.edit_price_description_sku", {
                    sku: editingSku.sku.productSku?.sku ?? "SKU",
                  })
                : t("shop.menu.detail.edit_price_description")}
            </DialogDescription>
          </DialogHeader>
          <Input
            type="number"
            inputMode="decimal"
            min={0}
            step="0.01"
            autoFocus
            value={editDraft}
            onChange={(e) => setEditDraft(e.target.value)}
            onFocus={(e) => e.currentTarget.select()}
            onKeyDown={(e) => {
              if (e.key === "Enter" && !e.nativeEvent.isComposing) handleSavePrice();
              if (e.key === "Escape") closeEditPrice();
            }}
            disabled={overridePriceMutation.isPending}
            className="text-right tabular-nums"
          />
          <DialogFooter>
            {editingSku?.sku.is_price_overridden && (
              <Button
                variant="outline"
                size="sm"
                onClick={() =>
                  resetPriceMutation.mutate(
                    { sectionId, productId: editingSku.productId, skuId: editingSku.sku.id },
                    { onSettled: closeEditPrice }
                  )
                }
                disabled={overridePriceMutation.isPending || resetPriceMutation.isPending}
              >
                <RotateCcw className="mr-1.5 size-3.5" />
                {t("shop.menu.detail.reset_to_default")}
              </Button>
            )}
            <Button
              variant="outline"
              size="sm"
              onClick={closeEditPrice}
              disabled={overridePriceMutation.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button size="sm" onClick={handleSavePrice} disabled={overridePriceMutation.isPending}>
              {overridePriceMutation.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("shop.menu.detail.overwrite_price.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
