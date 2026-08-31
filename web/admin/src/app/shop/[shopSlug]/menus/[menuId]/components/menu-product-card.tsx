"use client";

import { useCallback, useMemo, useState } from "react";
import Image from "next/image";
import { ImageIcon, Maximize2 } from "lucide-react";
import { Badge, Dialog, DialogContent, DialogHeader, DialogTitle, Switch } from "@godxjp/ui";
import { useToggleShopMenuProduct } from "@/hooks/api/use-shop-menus";
import { useTranslation } from "@/providers/app-provider";
import type { ShopMenuProduct } from "@/types/shop";
import { cn } from "@/lib/utils";
import { ImageLightbox } from "@/components/shared/image-lightbox";
import { formatPrice, getSkuPrices } from "./helpers";
import { SkuTable } from "./sku-table";
import { ToppingGroupsSection } from "./topping-groups-section";

export interface MenuProductCardProps {
  menuProduct: ShopMenuProduct;
  shopSlug: string;
  menuId: string;
}

export function MenuProductCard({ menuProduct, shopSlug, menuId }: MenuProductCardProps) {
  const { t } = useTranslation();
  const toggleProduct = useToggleShopMenuProduct(shopSlug, menuId);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [lightbox, setLightbox] = useState<{ src: string; alt: string } | null>(null);
  const name = menuProduct.product?.name ?? t("shop.menu.detail.untitled");
  const skus = menuProduct.skus ?? [];

  const handleZoomImage = useCallback((src: string, alt: string) => {
    setLightbox({ src, alt });
  }, []);

  const priceRange = useMemo(() => {
    const prices = skus
      .filter((s) => s.is_active)
      .map((s) => getSkuPrices(s).shop)
      .filter((p): p is number => p != null);
    if (prices.length === 0) return null;
    const min = Math.min(...prices);
    const max = Math.max(...prices);
    return min === max ? formatPrice(min) : `${formatPrice(min)} – ${formatPrice(max)}`;
  }, [skus]);

  return (
    <>
      <div
        role="button"
        tabIndex={0}
        onClick={() => setDialogOpen(true)}
        onKeyDown={(e) => {
          if (e.key === "Enter" || e.key === " ") {
            e.preventDefault();
            setDialogOpen(true);
          }
        }}
        className={cn(
          "flex cursor-pointer flex-col overflow-hidden rounded-lg border bg-white transition-colors hover:border-primary/40 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none",
          !menuProduct.is_active && "opacity-60"
        )}
      >
        {/* Thumbnail */}
        <div className="relative flex h-28 items-center justify-center bg-muted/40">
          {menuProduct.product?.image_url ? (
            <Image
              src={menuProduct.product.image_url}
              alt={name}
              fill
              sizes="(max-width: 640px) 50vw, 200px"
              className="object-cover"
            />
          ) : (
            <ImageIcon className="size-8 text-muted-foreground/30" />
          )}
        </div>

        {/* Body */}
        <div className="flex flex-1 flex-col gap-1.5 p-2.5">
          <div className="flex items-center justify-between">
            <span className="line-clamp-2 text-xs leading-snug font-semibold text-foreground">
              {name}
            </span>
            <Badge variant="outline" className="px-1.5 text-[10px] font-normal">
              {t("shop.menu.detail.variants_short", { count: skus.length })}
            </Badge>
          </div>

          {priceRange && (
            <span className="mt-auto text-xs font-medium text-foreground tabular-nums">
              {priceRange}
            </span>
          )}
        </div>

        {/* Footer — toggle only, stop propagation so click doesn't open dialog */}
        <div
          className="flex items-center justify-end border-t px-2.5 py-1.5"
          onClick={(e) => e.stopPropagation()}
          onKeyDown={(e) => e.stopPropagation()}
        >
          <Switch
            checked={menuProduct.is_active}
            disabled={toggleProduct.isPending}
            onCheckedChange={() => toggleProduct.mutate({ menuProductId: menuProduct.id })}
            aria-label={t("shop.menu.detail.toggle_aria", { name })}
            data-testid={`shop-menu-product-toggle-${menuProduct.id}`}
          />
        </div>
      </div>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent
          aria-describedby={undefined}
          className="flex max-h-[90vh] w-[95vw] flex-col gap-3 sm:!max-w-[95vw]"
        >
          <DialogHeader className="shrink-0">
            <div className="flex items-center gap-3">
              {menuProduct.product?.image_url ? (
                <button
                  type="button"
                  onClick={() => handleZoomImage(menuProduct.product!.image_url as string, name)}
                  aria-label={t("shop.menu.detail.zoom_image_aria", { name })}
                  className="group relative size-14 shrink-0 cursor-zoom-in overflow-hidden rounded-md bg-muted"
                >
                  <Image
                    src={menuProduct.product.image_url}
                    alt={name}
                    fill
                    sizes="56px"
                    className="object-cover"
                  />
                  <span className="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/0 transition-colors group-hover:bg-black/40">
                    <Maximize2 className="size-4 text-white opacity-0 transition-opacity group-hover:opacity-100" />
                  </span>
                </button>
              ) : (
                <div className="relative flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted">
                  <ImageIcon className="size-5 text-muted-foreground/40" />
                </div>
              )}
              <div className="flex flex-1 flex-col gap-1">
                <DialogTitle className="text-left">{name}</DialogTitle>
                {menuProduct.product?.description && (
                  <p className="text-xs text-muted-foreground">{menuProduct.product.description}</p>
                )}
              </div>
            </div>
          </DialogHeader>
          <div className="min-h-0 flex-1 space-y-4 overflow-y-auto">
            <SkuTable
              menuProduct={menuProduct}
              shopSlug={shopSlug}
              menuId={menuId}
              onZoomImage={handleZoomImage}
            />
            <ToppingGroupsSection menuProduct={menuProduct} shopSlug={shopSlug} menuId={menuId} />
          </div>
        </DialogContent>
      </Dialog>

      <ImageLightbox
        src={lightbox?.src ?? null}
        alt={lightbox?.alt ?? ""}
        open={!!lightbox}
        onOpenChange={(o) => {
          if (!o) setLightbox(null);
        }}
      />
    </>
  );
}
