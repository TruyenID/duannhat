"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { resolveNoVariantPriceRow } from "@/lib/toppingPriceRow";
import Image from "next/image";
import { useParams, useRouter } from "next/navigation";
import {
  Save,
  Trash2,
  Settings2,
  Search,
  Package,
  ImageIcon,
  GripVertical,
  Maximize2,
  PlayCircle,
  PauseCircle,
} from "lucide-react";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
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
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  Spinner,
  StatusBadge,
} from "@godxjp/ui";
import { cn } from "@/lib/utils";
import { ImageLightbox } from "@/components/shared/image-lightbox";
import {
  DndContext,
  DragOverlay,
  PointerSensor,
  useDraggable,
  useDroppable,
  useSensor,
  useSensors,
  closestCenter,
  type DragStartEvent,
  type DragEndEvent,
} from "@dnd-kit/core";
import {
  SortableContext,
  useSortable,
  verticalListSortingStrategy,
  arrayMove,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { ApiError } from "@/lib/api";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import { useTranslation } from "@/providers/app-provider";
import { currencySymbol, currencySymbolPosition, formatPriceAmount } from "@/lib/currency";
import {
  useToppingGroup,
  useUpdateToppingGroup,
  useDeleteToppingGroup,
  useToppingGroupItems,
  useSyncToppingGroupItems,
  useToppingGroupItemSkus,
  useAddToppingGroupItemSku,
  useUpdateToppingGroupItemSku,
  useDeleteToppingGroupItemSku,
} from "@/hooks/api/use-topping-groups";
import { useAllProducts, useProduct } from "@/hooks/api/use-products";
import { useProductTypeLookup } from "@/hooks/api/use-product-types";
import { useCategoryLookup } from "@/hooks/api/use-categories";
import type {
  ToppingGroupItem,
  ToppingGroupItemSku,
  UpdateToppingGroupInput,
} from "@/services/topping-group-service";
import type { Product, ProductSku } from "@/services/product-service";

// ─────────────────────────────────────────────────────────────────────────────
// SKU Price Sheet (per item) — redesigned
// ─────────────────────────────────────────────────────────────────────────────

interface SkuPriceSheetProps {
  open: boolean;
  onClose: () => void;
  brandSlug: string;
  groupId: string;
  item: ToppingGroupItem;
}

function SkuPriceSheet({ open, onClose, brandSlug, groupId, item }: SkuPriceSheetProps) {
  const { t } = useTranslation();

  const skusQuery = useToppingGroupItemSkus(brandSlug, groupId, item.id);
  const priceOverrides = skusQuery.data?.data ?? [];

  const productQuery = useProduct(brandSlug, item.product_id);
  const product = productQuery.data?.data;

  const addSku = useAddToppingGroupItemSku(brandSlug, groupId, item.id);
  const updateSku = useUpdateToppingGroupItemSku(brandSlug, groupId, item.id);
  const deleteSku = useDeleteToppingGroupItemSku(brandSlug, groupId, item.id);

  const isLoading = skusQuery.isLoading || productQuery.isLoading;
  const productName = item.product?.name ?? product?.name ?? item.product_id;
  const hasVariants = (product?.options?.length ?? 0) > 0;
  const activeProductSkus = (product?.skus ?? []).filter((s) => s.is_active);
  const mutationPending = addSku.isPending || updateSku.isPending || deleteSku.isPending;

  function handleSave(productSkuId: string | null, price: number, overrideId: string | null) {
    if (overrideId) {
      updateSku.mutate({ itemSkuId: overrideId, extra_price: price });
    } else {
      addSku.mutate({ product_sku_id: productSkuId, extra_price: price });
    }
  }

  return (
    <Sheet open={open} onOpenChange={(v) => !v && onClose()}>
      <SheetContent className="flex w-full flex-col overflow-hidden sm:max-w-lg">
        <SheetHeader className="shrink-0 border-b pb-3">
          <SheetTitle className="text-base">{productName}</SheetTitle>
          <p className="text-xs text-muted-foreground">
            {hasVariants
              ? t("hq.topping_groups.items.sku_price_by_variant")
              : t("hq.topping_groups.items.sku_price_default")}
          </p>
        </SheetHeader>

        <div className="flex-1 overflow-y-auto py-4">
          {isLoading ? (
            <div className="flex items-center justify-center py-10">
              <Spinner className="size-4" />
            </div>
          ) : hasVariants ? (
            <VariantPriceTable
              productSkus={activeProductSkus}
              priceOverrides={priceOverrides}
              onSave={handleSave}
              onDelete={(overrideId) => deleteSku.mutate(overrideId)}
              isPending={mutationPending}
              t={t}
            />
          ) : (
            <DefaultPriceEditor
              // A no-variant item's price row may be scoped to the product's
              // sole SKU or stored as the wildcard (product_sku_id null) row
              // — resolveSnapshotPrice/wildcardPriceApplies treat a scoped
              // row for that SKU as authoritative either way. Prefer the
              // scoped row so an existing price is recognized as an edit,
              // not mistaken for "no price yet" and resubmitted as a new
              // wildcard row (which the backend then rejects as dead data).
              override={resolveNoVariantPriceRow(priceOverrides, activeProductSkus[0]?.id)}
              sellingPrice={activeProductSkus[0]?.selling_price ?? null}
              onSave={(price, overrideId) => handleSave(activeProductSkus[0]?.id ?? null, price, overrideId)}
              isPending={mutationPending}
              t={t}
            />
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
}

function VariantPriceTable({
  productSkus,
  priceOverrides,
  onSave,
  onDelete,
  isPending,
  t,
}: {
  productSkus: ProductSku[];
  priceOverrides: ToppingGroupItemSku[];
  onSave: (productSkuId: string | null, price: number, overrideId: string | null) => void;
  onDelete: (overrideId: string) => void;
  isPending: boolean;
  t: (key: string) => string;
}) {
  if (productSkus.length === 0) {
    return (
      <p className="py-8 text-center text-sm text-muted-foreground">
        {t("hq.topping_groups.items.skus_empty")}
      </p>
    );
  }

  return (
    <div className="overflow-hidden rounded-md border">
      <div className="grid grid-cols-[1fr_9rem_2rem] gap-2 border-b bg-muted/40 px-3 py-2 text-xs font-medium text-muted-foreground">
        <span>{t("hq.topping_groups.items.col.variant")}</span>
        <span className="text-right">{t("hq.topping_groups.items.col.extra_price")}</span>
        <span />
      </div>
      {productSkus.map((productSku) => {
        const combo = [productSku.option_value1, productSku.option_value2, productSku.option_value3]
          .filter(Boolean)
          .map((v) => v!.label)
          .join(" / ");
        const label = combo || productSku.sku || productSku.name || "SKU";
        const override = priceOverrides.find((o) => o.product_sku_id === productSku.id);
        return (
          <VariantPriceRow
            key={productSku.id}
            productSkuId={productSku.id}
            label={label}
            skuCode={productSku.sku}
            sellingPrice={productSku.selling_price}
            override={override}
            onSave={onSave}
            onDelete={onDelete}
            isPending={isPending}
          />
        );
      })}
    </div>
  );
}

function VariantPriceRow({
  productSkuId,
  label,
  skuCode,
  sellingPrice,
  override,
  onSave,
  onDelete,
  isPending,
}: {
  productSkuId: string;
  label: string;
  skuCode: string | null;
  sellingPrice: string;
  override: ToppingGroupItemSku | undefined;
  onSave: (productSkuId: string | null, price: number, overrideId: string | null) => void;
  onDelete: (overrideId: string) => void;
  isPending: boolean;
}) {
  const { locale } = useTranslation();
  const [price, setPrice] = useState(override ? String(Number(override.extra_price)) : "");

  useEffect(() => {
    setPrice(override ? String(Number(override.extra_price)) : "");
  }, [override]);

  function handleBlur() {
    const n = Number(price);
    if (Number.isNaN(n)) return;
    if (!price.trim() && !override) return;
    if (override && n === Number(override.extra_price)) return;
    onSave(productSkuId, n, override?.id ?? null);
  }

  return (
    <div className="grid grid-cols-[1fr_9rem_2rem] items-center gap-2 border-b px-3 py-2.5 last:border-b-0">
      <div className="min-w-0">
        <span className="block truncate text-sm font-medium">{label}</span>
        <div className="flex items-center gap-1.5">
          {skuCode && <code className="text-[10px] text-muted-foreground">{skuCode}</code>}
          {sellingPrice && Number(sellingPrice) > 0 && (
            <span className="text-[10px] text-muted-foreground">
              {formatPriceAmount(sellingPrice, locale)}
            </span>
          )}
        </div>
      </div>
      <Input
        type="number"
        step="0.01"
        value={price}
        onChange={(e) => setPrice(e.target.value)}
        onBlur={handleBlur}
        placeholder="0"
        className="h-8 w-full text-right text-xs tabular-nums"
        disabled={isPending}
      />
      <Button
        variant="ghost"
        size="icon"
        className={`size-7 ${override ? "text-muted-foreground hover:text-destructive" : "invisible"}`}
        onClick={() => override && onDelete(override.id)}
        disabled={isPending || !override}
      >
        <Trash2 className="size-3.5" />
      </Button>
    </div>
  );
}

function DefaultPriceEditor({
  override,
  sellingPrice,
  onSave,
  isPending,
  t,
}: {
  override: ToppingGroupItemSku | null;
  sellingPrice: string | null;
  onSave: (price: number, overrideId: string | null) => void;
  isPending: boolean;
  t: (key: string) => string;
}) {
  const { locale } = useTranslation();
  const [price, setPrice] = useState(override ? String(Number(override.extra_price)) : "0");

  useEffect(() => {
    setPrice(override ? String(Number(override.extra_price)) : "0");
  }, [override]);

  function handleBlur() {
    const n = Number(price);
    if (Number.isNaN(n)) return;
    if (override && n === Number(override.extra_price)) return;
    onSave(n, override?.id ?? null);
  }

  return (
    <div className="rounded-md border p-4">
      <div className="mb-3 flex items-baseline justify-between">
        <span className="text-sm font-medium">{t("hq.topping_groups.items.col.extra_price")}</span>
        {sellingPrice && Number(sellingPrice) > 0 && (
          <span className="text-xs text-muted-foreground">
            {t("hq.topping_groups.items.col.base_price")}:{" "}
            {formatPriceAmount(sellingPrice, locale)}
          </span>
        )}
      </div>
      <div className="flex items-center gap-2">
        <Input
          type="number"
          step="0.01"
          value={price}
          onChange={(e) => setPrice(e.target.value)}
          onBlur={handleBlur}
          placeholder="0"
          className="h-9 flex-1 text-sm tabular-nums"
          disabled={isPending}
        />
        <span
          className={`shrink-0 text-sm text-muted-foreground ${
            currencySymbolPosition(locale) === "prefix" ? "order-first" : ""
          }`}
        >
          {currencySymbol(locale)}
        </span>
        {isPending && <Spinner className="size-4 shrink-0" />}
      </div>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Product detail dialog
// ─────────────────────────────────────────────────────────────────────────────

function VariantThumbnail({
  src,
  alt,
  onZoom,
}: {
  src: string | null | undefined;
  alt: string;
  onZoom: (src: string, alt: string) => void;
}) {
  if (!src) {
    return (
      <div className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
        <ImageIcon className="size-3.5 text-muted-foreground" />
      </div>
    );
  }
  return (
    <button
      type="button"
      onClick={() => onZoom(src, alt)}
      className="group relative size-10 shrink-0 cursor-zoom-in overflow-hidden rounded bg-muted"
    >
      <Image src={src} alt={alt} width={40} height={40} className="h-full w-full object-cover" />
      <span className="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/0 transition-colors group-hover:bg-black/40">
        <Maximize2 className="size-3.5 text-white opacity-0 transition-opacity group-hover:opacity-100" />
      </span>
    </button>
  );
}

function ProductDetailDialog({
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

  const [lightbox, setLightbox] = useState<{ src: string; alt: string } | null>(null);
  const handleZoom = useCallback((src: string, alt: string) => setLightbox({ src, alt }), []);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        aria-describedby={undefined}
        className="sm:max-w-lg"
        onOpenAutoFocus={(e) => e.preventDefault()}
      >
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
                    const skuImage =
                      sku.image_url ??
                      sku.gallery?.[0]?.url ??
                      (isSimpleSku ? (product.image_url ?? null) : null);

                    return (
                      <div
                        key={sku.id}
                        className={`flex items-center gap-2.5 px-3 py-2.5 text-xs ${
                          !sku.is_active ? "opacity-50" : ""
                        }`}
                      >
                        <VariantThumbnail src={skuImage} alt={label} onZoom={handleZoom} />
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

      <ImageLightbox
        src={lightbox?.src ?? null}
        alt={lightbox?.alt ?? ""}
        open={!!lightbox}
        onOpenChange={(o) => {
          if (!o) setLightbox(null);
        }}
      />
    </Dialog>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Left panel: draggable product card
// ─────────────────────────────────────────────────────────────────────────────

function DraggableProductCard({
  product,
  isAdded,
  onClick,
}: {
  product: Product;
  isAdded: boolean;
  onClick: () => void;
}) {
  const { t } = useTranslation();
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({
    id: `product:${product.id}`,
    data: { product },
    disabled: isAdded,
  });

  return (
    <div
      ref={setNodeRef}
      {...attributes}
      {...listeners}
      onClick={onClick}
      className={`flex items-center gap-2.5 rounded-md border bg-card p-2 transition-all select-none ${
        isAdded
          ? "cursor-pointer opacity-60 hover:border-primary/30"
          : "cursor-grab hover:border-primary/40 hover:shadow-sm active:cursor-grabbing"
      } ${isDragging ? "opacity-30" : ""}`}
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
        <span className="truncate text-sm font-medium">{product.name}</span>
        {isAdded ? (
          <span className="text-[10px] text-primary">{t("hq.topping_groups.items.added")}</span>
        ) : (
          <span className="text-[11px] text-muted-foreground">
            {t("hq.topping_groups.items.variant_count", { n: product.active_skus_count ?? 0 })}
          </span>
        )}
      </div>
      {!isAdded && <GripVertical className="size-3.5 shrink-0 text-muted-foreground/50" />}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Main edit page
// ─────────────────────────────────────────────────────────────────────────────

export default function EditToppingGroupPage() {
  const params = useParams<{ brandSlug: string; groupId: string }>();
  const brandSlug = params.brandSlug;
  const groupId = params.groupId;
  const router = useRouter();
  const { t } = useTranslation();

  // ── Items state ─────────────────────────────────────────────────────────
  const [skuSheetItem, setSkuSheetItem] = useState<ToppingGroupItem | null>(null);
  const [localItems, setLocalItems] = useState<ToppingGroupItem[]>([]);

  // ── Group delete state ──────────────────────────────────────────────────
  const [deleteGroupOpen, setDeleteGroupOpen] = useState(false);
  const [inUseProducts, setInUseProducts] = useState<{ id: string; name: string }[] | null>(null);

  // ── Left panel filter + detail state ────────────────────────────────────
  const [searchTerm, setSearchTerm] = useState("");
  const [categoryFilter, setCategoryFilter] = useState<string>("__all__");
  const [productTypeFilter, setProductTypeFilter] = useState<string>("__all__");
  const [detailProductId, setDetailProductId] = useState<string | null>(null);

  // ── Queries ─────────────────────────────────────────────────────────────
  const groupQuery = useToppingGroup(brandSlug, groupId);
  const group = groupQuery.data?.data;

  const itemsQuery = useToppingGroupItems(brandSlug, groupId);
  const items = itemsQuery.data?.data ?? [];

  // Sync local order when server data changes (add/remove/initial load)
  useEffect(() => {
    if (itemsQuery.data) setLocalItems(itemsQuery.data.data ?? []);
  }, [itemsQuery.data]);

  const productsQuery = useAllProducts(brandSlug, {
    sort: "name",
    status: "active",
  });
  const products = useMemo<Product[]>(() => productsQuery.data ?? [], [productsQuery.data]);

  const categoriesQuery = useCategoryLookup(brandSlug);
  const categories = useMemo(() => categoriesQuery.data?.data ?? [], [categoriesQuery.data]);

  const productTypesQuery = useProductTypeLookup(brandSlug);
  const productTypes = useMemo(() => productTypesQuery.data?.data ?? [], [productTypesQuery.data]);

  // ── Mutations ────────────────────────────────────────────────────────────
  const updateMutation = useUpdateToppingGroup(brandSlug);
  const deleteGroup = useDeleteToppingGroup(brandSlug);
  const syncItems = useSyncToppingGroupItems(brandSlug, groupId);

  const disabledAll = updateMutation.isPending || deleteGroup.isPending || syncItems.isPending;

  // ── Staged (unsaved) product panel ────────────────────────────────────────
  // Drag-in / remove / reorder mutate `localItems` ONLY. Nothing hits the
  // backend until "Lưu" fires one bulk sync — mirrors the menu-items page.
  const savedProductIds = useMemo(() => items.map((i) => i.product_id).join("|"), [items]);
  const stagedProductIds = useMemo(() => localItems.map((i) => i.product_id), [localItems]);
  // Dirty on ANY change to the product set — a product dragged in OR one removed.
  const isDirty = stagedProductIds.join("|") !== savedProductIds;

  const [confirmExitOpen, setConfirmExitOpen] = useState(false);

  async function handleSaveItems(): Promise<boolean> {
    try {
      await syncItems.mutateAsync(stagedProductIds);
      return true;
    } catch {
      /* hook toasts the error; localItems stays so the user can retry */
      return false;
    }
  }

  const backHref = `/hq/${brandSlug}/topping-groups`;

  /** Discard staged add/remove/reorder — snap the panel back to the saved set. */
  function handleRevert() {
    setLocalItems(items);
  }

  function handleRequestExit() {
    if (isDirty) {
      setConfirmExitOpen(true);
      return;
    }
    router.push(backHref);
  }

  // General-group fields (name, selection/modifier, min/max, price strategy) are
  // edited from the list-page modal (CreateToppingGroupDialog in edit mode).
  // This detail page is now dedicated to managing the group's PRODUCTS, so it
  // keeps only the active toggle + delete as group-level actions.
  async function handleToggleActive() {
    if (!group) return;
    try {
      await updateMutation.mutateAsync({
        id: group.id,
        data: { is_active: !group.is_active } as UpdateToppingGroupInput,
      });
    } catch {
      /* mutation surfaces its own error toast; group state stays authoritative */
    }
  }

  async function handleDeleteGroup() {
    if (!group) return;
    try {
      await deleteGroup.mutateAsync(group.id);
      setDeleteGroupOpen(false);
      // #1214 — was `/catalog/topping-groups`, a leftover of an older URL
      // shape. There is no `catalog/` segment in the app router, so deleting a
      // group landed the user on a 404 with the deletion already done. The
      // three other links in this file already used the correct path.
      router.push(`/hq/${brandSlug}/topping-groups`);
    } catch (err) {
      setDeleteGroupOpen(false);
      if (err instanceof ApiError && err.status === 409) {
        const body = err.body as {
          error?: string;
          used_by?: { id: string; name: string }[];
        };
        if (body.error === "TOPPING_GROUP_IN_USE") {
          setInUseProducts(body.used_by ?? []);
        }
      }
      // Other errors are toasted by the hook.
    }
  }

  // ── Left panel derived data ───────────────────────────────────────────────
  // Derive from the STAGED panel so a just-dragged product immediately shows as
  // "added" and can't be dragged in twice before saving.
  const addedProductIds = useMemo(
    () => new Set(localItems.map((i) => i.product_id)),
    [localItems]
  );

  const availableProducts = useMemo(() => {
    return products.filter((p) => {
      if (categoryFilter !== "__all__" && !p.categories?.some((c) => c.id === categoryFilter))
        return false;
      if (productTypeFilter !== "__all__" && p.product_type_id !== productTypeFilter) return false;
      if (searchTerm) {
        const q = searchTerm.toLowerCase();
        if (!p.name?.toLowerCase().includes(q)) return false;
      }
      return true;
    });
  }, [products, categoryFilter, productTypeFilter, searchTerm]);

  // ── DnD ──────────────────────────────────────────────────────────────────
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }));
  const [activeDragProduct, setActiveDragProduct] = useState<Product | null>(null);

  const handleDragStart = useCallback((event: DragStartEvent) => {
    const data = event.active.data.current as { product: Product } | undefined;
    if (data?.product) setActiveDragProduct(data.product);
  }, []);

  const handleDragEnd = useCallback(
    (event: DragEndEvent) => {
      const { active, over } = event;
      setActiveDragProduct(null);

      // Case 1: drop a product from the left panel into the group — STAGED only.
      const productData = active.data.current as { product: Product } | undefined;
      if (productData?.product) {
        if (!over) return;
        const overId = String(over.id);
        const isDropZone = overId === "topping-group-drop-zone";
        const isOverItem = localItems.some((i) => i.id === overId);
        if (!isDropZone && !isOverItem) return;

        const product = productData.product;
        if (addedProductIds.has(product.id)) return;

        // A temporary client-side row; the real id/order is assigned by the
        // backend on save. `staged-` marks it so nothing tries to fetch it.
        const stagedItem: ToppingGroupItem = {
          id: `staged-${product.id}`,
          topping_group_id: groupId,
          product_id: product.id,
          product: { id: product.id, name: product.name, image_url: product.image_url ?? null },
          sort_order: localItems.length,
          created_at: "",
          updated_at: "",
          deleted_at: null,
        };
        setLocalItems((prev) => [...prev, stagedItem]);
        return;
      }

      // Case 2: reorder within the panel — STAGED only.
      if (!over || active.id === over.id) return;
      setLocalItems((prev) => {
        const from = prev.findIndex((i) => i.id === active.id);
        const to = prev.findIndex((i) => i.id === over.id);
        if (from < 0 || to < 0) return prev;
        return arrayMove(prev, from, to);
      });
    },
    [addedProductIds, localItems, groupId]
  );

  /** Remove a staged/saved product from the panel — client-side until save. */
  const handleRemoveItem = useCallback((item: ToppingGroupItem) => {
    setLocalItems((prev) => prev.filter((i) => i.id !== item.id));
  }, []);

  // ── Loading / error states ───────────────────────────────────────────────
  if (groupQuery.isLoading || !group) {
    return (
      <>
        <PageHeader
          title={t("hq.topping_groups.edit_title")}
          description={t("hq.topping_groups.subtitle")}
          backHref={`/hq/${brandSlug}/topping-groups`}
        >
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8"
            onClick={() => router.push(`/hq/${brandSlug}/topping-groups`)}
          >
            {t("common.cancel")}
          </Button>
        </PageHeader>
        <PageContent>
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Spinner className="size-4" />
            {t("common.loading")}
          </div>
        </PageContent>
      </>
    );
  }

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCenter}
      onDragStart={handleDragStart}
      onDragEnd={handleDragEnd}
      onDragCancel={() => setActiveDragProduct(null)}
    >
      <PageHeader
        title={group.name || t("hq.topping_groups.edit_title")}
        description={t("hq.topping_groups.subtitle")}
        onBack={handleRequestExit}
        titleBadge={
          isDirty ? (
            <Badge
              variant="outline"
              className="h-5 border-amber-300 bg-amber-50 text-[10px] text-amber-600"
            >
              {t("hq.topping_groups.items.unsaved")}
            </Badge>
          ) : undefined
        }
      >
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8"
          onClick={handleRevert}
          disabled={!isDirty || disabledAll}
        >
          {t("common.cancel")}
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className={cn(
            "h-8 gap-1.5",
            group.is_active
              ? "border-green-400 text-green-700 hover:bg-green-50"
              : "text-muted-foreground"
          )}
          onClick={handleToggleActive}
          disabled={disabledAll}
        >
          {updateMutation.isPending ? (
            <Spinner className="size-3.5" />
          ) : group.is_active ? (
            <PauseCircle className="size-3.5" />
          ) : (
            <PlayCircle className="size-3.5" />
          )}
          {group.is_active ? t("status.active") : t("status.inactive")}
        </Button>
        {!group.deleted_at && (
          <Button
            type="button"
            variant="destructive"
            size="sm"
            className="h-8 gap-1.5"
            onClick={() => setDeleteGroupOpen(true)}
            disabled={disabledAll}
          >
            <Trash2 className="size-3.5" />
            {t("common.delete")}
          </Button>
        )}
        <Button
          type="button"
          size="sm"
          className="h-8 gap-1.5"
          onClick={handleSaveItems}
          disabled={!isDirty || disabledAll}
        >
          {syncItems.isPending ? <Spinner className="size-3.5" /> : <Save className="size-3.5" />}
          {t("common.save")}
        </Button>
      </PageHeader>

      <PageContent>
        <div className="flex flex-col gap-4">
          {/* Section B: Two-panel product picker */}
          <div className="overflow-hidden rounded-lg border">
            <div className="flex items-center justify-between border-b bg-muted/30 px-4 py-2">
              <span className="text-sm font-semibold">{t("hq.topping_groups.items.title")}</span>
              <Badge variant="outline" className="text-[10px]">
                {items.length}
              </Badge>
            </div>

            <div className="flex h-[calc(100vh-11rem)] min-h-100">
              {/* ── Left panel: product catalog ── */}
              <div className="flex w-75 shrink-0 flex-col border-r bg-background">
                <div className="space-y-2 border-b p-3">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                      <Package className="size-3.5 text-primary" />
                      {t("hq.topping_groups.items.products_count", {
                        n: availableProducts.length,
                      })}
                    </div>
                    {productsQuery.isLoading && (
                      <Spinner className="size-3.5 text-muted-foreground" />
                    )}
                  </div>
                  <div className="relative">
                    <Search className="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                    <Input
                      placeholder={t("hq.topping_groups.items.search_placeholder")}
                      value={searchTerm}
                      onChange={(e: React.ChangeEvent<HTMLInputElement>) =>
                        setSearchTerm(e.target.value)
                      }
                      className="h-8 pl-8 text-sm"
                    />
                  </div>
                  <Select
                    value={categoryFilter}
                    onValueChange={(v) => setCategoryFilter(v ?? "__all__")}
                  >
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
                  <Select
                    value={productTypeFilter}
                    onValueChange={(v) => setProductTypeFilter(v ?? "__all__")}
                  >
                    <SelectTrigger className="h-7 text-xs">
                      <SelectValue placeholder={t("hq.topping_groups.items.all_product_types")} />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="__all__">
                        {t("hq.topping_groups.items.all_product_types")}
                      </SelectItem>
                      {productTypes.map((pt) => (
                        <SelectItem key={pt.id} value={pt.id}>
                          {pt.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <p className="text-[10px] text-muted-foreground">
                    {t("hq.topping_groups.items.drag_hint")}
                  </p>
                </div>

                <div className="flex-1 space-y-1 overflow-y-auto p-2">
                  {availableProducts.map((product) => (
                    <DraggableProductCard
                      key={product.id}
                      product={product}
                      isAdded={addedProductIds.has(product.id)}
                      onClick={() => setDetailProductId(product.id)}
                    />
                  ))}
                  {availableProducts.length === 0 && !productsQuery.isLoading && (
                    <div className="flex flex-col items-center justify-center py-8 text-center text-sm text-muted-foreground">
                      <Package className="mb-2 size-8 text-muted-foreground/40" />
                      {t("hq.topping_groups.items.no_products")}
                    </div>
                  )}
                </div>

                <div className="shrink-0 border-t px-3 py-2 text-center text-xs text-muted-foreground">
                  {t("hq.topping_groups.items.products_of_total", {
                    count: availableProducts.length,
                    total: products.length,
                  })}
                </div>
              </div>

              {/* ── Right panel: current items drop zone ── */}
              <div className="flex min-w-0 flex-1 flex-col bg-muted/5">
                <div className="flex shrink-0 items-center gap-2 border-b px-3 py-2">
                  <span className="text-sm font-medium">
                    {t("hq.topping_groups.items.panel_title")}
                  </span>
                  <Badge variant="outline" className="text-[10px]">
                    {localItems.length}
                  </Badge>
                </div>
                <div className="flex-1 overflow-y-auto p-3">
                  <DroppableItems
                    items={localItems}
                    isLoading={itemsQuery.isLoading}
                    onRemove={handleRemoveItem}
                    onSettings={setSkuSheetItem}
                  />
                </div>
              </div>
            </div>
          </div>
        </div>
      </PageContent>

      {/* Unsaved-changes confirmation — mirrors the floating-section guard:
          a product was added or removed but not saved. */}
      <AlertDialog open={confirmExitOpen} onOpenChange={setConfirmExitOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.topping_groups.items.unsaved_title")}</AlertDialogTitle>
            <AlertDialogDescription>
              {t("hq.topping_groups.items.unsaved_desc")}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              onClick={(e) => {
                e.preventDefault();
                setConfirmExitOpen(false);
                router.push(backHref);
              }}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {t("hq.topping_groups.items.discard_and_leave")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Delete group confirm */}
      <DeleteConfirmDialog
        open={deleteGroupOpen}
        onOpenChange={setDeleteGroupOpen}
        description={t("hq.topping_groups.delete_confirm", {
          name: group.name || t("hq.topping_groups.edit_title"),
        })}
        onConfirm={handleDeleteGroup}
        isPending={deleteGroup.isPending}
      />

      {/* In-use blocking dialog (409 TOPPING_GROUP_IN_USE) */}
      <Dialog open={!!inUseProducts} onOpenChange={(o) => !o && setInUseProducts(null)}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>
              {t("hq.topping_groups.in_use_title", {
                name: group.name || t("hq.topping_groups.edit_title"),
              })}
            </DialogTitle>
            <DialogDescription>{t("hq.topping_groups.in_use_description")}</DialogDescription>
          </DialogHeader>
          <ul className="max-h-60 space-y-1 overflow-y-auto rounded-md border border-input bg-muted/30 p-2 text-xs">
            {inUseProducts?.map((p) => (
              <li key={p.id} className="truncate">
                • {p.name}
              </li>
            ))}
            {inUseProducts && inUseProducts.length === 0 && (
              <li className="text-muted-foreground">{t("hq.topping_groups.in_use_unknown")}</li>
            )}
          </ul>
          <DialogFooter>
            <Button variant="outline" size="sm" onClick={() => setInUseProducts(null)}>
              {t("common.close")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* SKU price sheet */}
      {skuSheetItem && (
        <SkuPriceSheet
          open={!!skuSheetItem}
          onClose={() => setSkuSheetItem(null)}
          brandSlug={brandSlug}
          groupId={groupId}
          item={skuSheetItem}
        />
      )}

      {/* Product detail dialog */}
      {detailProductId && (
        <ProductDetailDialog
          brandSlug={brandSlug}
          productId={detailProductId}
          open={!!detailProductId}
          onOpenChange={(open) => {
            if (!open) setDetailProductId(null);
          }}
        />
      )}

      {/* Drag preview */}
      <DragOverlay>
        {activeDragProduct && (
          <div className="flex w-64 items-center gap-2.5 rounded-md border bg-card p-2 shadow-lg">
            <div className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
              {activeDragProduct.image_url ? (
                <Image
                  src={activeDragProduct.image_url}
                  alt={activeDragProduct.name}
                  width={32}
                  height={32}
                  className="h-full w-full object-cover"
                />
              ) : (
                <ImageIcon className="size-3.5 text-muted-foreground" />
              )}
            </div>
            <div className="flex min-w-0 flex-1 flex-col">
              <span className="truncate text-sm font-medium">{activeDragProduct.name}</span>
              <span className="text-[11px] text-muted-foreground">
                {t("hq.topping_groups.items.variant_count", {
                  n: activeDragProduct.active_skus_count ?? 0,
                })}
              </span>
            </div>
          </div>
        )}
      </DragOverlay>
    </DndContext>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Droppable items panel (extracted to use useDroppable at component level)
// ─────────────────────────────────────────────────────────────────────────────

function SortableItem({
  item,
  onRemove,
  onSettings,
}: {
  item: ToppingGroupItem;
  onRemove: (item: ToppingGroupItem) => void;
  onSettings: (item: ToppingGroupItem) => void;
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: item.id,
  });

  // A staged item isn't persisted yet, so per-SKU price editing (which loads
  // this item's SKUs by id from the backend) is meaningless until it's saved —
  // only the remove action makes sense on it.
  const isStaged = item.id.startsWith("staged-");

  return (
    <div
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(
        "flex items-center gap-2 rounded-md border bg-card p-2",
        isDragging && "opacity-50 shadow-md"
      )}
    >
      <button
        {...attributes}
        {...listeners}
        className="shrink-0 cursor-grab touch-none text-muted-foreground/50 hover:text-muted-foreground active:cursor-grabbing"
        tabIndex={-1}
        type="button"
      >
        <GripVertical className="size-3.5" />
      </button>
      <div className="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
        {item.product?.image_url ? (
          <Image
            src={item.product.image_url}
            alt={item.product.name}
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
          {item.product?.name ?? item.product_id}
        </span>
      </div>
      {!isStaged && (
        <Button
          variant="ghost"
          size="icon"
          className="size-7 shrink-0 text-muted-foreground hover:text-foreground"
          onClick={() => onSettings(item)}
        >
          <Settings2 className="size-3.5" />
        </Button>
      )}
      <Button
        variant="ghost"
        size="icon"
        className="size-7 shrink-0 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
        onClick={() => onRemove(item)}
      >
        <Trash2 className="size-3" />
      </Button>
    </div>
  );
}

function DroppableItems({
  items,
  isLoading,
  onRemove,
  onSettings,
}: {
  items: ToppingGroupItem[];
  isLoading: boolean;
  onRemove: (item: ToppingGroupItem) => void;
  onSettings: (item: ToppingGroupItem) => void;
}) {
  const { t } = useTranslation();
  const { setNodeRef, isOver } = useDroppable({ id: "topping-group-drop-zone" });

  return (
    <div
      ref={setNodeRef}
      className={cn(
        "flex min-h-full flex-col gap-1.5 rounded-lg border border-dashed p-2 transition-colors",
        isOver ? "border-primary bg-primary/5" : "border-border/60"
      )}
    >
      {isLoading ? (
        <div className="flex flex-1 items-center justify-center py-10">
          <Spinner className="size-4 text-muted-foreground" />
        </div>
      ) : items.length === 0 ? (
        <div className="flex flex-1 items-center justify-center py-10 text-center text-xs text-muted-foreground">
          {t("hq.topping_groups.items.drag_here")}
        </div>
      ) : (
        <SortableContext items={items.map((i) => i.id)} strategy={verticalListSortingStrategy}>
          {items.map((item) => (
            <SortableItem key={item.id} item={item} onRemove={onRemove} onSettings={onSettings} />
          ))}
        </SortableContext>
      )}
    </div>
  );
}
