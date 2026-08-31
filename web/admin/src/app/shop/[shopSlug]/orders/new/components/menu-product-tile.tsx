"use client";

import { useState } from "react";
import Image from "next/image";
import { ChevronLeft, ChevronRight, ImageIcon } from "lucide-react";
import { useTranslation } from "@/providers/app-provider";
import { useShopCurrency } from "@/providers/shop-currency-provider";
import { formatCurrency } from "@/lib/currency";
import type { LocaleCode } from "@/i18n";
import { Popover, PopoverContent, PopoverTrigger } from "@godxjp/ui";

export interface MenuProductSkuOption {
  /** MenuProductSku id — unused for the cart (we snapshot the ProductSku id). */
  id: string;
  skuId: string;
  /** Variant label (e.g. "Regular", "Large") or fallback SKU code. */
  name: string;
  /** Cart display name — product + variant when there are multiple SKUs. */
  cartName: string;
  unitPrice: number;
}

export interface MenuProductImage {
  id: string;
  url: string;
}

export interface MenuProductTileProps {
  productId: string;
  productName: string;
  /** Ordered gallery images. Empty array → empty-state placeholder. */
  images?: MenuProductImage[];
  skus: MenuProductSkuOption[];
  onAdd: (sku: MenuProductSkuOption) => void;
}

/**
 * Product prices on an order screen are the SHOP's money, so the currency comes
 * from the shop and not from the reader's language (#1260). `¥` was
 * interpolated unconditionally, so a Vietnamese shop taking VND saw yen.
 */
function formatPrice(n: number, locale: LocaleCode, currency: string | null): string {
  return formatCurrency(n, locale, currency ?? undefined);
}

function priceBand(
  skus: MenuProductSkuOption[],
  locale: LocaleCode,
  currency: string | null
): string {
  const prices = skus.map((s) => s.unitPrice);
  const min = Math.min(...prices);
  const max = Math.max(...prices);
  return min === max
    ? formatPrice(min, locale, currency)
    : `${formatPrice(min, locale, currency)}〜${formatPrice(max, locale, currency)}`;
}

// =========================================================================
//  Image carousel
//
//  - Click anywhere on the image → next image (wraps around at the end).
//  - Hover-revealed ‹ / › buttons → explicit prev / next.
//  - Dot indicators → jump to specific image.
//  - "1 / N" counter top-right for orientation.
//
//  Add-to-cart is triggered by the area BELOW the carousel (name + price),
//  not the carousel itself — so cycling through images never accidentally
//  adds the wrong variant.
// =========================================================================

function ProductImageCarousel({
  images,
  productName,
}: {
  images: MenuProductImage[];
  productName: string;
}) {
  const [activeIndex, setActiveIndex] = useState(0);

  // Empty state
  if (images.length === 0) {
    return (
      <div
        data-slot="menu-product-thumb-empty"
        className="flex aspect-square w-full items-center justify-center bg-muted"
      >
        <ImageIcon className="size-6 text-muted-foreground/50" />
      </div>
    );
  }

  const safeIndex = Math.min(activeIndex, images.length - 1);
  const current = images[safeIndex];
  const hasMultiple = images.length > 1;

  // Stop click propagation on dots so they don't trigger the next-image
  // click handler on the parent <button>.
  const stop = (e: React.MouseEvent) => e.stopPropagation();

  const goNext = () => setActiveIndex((i) => (i + 1) % images.length);
  const goPrev = () => setActiveIndex((i) => (i === 0 ? images.length - 1 : i - 1));

  return (
    <div
      data-slot="menu-product-carousel"
      className="group relative aspect-square w-full overflow-hidden bg-muted"
      onClick={
        hasMultiple
          ? (e) => {
              stop(e);
              goNext();
            }
          : undefined
      }
    >
      <Image
        src={current.url}
        alt={productName}
        fill
        sizes="(max-width: 640px) 50vw, 200px"
        className="object-cover"
      />

      {hasMultiple && (
        <>
          <span
            role="button"
            aria-label="Previous image"
            onClick={(e) => {
              stop(e);
              goPrev();
            }}
            className="absolute top-1/2 left-1 hidden -translate-y-1/2 cursor-pointer rounded-full bg-black/60 p-1 text-white shadow transition group-hover:flex hover:bg-black/80"
          >
            <ChevronLeft className="size-3.5" />
          </span>
          <span
            role="button"
            aria-label="Next image"
            onClick={(e) => {
              stop(e);
              goNext();
            }}
            className="absolute top-1/2 right-1 hidden -translate-y-1/2 cursor-pointer rounded-full bg-black/60 p-1 text-white shadow transition group-hover:flex hover:bg-black/80"
          >
            <ChevronRight className="size-3.5" />
          </span>

          <div
            className="absolute bottom-1 left-1/2 flex -translate-x-1/2 gap-1 rounded-full bg-black/40 px-1.5 py-0.5"
            onClick={stop}
          >
            {images.map((img, idx) => (
              <span
                key={img.id}
                role="button"
                aria-label={`Image ${idx + 1}`}
                onClick={(e) => {
                  stop(e);
                  setActiveIndex(idx);
                }}
                className={`size-1.5 cursor-pointer rounded-full transition ${
                  idx === safeIndex ? "bg-white" : "bg-white/40 hover:bg-white/70"
                }`}
              />
            ))}
          </div>

          <div className="absolute top-1 right-1 rounded bg-black/50 px-1.5 py-0.5 text-[10px] font-medium text-white tabular-nums">
            {safeIndex + 1}/{images.length}
          </div>
        </>
      )}
    </div>
  );
}

// =========================================================================
//  Tile
// =========================================================================

export function MenuProductTile({ productName, images = [], skus, onAdd }: MenuProductTileProps) {
  const { t, locale } = useTranslation();
  // Shop-scoped screen: prices belong to the shop's currency (#1260).
  const shopCurrency = useShopCurrency();
  const [open, setOpen] = useState(false);

  // The text/price block below the carousel is the add-to-cart trigger
  // (or popover trigger when the product has multiple SKUs).
  const textBlock = (
    <span className="block min-w-0 px-2 py-1.5 text-left text-xs">
      <span className="block truncate font-medium">{productName}</span>
      <span className="block text-muted-foreground tabular-nums">
        {skus.length === 1
          ? formatPrice(skus[0].unitPrice, locale, shopCurrency)
          : `${priceBand(skus, locale, shopCurrency)} · ${t("shop.orders.new.variants_count", { count: skus.length })}`}
      </span>
    </span>
  );

  if (skus.length === 1) {
    return (
      <button
        type="button"
        data-slot="menu-product-tile"
        onClick={() => onAdd(skus[0])}
        className="cursor-pointer overflow-hidden rounded-md border text-left transition-all hover:border-primary/50 hover:shadow-sm"
      >
        <ProductImageCarousel images={images} productName={productName} />
        {textBlock}
      </button>
    );
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          type="button"
          data-slot="menu-product-tile"
          className="cursor-pointer overflow-hidden rounded-md border text-left transition-all hover:border-primary/50 hover:shadow-sm"
        >
          <ProductImageCarousel images={images} productName={productName} />
          {textBlock}
        </button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-64 p-1">
        <p className="px-2 py-1 text-xs font-semibold text-muted-foreground">{productName}</p>
        <div className="flex flex-col">
          {skus.map((s) => (
            <button
              key={s.id}
              type="button"
              className="flex items-center justify-between rounded px-2 py-1.5 text-left text-xs transition-colors hover:bg-muted"
              onClick={() => {
                onAdd(s);
                setOpen(false);
              }}
            >
              <span className="truncate font-medium">{s.name}</span>
              <span className="text-muted-foreground tabular-nums">{formatPrice(s.unitPrice, locale, shopCurrency)}</span>
            </button>
          ))}
        </div>
      </PopoverContent>
    </Popover>
  );
}
