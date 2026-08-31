import type { ReactNode } from "react";
import { Sheet, SheetClose, SheetContent, SheetTitle } from "@godxjp/ui";
import { ReceiptTextIcon, XIcon } from "lucide-react";
import { useTranslation } from "@/providers/app-provider";

interface MobileCartDockProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Non-voided line count — shown on both the button badge and the sheet chip. */
  itemCount: number;
  /** The same OrderCart element the lg+ sidebar renders. */
  children: ReactNode;
}

/**
 * Mobile / tablet-portrait cart: at <lg the sidebar is hidden and replaced by
 * a floating button that opens the cart in a Sheet sliding from the right.
 *
 * Pill-shaped, bottom-right, sits above the iOS home-indicator via
 * env(safe-area-inset-bottom). Slimmer than v1 so it doesn't overpower the
 * menu grid; the badge is a separate dot at the top-right when the cart has
 * items.
 */
export function MobileCartDock({
  open,
  onOpenChange,
  itemCount,
  children,
}: MobileCartDockProps) {
  const { t } = useTranslation();

  return (
    <>
      <button
        type="button"
        onClick={() => onOpenChange(true)}
        aria-label={t("pos.cart.open_cart")}
        className="bg-primary text-primary-foreground fixed right-4 bottom-[max(1rem,env(safe-area-inset-bottom))] z-30 flex h-12 cursor-pointer items-center gap-2 rounded-full px-4 text-sm font-semibold shadow-[0_8px_24px_rgba(0,0,0,0.18)] transition-transform hover:scale-[1.03] active:scale-95 lg:hidden"
      >
        <ReceiptTextIcon className="size-5" />
        <span>{t("pos.cart.open_cart")}</span>
        {itemCount > 0 && (
          <span className="bg-background text-foreground ml-0.5 inline-flex h-6 min-w-6 items-center justify-center rounded-full px-1.5 text-xs font-bold tabular-nums shadow-sm">
            {itemCount}
          </span>
        )}
      </button>
      <Sheet open={open} onOpenChange={onOpenChange}>
        {/* `[&>button.absolute]:hidden` — hide the SheetContent default
            close-X (top-4 right-4) because it overlaps the cart's order
            code + "Hủy đơn" row. We render our own close button in the
            sticky topbar instead. */}
        <SheetContent
          side="right"
          className="flex h-full w-full max-w-[440px] flex-col gap-0 p-0 sm:max-w-[440px] lg:hidden [&>button.absolute]:hidden"
        >
          <SheetTitle className="sr-only">{t("pos.cart.open_cart")}</SheetTitle>
          {/* Sticky topbar — page-style: close on the left, title in
              the center, item count chip on the right. Borders match
              the cart header below for a continuous look. */}
          <div className="bg-card flex shrink-0 items-center justify-between gap-2 px-2 py-2">
            <SheetClose
              className="text-muted-foreground hover:bg-muted hover:text-foreground flex size-10 cursor-pointer items-center justify-center rounded-full transition-colors"
              aria-label={t("common.close")}
            >
              <XIcon className="size-5" />
            </SheetClose>
            <div className="flex min-w-0 items-center gap-1.5">
              <ReceiptTextIcon className="text-primary size-4 shrink-0" />
              <span className="text-foreground truncate text-sm font-semibold">
                {t("pos.cart.open_cart")}
              </span>
            </div>
            <span className="bg-primary/10 text-primary inline-flex h-7 min-w-7 items-center justify-center rounded-full px-2 text-xs font-bold tabular-nums">
              {itemCount}
            </span>
          </div>
          <div className="flex min-h-0 flex-1 flex-col">{children}</div>
        </SheetContent>
      </Sheet>
    </>
  );
}
