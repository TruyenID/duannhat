"use client";

import { useState, type ReactNode } from "react";
import { useTranslations } from "next-intl";
import { ChevronLeft, ChevronRight } from "lucide-react";

interface ItemImageGalleryProps {
  /** Ordered list of absolute image URLs (from the backend menu API). */
  images?: string[];
  alt: string;
  /** className applied to each <img> element. */
  imgClassName?: string;
  /** Rendered when there are no images at all. */
  placeholder?: ReactNode;
  /** Positioning class for the dot indicators (default "bottom-2"). Use e.g.
   *  "top-3" when a bottom overlay would cover bottom-anchored dots. */
  dotsPositionClassName?: string;
  /** Keep prev/next arrows always visible instead of revealing on hover.
   *  Useful in the detail modal where the gallery is the primary content. */
  alwaysShowArrows?: boolean;
}

/**
 * ItemImageGallery — compact in-card photo carousel.
 *
 * Shows one image at a time with dot indicators; when there is more than one
 * photo it also reveals prev/next arrows. All controls call
 * `stopPropagation()` so tapping them swaps the photo instead of bubbling up
 * to the card's onClick (which opens the product modal).
 *
 * Uses <img> rather than next/image because the storage domain (MinIO / S3)
 * isn't allowlisted in next.config and presigned URLs vary per request.
 */
export function ItemImageGallery({
  images,
  alt,
  imgClassName,
  placeholder,
  dotsPositionClassName = "bottom-2",
  alwaysShowArrows = false,
}: ItemImageGalleryProps) {
  const t = useTranslations("common");
  const gallery = images ?? [];
  const [index, setIndex] = useState(0);

  const arrowVisibility = alwaysShowArrows
    ? "opacity-100"
    : "opacity-0 group-hover:opacity-100 max-md:opacity-100";

  if (gallery.length === 0) {
    return <>{placeholder}</>;
  }

  // Guard against an out-of-range index if the gallery shrinks.
  const safeIndex = Math.min(index, gallery.length - 1);
  const hasMultiple = gallery.length > 1;

  const go = (next: number) => {
    setIndex((next + gallery.length) % gallery.length);
  };

  return (
    <div className="absolute inset-0 h-full w-full">
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        key={safeIndex}
        src={gallery[safeIndex]}
        alt={gallery.length > 1 ? `${alt} (${safeIndex + 1}/${gallery.length})` : alt}
        className={imgClassName}
      />

      {hasMultiple && (
        <>
          {/* Prev / next arrows */}
          <button
            type="button"
            aria-label={t('previousImage')}
            onClick={(e) => {
              e.stopPropagation();
              go(safeIndex - 1);
            }}
            className={`absolute left-1.5 top-1/2 flex size-7 -translate-y-1/2 items-center justify-center rounded-full bg-black/35 text-white backdrop-blur-sm transition-opacity hover:bg-black/55 ${arrowVisibility}`}
          >
            <ChevronLeft className="size-4" />
          </button>
          <button
            type="button"
            aria-label={t('nextImage')}
            onClick={(e) => {
              e.stopPropagation();
              go(safeIndex + 1);
            }}
            className={`absolute right-1.5 top-1/2 flex size-7 -translate-y-1/2 items-center justify-center rounded-full bg-black/35 text-white backdrop-blur-sm transition-opacity hover:bg-black/55 ${arrowVisibility}`}
          >
            <ChevronRight className="size-4" />
          </button>

          {/* Dot indicators */}
          <div className={`absolute inset-x-0 ${dotsPositionClassName} flex items-center justify-center gap-1.5`}>
            {gallery.map((src, i) => (
              <button
                key={src + i}
                type="button"
                aria-label={t('goToImage', { index: i + 1 })}
                aria-current={i === safeIndex}
                onClick={(e) => {
                  e.stopPropagation();
                  setIndex(i);
                }}
                className={
                  i === safeIndex
                    ? "h-1.5 w-4 rounded-full bg-white shadow"
                    : "h-1.5 w-1.5 rounded-full bg-white/60 shadow transition-colors hover:bg-white/90"
                }
              />
            ))}
          </div>
        </>
      )}
    </div>
  );
}
