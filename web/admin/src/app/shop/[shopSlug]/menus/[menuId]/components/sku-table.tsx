"use client";

import { useMemo, useState } from "react";
import Image from "next/image";
import {
  EllipsisVertical,
  ImageIcon,
  Maximize2,
  Pencil,
  Power,
  RotateCcw,
  UtensilsCrossed,
} from "lucide-react";
import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
  Input,
  Spinner,
  StatusBadge,
  Switch,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@godxjp/ui";
import {
  useToggleShopMenuProductSku,
  useUpdateShopMenuSkuPrice,
  useResetShopMenuSkuPrice,
} from "@/hooks/api/use-shop-menus";
import { useTranslation } from "@/providers/app-provider";
import type {
  ShopMenuProduct,
  ShopMenuProductOptionValueRef,
  ShopMenuProductSku,
} from "@/types/shop";
import { cn } from "@/lib/utils";
import { formatPrice } from "./helpers";

export interface SkuTableProps {
  menuProduct: ShopMenuProduct;
  shopSlug: string;
  menuId: string;
  /** Open the parent lightbox on a SKU thumbnail click. Omit to render the
   *  thumbnails as static images. */
  onZoomImage?: (src: string, alt: string) => void;
}

type VariantSlot = 1 | 2 | 3;
const VARIANT_SLOTS: readonly VariantSlot[] = [1, 2, 3] as const;

function optionValueAt(
  sku: ShopMenuProductSku,
  slot: VariantSlot
): ShopMenuProductOptionValueRef | null | undefined {
  const key = `option_value${slot}` as const;
  return sku.product_sku?.[key];
}

/**
 * Walk every sku in the product to find the (translated) option name that
 * lives in each variant slot. The backend stamps Astrotomic's locale-matched
 * string into `option.name` via Accept-Language, so reading the first non-empty
 * value per slot is enough — no per-locale resolution needed here.
 */
function deriveActiveSlots(skus: ShopMenuProductSku[]): { slot: VariantSlot; name: string }[] {
  const active: { slot: VariantSlot; name: string }[] = [];
  for (const slot of VARIANT_SLOTS) {
    let name: string | undefined;
    for (const sku of skus) {
      const ov = optionValueAt(sku, slot);
      if (ov?.option?.name) {
        name = ov.option.name;
        break;
      }
      if (!name && ov) {
        name = "";
      }
    }
    if (name !== undefined) {
      active.push({ slot, name });
    }
  }
  return active;
}

export function SkuTable({ menuProduct, shopSlug, menuId, onZoomImage }: SkuTableProps) {
  const { t } = useTranslation();
  const toggleSku = useToggleShopMenuProductSku(shopSlug, menuId);
  const updatePrice = useUpdateShopMenuSkuPrice(shopSlug, menuId);
  const resetPrice = useResetShopMenuSkuPrice(shopSlug, menuId);

  const activeSlots = useMemo(() => deriveActiveSlots(menuProduct.skus ?? []), [menuProduct.skus]);
  const skus = menuProduct.skus ?? [];
  // Simple product = exactly one SKU. The HQ side hides per-SKU image upload
  // for that case (the product gallery IS the SKU image), so STT + image
  // columns are redundant here too — drop them to keep the row scannable.
  const isSimple = skus.length === 1;

  const [editingPriceSku, setEditingPriceSku] = useState<ShopMenuProductSku | null>(null);
  const [editDraft, setEditDraft] = useState("");
  const [resetPriceSku, setResetPriceSku] = useState<ShopMenuProductSku | null>(null);

  function openEditPrice(sku: ShopMenuProductSku) {
    setEditDraft(String(sku.selling_price ?? 0));
    setEditingPriceSku(sku);
  }

  function handleSavePrice() {
    const next = Number(editDraft);
    // #2052 — `< 0`, KHÔNG phải `<= 0`. Giá 0 là một mức giá hợp lệ (hàng
    // tặng, quà khuyến mãi, món kèm combo); thứ phải chặn là giá ÂM, vì đó là
    // giảm giá/hoàn tiền — khái niệm khác, đi đường khác. HQ vốn đã cho phép 0,
    // nên `<= 0` ở đây còn làm shop và HQ nói hai luật khác nhau.
    if (!editingPriceSku || !Number.isFinite(next) || next < 0) return;
    updatePrice.mutate(
      {
        menuProductId: menuProduct.id,
        menuProductSkuId: editingPriceSku.id,
        data: { selling_price: next },
      },
      { onSettled: () => setEditingPriceSku(null) }
    );
  }

  function handleResetPrice() {
    if (!resetPriceSku) return;
    resetPrice.mutate(
      { menuProductId: menuProduct.id, menuProductSkuId: resetPriceSku.id },
      { onSettled: () => setResetPriceSku(null) }
    );
  }

  if (skus.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center py-6 text-sm text-muted-foreground">
        <UtensilsCrossed className="mb-2 size-5 opacity-40" />
        {t("shop.menu.detail.no_variants_short")}
      </div>
    );
  }

  return (
    <>
      <div className="overflow-x-auto rounded-md border">
        <Table>
          <TableHeader>
            <TableRow>
              {!isSimple && (
                <>
                  <TableHead className="h-8 w-8 text-center text-xs">
                    {t("shop.menu.detail.no_col")}
                  </TableHead>
                  <TableHead className="h-8 w-14 text-xs">
                    {t("shop.menu.detail.image_col")}
                  </TableHead>
                </>
              )}
              <TableHead className="h-8 text-xs">{t("shop.menu.detail.sku_col")}</TableHead>
              {activeSlots.map(({ slot, name }) => (
                <TableHead key={`variant-${slot}`} className="h-8 text-xs">
                  {name || t(`shop.menu.detail.variant_${slot}_col`)}
                </TableHead>
              ))}
              <TableHead className="h-8 text-right text-xs">
                {t("shop.menu.detail.default_price_col")}
              </TableHead>
              <TableHead className="h-8 text-right text-xs">
                {t("shop.menu.detail.price_col")}
              </TableHead>
              <TableHead className="h-8 w-24 text-xs">{t("common.status")}</TableHead>
              <TableHead className="h-8 w-14 text-right text-xs">
                {t("shop.menu.detail.action_col")}
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {skus.map((sku, idx) => {
              const defaultPrice = sku.default_price ?? sku.product_sku?.selling_price ?? null;
              // Variant SKUs must carry their own image. Don't fall back to the
              // parent product image here — that's the simple-SKU contract only,
              // and that branch never reaches this map (cell is hidden via
              // `isSimple`). Falling back here would mislead the user into
              // thinking SKU 3/4 share the product photo.
              const skuImage = sku.product_sku?.image_url ?? null;
              const altLabel = sku.product_sku?.sku ?? sku.product_sku?.name ?? "SKU";
              return (
                <TableRow
                  key={sku.id}
                  className={cn((!sku.is_active || !menuProduct.is_active) && "opacity-50")}
                >
                  {!isSimple && (
                    <>
                      <TableCell className="py-2 text-center text-xs text-muted-foreground tabular-nums">
                        {idx + 1}
                      </TableCell>
                      <TableCell className="py-2">
                        {skuImage ? (
                          <button
                            type="button"
                            onClick={() => onZoomImage?.(skuImage, altLabel)}
                            aria-label={t("shop.menu.detail.zoom_image_aria", { name: altLabel })}
                            className="group relative size-10 cursor-zoom-in overflow-hidden rounded bg-muted"
                          >
                            <Image
                              src={skuImage}
                              alt={altLabel}
                              width={40}
                              height={40}
                              className="h-full w-full object-cover"
                            />
                            <span className="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/0 transition-colors group-hover:bg-black/40">
                              <Maximize2 className="size-3.5 text-white opacity-0 transition-opacity group-hover:opacity-100" />
                            </span>
                          </button>
                        ) : (
                          <div className="flex size-10 items-center justify-center overflow-hidden rounded border bg-muted">
                            <ImageIcon className="size-4 text-muted-foreground" />
                          </div>
                        )}
                      </TableCell>
                    </>
                  )}
                  <TableCell className="py-2">
                    <code className="rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground">
                      {sku.product_sku?.sku ?? "—"}
                    </code>
                  </TableCell>
                  {activeSlots.map(({ slot }) => {
                    const ov = optionValueAt(sku, slot);
                    return (
                      <TableCell key={`sku-${sku.id}-ov-${slot}`} className="py-2">
                        {ov ? (
                          <span className="text-xs font-medium text-foreground">{ov.label}</span>
                        ) : (
                          <span className="text-xs text-muted-foreground">—</span>
                        )}
                      </TableCell>
                    );
                  })}

                  {/* Default price — HQ setting, always muted */}
                  <TableCell className="py-2 text-right text-sm text-muted-foreground tabular-nums">
                    {formatPrice(defaultPrice)}
                  </TableCell>

                  {/* Shop price — prominent if overridden, dash if using default */}
                  <TableCell className="py-2 text-right">
                    {sku.is_price_overridden ? (
                      <span className="text-sm font-medium tabular-nums">
                        {formatPrice(sku.selling_price)}
                      </span>
                    ) : (
                      <span className="text-xs text-muted-foreground">—</span>
                    )}
                  </TableCell>

                  {/* Status */}
                  <TableCell className="py-2">
                    <StatusBadge status={sku.is_active ? "active" : "inactive"} />
                  </TableCell>

                  {/* Action — dropdown (edit price, reset price, toggle active) */}
                  <TableCell className="py-2 text-right">
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="size-7">
                          <EllipsisVertical className="size-4" />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem onClick={() => openEditPrice(sku)}>
                          <Pencil className="mr-2 size-3.5" />{" "}
                          {t("shop.menu.detail.action_edit_price")}
                        </DropdownMenuItem>
                        {sku.is_price_overridden && (
                          <DropdownMenuItem onClick={() => setResetPriceSku(sku)}>
                            <RotateCcw className="mr-2 size-3.5" />{" "}
                            {t("shop.menu.detail.reset_to_default")}
                          </DropdownMenuItem>
                        )}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                          onClick={() =>
                            toggleSku.mutate({
                              menuProductId: menuProduct.id,
                              menuProductSkuId: sku.id,
                            })
                          }
                          disabled={!menuProduct.is_active || toggleSku.isPending}
                        >
                          <Power className="mr-2 size-3.5" />
                          {sku.is_active ? t("common.deactivate") : t("common.activate")}
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </div>

      {/* Edit price dialog */}
      <Dialog
        open={!!editingPriceSku}
        onOpenChange={(o) => {
          if (!o && !updatePrice.isPending) setEditingPriceSku(null);
        }}
      >
        <DialogContent className="sm:max-w-xs">
          <DialogHeader>
            <DialogTitle>{t("shop.menu.detail.edit_price_title")}</DialogTitle>
            <DialogDescription>
              {editingPriceSku
                ? t("shop.menu.detail.edit_price_description_sku", {
                    sku: editingPriceSku.product_sku?.sku ?? "SKU",
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
              if (e.key === "Escape") setEditingPriceSku(null);
            }}
            disabled={updatePrice.isPending}
            className="text-right tabular-nums"
          />
          <DialogFooter>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setEditingPriceSku(null)}
              disabled={updatePrice.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button size="sm" onClick={handleSavePrice} disabled={updatePrice.isPending}>
              {updatePrice.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("shop.menu.detail.overwrite_price.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Reset price dialog */}
      <Dialog
        open={!!resetPriceSku}
        onOpenChange={(o) => {
          if (!o && !resetPrice.isPending) setResetPriceSku(null);
        }}
      >
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{t("shop.menu.detail.reset_price.title")}</DialogTitle>
            <DialogDescription>
              {resetPriceSku &&
                t("shop.menu.detail.reset_price.description", {
                  current: formatPrice(resetPriceSku.selling_price),
                  default: formatPrice(
                    resetPriceSku.default_price ?? resetPriceSku.product_sku?.selling_price ?? 0
                  ),
                })}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setResetPriceSku(null)}
              disabled={resetPrice.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button size="sm" onClick={handleResetPrice} disabled={resetPrice.isPending}>
              {resetPrice.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("shop.menu.detail.reset_price.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
