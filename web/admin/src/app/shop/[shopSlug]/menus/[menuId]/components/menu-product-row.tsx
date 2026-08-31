"use client";

import { useCallback, useState } from "react";
import Image from "next/image";
import { ChevronDown, ChevronUp, ImageIcon, Maximize2 } from "lucide-react";
import { Switch } from "@godxjp/ui";
import { useToggleShopMenuProduct } from "@/hooks/api/use-shop-menus";
import { useTranslation } from "@/providers/app-provider";
import type { ShopMenuProduct } from "@/types/shop";
import { cn } from "@/lib/utils";
import { ImageLightbox } from "@/components/shared/image-lightbox";
import { SkuTable } from "./sku-table";
import { ToppingGroupsSection } from "./topping-groups-section";

export interface MenuProductRowProps {
  menuProduct: ShopMenuProduct;
  shopSlug: string;
  menuId: string;
}

export function MenuProductRow({ menuProduct, shopSlug, menuId }: MenuProductRowProps) {
  const { t } = useTranslation();
  const toggleProduct = useToggleShopMenuProduct(shopSlug, menuId);
  const [isOpen, setIsOpen] = useState(false);
  const [lightbox, setLightbox] = useState<{ src: string; alt: string } | null>(null);

  const name = menuProduct.product?.name ?? t("shop.menu.detail.untitled");
  const skus = menuProduct.skus ?? [];
  const skuCount = skus.length;
  const productImage = menuProduct.product?.image_url ?? null;

  const handleZoomImage = useCallback((src: string, alt: string) => {
    setLightbox({ src, alt });
  }, []);

  return (
    <div
      className={cn(
        "flex flex-col overflow-hidden rounded-md border bg-card transition-colors hover:border-primary/40",
        !menuProduct.is_active && "opacity-60"
      )}
    >
      <div
        role="button"
        tabIndex={0}
        onClick={() => setIsOpen(!isOpen)}
        onKeyDown={(e) => {
          if (e.key === "Enter" || e.key === " ") {
            e.preventDefault();
            setIsOpen(!isOpen);
          }
        }}
        className="flex cursor-pointer items-center gap-2 p-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
      >
        {productImage ? (
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              handleZoomImage(productImage, name);
            }}
            onKeyDown={(e) => e.stopPropagation()}
            aria-label={t("shop.menu.detail.zoom_image_aria", { name })}
            className="group relative size-10 shrink-0 cursor-zoom-in overflow-hidden rounded bg-muted"
          >
            <Image
              src={productImage}
              alt={name}
              width={40}
              height={40}
              className="h-full w-full object-cover"
            />
            <span className="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/0 transition-colors group-hover:bg-black/40">
              <Maximize2 className="size-3.5 text-white opacity-0 transition-opacity group-hover:opacity-100" />
            </span>
          </button>
        ) : (
          <div className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded border bg-muted">
            <ImageIcon className="size-3.5 text-muted-foreground" />
          </div>
        )}
        <div className="min-w-0 flex-1">
          <span className="block truncate text-sm font-medium">{name}</span>
          <span className="text-[11px] text-muted-foreground">
            {t("shop.menu.detail.variants_short", { count: skuCount })}
            {/* #1227 — the rate this line is actually billed at, resolved by
                the backend across all six tiers. Shown per line rather than
                once per menu because the tiers mean different lines in the
                same menu can carry different rates. */}
            {menuProduct.effective_tax_rate != null && (
              <>
                {" · "}
                <span className="tabular-nums">
                  {t("shop.menu.detail.tax_rate_short", {
                    rate: menuProduct.effective_tax_rate,
                  })}
                </span>
              </>
            )}
          </span>
        </div>

        <div
          onClick={(e) => e.stopPropagation()}
          onKeyDown={(e) => e.stopPropagation()}
          className="mx-2 flex items-center justify-center"
        >
          <Switch
            checked={menuProduct.is_active}
            disabled={toggleProduct.isPending}
            onCheckedChange={() => toggleProduct.mutate({ menuProductId: menuProduct.id })}
            aria-label={t("shop.menu.detail.toggle_aria", { name })}
            data-testid={`shop-menu-product-toggle-${menuProduct.id}`}
          />
        </div>

        <div className="flex items-center pr-1 text-muted-foreground">
          {isOpen ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
        </div>
      </div>

      {isOpen && (
        <div className="space-y-4 border-t bg-muted/10 p-3">
          <SkuTable
            menuProduct={menuProduct}
            shopSlug={shopSlug}
            menuId={menuId}
            onZoomImage={handleZoomImage}
          />
          <ToppingGroupsSection menuProduct={menuProduct} shopSlug={shopSlug} menuId={menuId} />
        </div>
      )}

      <ImageLightbox
        src={lightbox?.src ?? null}
        alt={lightbox?.alt ?? ""}
        open={!!lightbox}
        onOpenChange={(o) => {
          if (!o) setLightbox(null);
        }}
      />
    </div>
  );
}
