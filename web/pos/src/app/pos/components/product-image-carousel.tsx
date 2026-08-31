/**
 * ProductImageCarousel — renders all gallery images for a product in the
 * menu card. Falls back to a placeholder when the product has no gallery
 * or only one image (in which case we render a plain <img> to avoid the
 * cost of Embla for a single frame).
 */

import {
  Carousel,
  CarouselContent,
  CarouselItem,
  CarouselNext,
  CarouselPrevious,
} from "@godxjp/ui";
import { ImageIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/providers/app-provider";
import type { ProductGalleryItem } from "../types";

export interface ProductImageCarouselProps {
  gallery?: ProductGalleryItem[];
  /** Product name — used as alt text for every image. */
  name: string;
  className?: string;
}

export function ProductImageCarousel({
  gallery,
  name,
  className,
}: ProductImageCarouselProps) {
  const { t } = useTranslation();
  const images = (gallery ?? []).filter((g) => g.url);

  // No gallery — lightweight placeholder so the card still has a square
  // image slot (consistent layout across cards).
  if (images.length === 0) {
    return (
      <div
        className={cn(
          "flex aspect-square w-full items-center justify-center rounded-md border bg-muted/40 text-muted-foreground",
          className,
        )}
      >
        <ImageIcon className="size-8" />
      </div>
    );
  }

  // Single image — skip the carousel machinery to keep the card cheap.
  if (images.length === 1) {
    return (
      <img
        src={images[0].url}
        alt={name}
        loading="lazy"
        className={cn(
          "aspect-square w-full rounded-md border object-cover",
          className,
        )}
      />
    );
  }

  return (
    <div
      className={cn("relative", className)}
      // Stop ONLY the carousel's left/right nav buttons from bubbling into
      // the parent "add to cart" card click — swallowing every click here
      // would also eat taps on the image / the card's "+" overlay (which is
      // pointer-events-none and lands on this div), so the card would never
      // add the item. Gate on a nav-button target that lives inside us.
      onClick={(e) => {
        const btn = (e.target as HTMLElement).closest("button");
        if (btn && e.currentTarget.contains(btn)) e.stopPropagation();
      }}
      onKeyDown={(e) => {
        const btn = (e.target as HTMLElement).closest("button");
        if (btn && e.currentTarget.contains(btn)) e.stopPropagation();
      }}
    >
      <Carousel opts={{ loop: true }} className="w-full">
        <CarouselContent>
          {images.map((img) => (
            <CarouselItem key={img.id}>
              <img
                src={img.url}
                alt={name}
                loading="lazy"
                className="aspect-square w-full rounded-md border object-cover"
              />
            </CarouselItem>
          ))}
        </CarouselContent>
        <CarouselPrevious
          className="left-1 size-6 border-none bg-background/70 text-foreground backdrop-blur-sm hover:bg-background"
          aria-label={t("pos.carousel.prev")}
        />
        <CarouselNext
          className="right-1 size-6 border-none bg-background/70 text-foreground backdrop-blur-sm hover:bg-background"
          aria-label={t("pos.carousel.next")}
        />
      </Carousel>
      <span className="absolute bottom-1 right-1 rounded-full bg-background/80 px-1.5 py-0.5 text-[9px] font-medium text-foreground shadow-sm backdrop-blur-sm">
        {images.length} {t("pos.carousel.count")}
      </span>
    </div>
  );
}
