/**
 * ItemThumb — the order-line thumbnail with a graceful fallback chain.
 *
 *   1. `imageUrl` (SKU photo, or the product photo the caller resolved).
 *   2. an item-INITIAL tile — used when the line has no image at all, which
 *      happens for a line whose product SKU was removed from the catalog
 *      (menu re-sync / product deletion). Those lines carry no image data to
 *      recover, so instead of a generic "broken picture" icon we show the
 *      first letter of the item name on a muted tile: it reads as intentional
 *      and still identifies the item at a glance.
 *
 * The name still comes from the `menu_item_name` snapshot (see
 * `orderItemDisplayName`); only the photo is unrecoverable for an orphaned SKU.
 */
import { cn } from "@/lib/utils";

/** First grapheme of the label, upper-cased. Falls back to "?" when blank. */
function itemInitial(label: string): string {
  const trimmed = label.trim();
  if (!trimmed) return "?";
  // `Array.from` splits by code point so multi-byte letters (e.g. accented
  // Vietnamese) render as a single glyph.
  return Array.from(trimmed)[0].toUpperCase();
}

export function ItemThumb({
  imageUrl,
  label,
  className,
}: {
  imageUrl?: string | null;
  label: string;
  className?: string;
}) {
  if (imageUrl) {
    return (
      <img
        src={imageUrl}
        alt={label}
        loading="lazy"
        className={cn("size-full object-cover", className)}
      />
    );
  }
  return (
    <div
      aria-hidden
      className={cn(
        "flex size-full items-center justify-center bg-muted/60 font-semibold text-muted-foreground/70",
        className,
      )}
    >
      {itemInitial(label)}
    </div>
  );
}
