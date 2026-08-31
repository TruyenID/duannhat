"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Dialog, DialogContent } from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { UtensilsCrossed, AlertCircle, ArrowRight } from "lucide-react";

interface MenuExpiredModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  menuName?: string;
  cartDeadlineIso?: string | null; // ISO string for cart timeout deadline
  /**
   * True when the cart items themselves have already passed their per-item
   * deadline (caller computes from `useCart().items.every(isItemExpired)`).
   * Modal then shows the "đã hết thời gian" copy + skips the countdown that
   * would otherwise be miscomputed against the NEXT menu's deadline after
   * rollover.
   */
  cartItemsExpired?: boolean;
  /**
   * Length of the cart grace window in minutes (`cartTimeoutMinutes` from the
   * menu metadata). Used as a sanity ceiling on the countdown: the grace
   * deadline is `menuEnd + grace`, and the modal only opens once we're already
   * past `menuEnd`, so a correct countdown can never exceed `grace`. A value
   * larger than that means `cartDeadlineIso` is the NEXT menu's deadline (BE
   * recomputes it on rollover) — we then show the "expired" copy instead of a
   * misleading multi-hour countdown. Defaults to a 60-minute ceiling when the
   * metadata didn't supply it.
   */
  graceWindowMinutes?: number;
  /**
   * Called when the user clicks the action button. Caller should trigger
   * a refetch of the menu API so the next time-of-day menu is loaded
   * (e.g., bump a refreshKey + clear local menu state). The modal closes
   * itself after invoking this callback.
   */
  onViewNextMenu?: () => void;
}

export default function MenuExpiredModal({
  open,
  onOpenChange,
  menuName,
  cartDeadlineIso,
  cartItemsExpired = false,
  graceWindowMinutes,
  onViewNextMenu,
}: MenuExpiredModalProps) {
  const t = useTranslations("menuExpiredModal");
  const resolvedMenuName = menuName || t("fallbackMenuName");
  const [secondsRemaining, setSecondsRemaining] = useState<number | null>(null);

  // Live MM:SS countdown against the cart deadline. Skip entirely when the
  // caller flagged cart items as already expired — at that point
  // `cartDeadlineIso` is the NEXT menu's deadline (BE recomputes on rollover)
  // and counting against it would show a misleading 2h+ window for items the
  // user can no longer order. Ticks every 1s so the user sees the timer
  // visibly counting down.
  useEffect(() => {
    if (!open || !cartDeadlineIso || cartItemsExpired) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setSecondsRemaining(null);
      return;
    }

    const updateCountdown = () => {
      const now = Date.now();
      const deadline = new Date(cartDeadlineIso).getTime();
      const diff = deadline - now;
      setSecondsRemaining(Math.max(0, Math.ceil(diff / 1000)));
    };

    // Update immediately
    updateCountdown();

    // Then tick every second
    const timer = setInterval(updateCountdown, 1000);
    return () => clearInterval(timer);
  }, [open, cartDeadlineIso, cartItemsExpired]);

  // Treat the modal as in "expired" state when EITHER the caller flagged it
  // (items in cart already past per-item deadline) OR the visible countdown
  // itself reached 0. The latter handles the case where the user keeps the
  // modal open past the deadline — the copy swaps in-place to "đã hết thời
  // gian" without waiting for the next caller re-render.
  const countdownReachedZero = secondsRemaining === 0;
  // A menu-transition confirm grace is always short (the few minutes to confirm
  // old-menu items before they're removed), so the countdown can never exceed
  // the grace window — the modal only opens after the menu's serve-time ended,
  // so deadline − now ≤ grace. A larger value means cartDeadlineIso is the NEXT
  // menu's deadline after a rollover (e.g. 7h45m / 4h19m) — treat the old-menu
  // cart items as expired instead of showing a misleading multi-hour countdown.
  //
  // The ceiling is clamped to 60 minutes even when cartTimeoutMinutes is
  // configured larger, so a shop with a long cart timeout still can't surface a
  // multi-hour transition countdown. +60s buffer absorbs clock skew.
  const MAX_GRACE_CEILING_MINUTES = 60;
  const graceCeilingSeconds =
    Math.min(graceWindowMinutes ?? MAX_GRACE_CEILING_MINUTES, MAX_GRACE_CEILING_MINUTES) * 60 + 60;
  const countdownImplausible =
    secondsRemaining !== null && secondsRemaining > graceCeilingSeconds;
  const showExpired = cartItemsExpired || countdownReachedZero || countdownImplausible;

  // Format as H:MM:SS (when ≥ 1h) or MM:SS — matches the takeaway
  // PaymentWarningBanner + OrderCountdownBadge used in the dine-in flow,
  // so the menu-rollover modal speaks the same time-format dialect.
  // Renders "…" only during the initial tick before the interval fires.
  const countdownLabel = (() => {
    if (secondsRemaining === null) return "…";
    const hours = Math.floor(secondsRemaining / 3600);
    const minutes = Math.floor((secondsRemaining % 3600) / 60);
    const seconds = secondsRemaining % 60;
    const mm = minutes.toString().padStart(2, "0");
    const ss = seconds.toString().padStart(2, "0");
    return hours > 0 ? `${hours}:${mm}:${ss}` : `${mm}:${ss}`;
  })();

  const handleViewMenu = () => {
    onOpenChange(false);
    onViewNextMenu?.();
  };

  // Modal chỉ được đóng qua nút "Xem thực đơn tiếp theo" (handleViewMenu
  // gọi `onOpenChange(false)` trực tiếp với prop của parent — không đi qua
  // wrapper này). Backdrop click / Escape key kích hoạt Dialog's onOpenChange
  // ở đây → ta swallow request để modal không tắt được bằng cách "lỡ tay".
  // Block backdrop bằng `disablePointerDismissal`; block escape bằng filter
  // `eventDetails.reason` ('escapeKey' / 'focusOut').
  const handleDialogOpenChange = (
    next: boolean,
    eventDetails?: { reason?: string },
  ) => {
    if (!next && eventDetails?.reason !== "close-press" && eventDetails?.reason !== "imperative-action") {
      return;
    }
    onOpenChange(next);
  };

  return (
    <Dialog open={open} onOpenChange={handleDialogOpenChange} disablePointerDismissal>
      <DialogContent
        className="max-w-[340px] rounded-2xl p-0 gap-0 overflow-hidden"
        showCloseButton={false}
      >
        {/* Icon Section */}
        <div className="flex items-center justify-center pt-8 pb-4">
          <div className="relative">
            <div
              className="flex h-20 w-20 items-center justify-center rounded-full"
              style={{ backgroundColor: "#E8F5E9" }}
            >
              <div
                className="flex h-16 w-16 items-center justify-center rounded-full"
                style={{ backgroundColor: "#2D8336" }}
              >
                <UtensilsCrossed className="h-8 w-8 text-white" />
              </div>
            </div>
            {/* Green dot indicator */}
            <div
              className="absolute bottom-0 right-0 h-5 w-5 rounded-full border-4 border-white"
              style={{ backgroundColor: "#2D8336" }}
            />
          </div>
        </div>

        {/* Content Section */}
        <div className="px-6 pb-6 text-center space-y-4">
          {/* Title — spec Figma: 20/700/#1B1C1C */}
          <h2 className="text-[20px] font-bold" style={{ color: "#1B1C1C" }}>
            {t('title')}
          </h2>

          {/* Description — spec Figma: 14px/#3F4940 */}
          <div className="text-sm leading-relaxed" style={{ color: "#3F4940" }}>
            <p>
              {t.rich('endedLine', {
                name: resolvedMenuName,
                menu: (chunks) => <span className="font-semibold">{chunks}</span>,
              })}
            </p>
            <p className="mt-2">
              {t('switchingLine')}
            </p>
          </div>

          {/* Warning Box — spec Figma: bg #FDFFE9 */}
          <div
            className="rounded-lg p-3 text-left"
            style={{ backgroundColor: "#FDFFE9", border: "1px solid #FFE082" }}
          >
            <div className="flex gap-2">
              <AlertCircle className="h-5 w-5 shrink-0 text-amber-600 mt-0.5" />
              <div className="text-xs text-amber-900 leading-relaxed">
                <p>
                  {/* "Lưu ý quan trọng" — font-bold (700) theo spec */}
                  <span className="font-bold">{t('importantNote')}</span>{" "}
                  <span className="font-normal tabular-nums">
                    {showExpired
                      ? t('noteBodyExpired', { menu: resolvedMenuName })
                      : t.rich('noteBody', {
                          menu: resolvedMenuName,
                          // MM:SS countdown (live, 1s tick) injected vào
                          // `{countdown}`. `<bold>...</bold>` tag trong message
                          // bọc countdown → font-bold 700 theo spec.
                          // `tabular-nums` ở span cha giữ width số ổn định.
                          countdown: countdownLabel,
                          bold: (chunks) => (
                            <span className="font-bold">{chunks}</span>
                          ),
                        })}
                  </span>
                </p>
                {!showExpired && (
                  <p className="mt-2">
                    {t('noteAfter')}
                  </p>
                )}
              </div>
            </div>
          </div>

          {/* Action Button */}
          <Button
            onClick={handleViewMenu}
            className="w-full h-12 rounded-lg font-semibold text-base text-white flex items-center justify-center gap-2"
            style={{ backgroundColor: "#2D8336" }}
          >
            {t('viewNextMenu')}
            <ArrowRight className="h-5 w-5" />
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
