/**
 * Plan-038 T4.1 — "Gửi bếp" floating action button (FAB).
 *
 * A round, draggable button that floats over the checkout sidebar instead of
 * occupying a layout row — staff can park it anywhere inside the cart aside.
 *
 *   <KitchenFireButton orderId={orderId} refreshSignal={sig} />
 *
 * Requirements on the host: the FAB positions itself `absolute` against its
 * nearest positioned ancestor, so the parent (`order-cart` <aside>) must be
 * `relative`. When `workstationPrintService.enabled === false` (env var unset)
 * the FAB is hidden — matches the silent-no-op pattern from plan-030.
 *
 * Interaction:
 * - Tap (pointer barely moved) → fire kitchen tickets for unprinted items.
 * - Drag (pointer moved past a threshold) → reposition; clamped to the aside
 *   bounds and persisted to localStorage as a ratio so it survives reloads and
 *   sidebar resizes.
 * - Keyboard: focus + Enter/Space fires.
 *
 * Visual states:
 * - pending items → amber circle, ChefHat icon, count badge (top-right).
 * - all fired      → emerald circle, check icon, no badge.
 * - firing         → amber circle, spinner.
 * - a small status dot (bottom-right) reflects kitchen-printer reachability
 *   (green online / amber stale / red offline); the title tooltip spells it out.
 */

import { useCallback, useEffect, useRef, useState } from "react";
import type { KeyboardEvent as ReactKeyboardEvent, PointerEvent as ReactPointerEvent, ReactNode } from "react";
import { CheckIcon, ChefHatIcon } from "lucide-react";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { ApiError } from "@/lib/api";
import { Spinner } from "@/components/ui/spinner";
import { useTranslation } from "@/providers/app-provider";
import {
  type PrintStatusResult,
  workstationPrintService,
} from "@/services/workstation-print-service";

export interface KitchenFireButtonProps {
  orderId: string | null;
  /** Optional — extra disable signal (e.g. order locked / awaiting payment). */
  disabled?: boolean;
  /**
   * Opaque value that changes whenever the order's line items change (add /
   * void / qty edit). When it changes the component re-probes print status
   * immediately so the pending-print count + CTA reflect the new order state
   * without waiting for the 30s poll or a manual reload. Pass e.g. a
   * signature of the active items.
   */
  refreshSignal?: string | number;
}

const STATUS_POLL_MS = 30_000;
const FAB_SIZE = 56; // px — keep in sync with the `size-14` class below.
const EDGE_MARGIN = 16; // px — min gap kept from the sidebar edges.
const TAP_THRESHOLD = 6; // px — movement under this counts as a tap, not a drag.
const POSITION_STORAGE_KEY = "pos.kitchenFab.position";

interface Pos {
  x: number;
  y: number;
}

function clamp(v: number, lo: number, hi: number): number {
  return Math.min(Math.max(v, lo), Math.max(lo, hi));
}

/** Persisted as a 0..1 ratio of the draggable area so it adapts to resize. */
function loadSavedRatio(): { xr: number; yr: number } | null {
  try {
    const raw = localStorage.getItem(POSITION_STORAGE_KEY);
    if (!raw) return null;
    const p = JSON.parse(raw) as { xr?: unknown; yr?: unknown };
    if (typeof p.xr === "number" && typeof p.yr === "number") {
      return { xr: p.xr, yr: p.yr };
    }
  } catch {
    // corrupt / unavailable storage — fall back to the default placement.
  }
  return null;
}

export function KitchenFireButton({
  orderId,
  disabled,
  refreshSignal,
}: KitchenFireButtonProps): ReactNode {
  const { t } = useTranslation();
  const [status, setStatus] = useState<PrintStatusResult | null>(null);
  const [firing, setFiring] = useState(false);

  const fabRef = useRef<HTMLButtonElement>(null);
  const [pos, setPos] = useState<Pos | null>(null);
  // `dragging` drives only the visual (scale / grab cursor). It flips at most
  // twice per gesture — never read a ref during render to derive it.
  const [dragging, setDragging] = useState(false);
  const posRef = useRef<Pos | null>(null);
  const dragRef = useRef<{
    startX: number;
    startY: number;
    originX: number;
    originY: number;
    moved: boolean;
    // Bounds captured once at pointer-down so the drag never re-measures the
    // parent mid-gesture — a transient/zero re-measure used to snap the FAB
    // to a rim. Stable bounds = the FAB tracks the pointer 1:1.
    maxX: number;
    maxY: number;
  } | null>(null);

  const commitPos = useCallback((p: Pos) => {
    posRef.current = p;
    setPos(p);
  }, []);

  const refreshStatus = useCallback(async () => {
    if (!orderId || !workstationPrintService.enabled) return;
    try {
      const s = await workstationPrintService.getPrintStatus({ orderId });
      setStatus(s);
    } catch {
      // status probe failure is non-fatal — keep the previous state so the
      // dot/badge don't flicker between online / unknown.
    }
  }, [orderId]);

  // Re-probe on mount, when the order changes, and whenever its line items
  // change (`refreshSignal`) — so adding/voiding a line flips the FAB back
  // from "all sent" to "N pending" right away, not after the 30s poll.
  useEffect(() => {
    refreshStatus();
    if (!orderId) return;
    const id = setInterval(refreshStatus, STATUS_POLL_MS);
    return () => clearInterval(id);
  }, [orderId, refreshSignal, refreshStatus]);

  // Initial placement + keep the FAB inside the sidebar as it resizes.
  const parentEl = useCallback((): HTMLElement | null => {
    const el = fabRef.current;
    return (el?.offsetParent as HTMLElement | null) ?? el?.parentElement ?? null;
  }, []);

  useEffect(() => {
    const parent = parentEl();
    if (!parent) return;
    const place = () => {
      const rect = parent.getBoundingClientRect();
      // Hidden / not-yet-laid-out (tab switch, closed Sheet, pre-layout):
      // measuring 0 would collapse the bounds and pin the FAB to a rim. Skip
      // entirely — a real measurement arrives via the ResizeObserver later.
      if (rect.width <= 0 || rect.height <= 0) return;
      const maxX = Math.max(EDGE_MARGIN, rect.width - FAB_SIZE - EDGE_MARGIN);
      const maxY = Math.max(EDGE_MARGIN, rect.height - FAB_SIZE - EDGE_MARGIN);
      const current = posRef.current;
      if (current) {
        // Only nudge if the saved spot is actually out of the new bounds —
        // otherwise leave it exactly where the user dropped it (no snapping).
        const cx = clamp(current.x, EDGE_MARGIN, maxX);
        const cy = clamp(current.y, EDGE_MARGIN, maxY);
        if (cx !== current.x || cy !== current.y) commitPos({ x: cx, y: cy });
        return;
      }
      const saved = loadSavedRatio();
      if (saved) {
        commitPos({
          x: clamp(saved.xr * (rect.width - FAB_SIZE), EDGE_MARGIN, maxX),
          y: clamp(saved.yr * (rect.height - FAB_SIZE), EDGE_MARGIN, maxY),
        });
        return;
      }
      // First-ever placement: lower-right, but lifted clear of the checkout
      // footer so it doesn't cover the pay button. Inset from the rim by the
      // edge margin. The user drags it wherever they prefer afterwards.
      commitPos({
        x: maxX,
        y: clamp(rect.height * 0.62, EDGE_MARGIN, maxY),
      });
    };
    place();
    const ro = new ResizeObserver(place);
    ro.observe(parent);
    return () => ro.disconnect();
  }, [parentEl, commitPos]);

  /**
   * Mode A: the shop set "thanh toán trước khi chuẩn bị món", and the
   * workstation says this takeaway order has not been settled. Firing now
   * closes the unprinted delta, so the fire that runs when payment lands has
   * nothing left to send — and the only sheet the kitchen ever gets is the one
   * stamped CHUA TRA, carried out with the food.
   *
   * `=== true` on purpose. The field is absent on dine-in orders, on older
   * workstations, and whenever the status probe failed (the previous status is
   * kept). Treating any of those as "wait" would grey out the button for shops
   * this rule does not apply to.
   */
  const awaitingPrepayment = status?.order?.awaiting_prepayment === true;

  const fire = useCallback(async () => {
    if (!orderId || firing || disabled) return;
    // A silent no-op here would read as a broken button. Say why — the cashier
    // can act on it (take the payment), which is the whole point of the rule.
    if (awaitingPrepayment) {
      toast.info(t("pos.kitchen.awaiting_prepayment"));

      return;
    }
    setFiring(true);
    try {
      const res = await workstationPrintService.printKitchenTicket({ orderId });
      if (res.status === "ok") {
        toast.success(t("pos.kitchen.sent_n_items", { n: String(res.printed) }));
      } else if (res.status === "partial") {
        // The items reached the kitchen (KDS) even on a partial. When the only
        // reason is "no printer configured" (KDS-only kitchen), say so plainly
        // instead of a vague warning — nothing actually went wrong.
        const errs = res.errors ?? [];
        const allNoPrinter =
          errs.length > 0 && errs.every((e) => e.reason?.startsWith("no_printer"));
        toast.warning(
          allNoPrinter
            ? t("pos.kitchen.sent_kds_no_printer", { n: String(res.printed) })
            : t("pos.kitchen.sent_n_items", { n: String(res.printed) }),
        );
      }
      refreshStatus();
    } catch (err) {
      if (err instanceof ApiError) {
        if (err.status === 503 && err.body.status === "no_printer") {
          toast.error(t("pos.kitchen.no_printer"));
        } else if (err.status === 504) {
          toast.warning(t("pos.kitchen.sync_pending"));
        } else if (err.status === 422) {
          toast.info(t("pos.kitchen.all_printed"));
        } else {
          toast.error(t("pos.kitchen.fire_failed"));
        }
      } else {
        toast.error(t("pos.kitchen.fire_failed"));
      }
    } finally {
      setFiring(false);
    }
  }, [orderId, firing, disabled, awaitingPrepayment, refreshStatus, t]);

  const onPointerDown = useCallback(
    (e: ReactPointerEvent<HTMLButtonElement>) => {
      if (disabled) return;
      const cur = posRef.current;
      const parent = parentEl();
      if (!cur || !parent) return;
      const rect = parent.getBoundingClientRect();
      if (rect.width <= 0 || rect.height <= 0) return;
      fabRef.current?.setPointerCapture(e.pointerId);
      dragRef.current = {
        startX: e.clientX,
        startY: e.clientY,
        originX: cur.x,
        originY: cur.y,
        moved: false,
        maxX: Math.max(EDGE_MARGIN, rect.width - FAB_SIZE - EDGE_MARGIN),
        maxY: Math.max(EDGE_MARGIN, rect.height - FAB_SIZE - EDGE_MARGIN),
      };
    },
    [disabled, parentEl],
  );

  const onPointerMove = useCallback(
    (e: ReactPointerEvent<HTMLButtonElement>) => {
      const ds = dragRef.current;
      if (!ds) return;
      const dx = e.clientX - ds.startX;
      const dy = e.clientY - ds.startY;
      if (!ds.moved && Math.hypot(dx, dy) <= TAP_THRESHOLD) return;
      if (!ds.moved) setDragging(true);
      ds.moved = true;
      // Bounds captured at pointer-down — no mid-drag re-measure, so the FAB
      // follows the pointer exactly and never jumps to a rim.
      commitPos({
        x: clamp(ds.originX + dx, EDGE_MARGIN, ds.maxX),
        y: clamp(ds.originY + dy, EDGE_MARGIN, ds.maxY),
      });
    },
    [commitPos],
  );

  const endDrag = useCallback(
    (e: ReactPointerEvent<HTMLButtonElement>, cancelled: boolean) => {
      const ds = dragRef.current;
      dragRef.current = null;
      setDragging(false);
      if (fabRef.current?.hasPointerCapture(e.pointerId)) {
        fabRef.current.releasePointerCapture(e.pointerId);
      }
      if (!ds || cancelled) return;
      if (!ds.moved) {
        // Treated as a tap → fire.
        fire();
        return;
      }
      // Persist the dropped position as a ratio of the draggable area.
      const parent = parentEl();
      const cur = posRef.current;
      if (parent && cur) {
        const rect = parent.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) return;
        const xr = cur.x / Math.max(1, rect.width - FAB_SIZE);
        const yr = cur.y / Math.max(1, rect.height - FAB_SIZE);
        try {
          localStorage.setItem(
            POSITION_STORAGE_KEY,
            JSON.stringify({ xr, yr }),
          );
        } catch {
          // best-effort persistence; ignore storage failures.
        }
      }
    },
    [fire, parentEl],
  );

  const onKeyDown = useCallback(
    (e: ReactKeyboardEvent<HTMLButtonElement>) => {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        fire();
      }
    },
    [fire],
  );

  if (!workstationPrintService.enabled) return null;
  if (!orderId) return null;

  const pendingCount = status?.order?.open_items_pending_print ?? 0;
  const stale = (status?.sync?.cursor_age_s ?? 0) > 30;
  const nothingToFire = pendingCount === 0;
  const kitchenOnline = !!status?.printer_roles?.kitchen_printer?.online;

  const dotClass = stale
    ? "bg-amber-400"
    : kitchenOnline
      ? "bg-emerald-400"
      : "bg-rose-500";

  // Title tooltip: printer reachability at a glance (no extra DOM clutter).
  const tooltipParts: string[] = [];
  if (status) {
    const roles = status.printer_roles;
    tooltipParts.push(
      `${t("pos.printer.kitchen")}: ${
        roles.kitchen_printer?.online
          ? t("pos.printer.online")
          : t("pos.printer.offline")
      }`,
    );
    if (roles.bar_printer?.configured) {
      tooltipParts.push(
        `${t("pos.printer.bar")}: ${
          roles.bar_printer?.online
            ? t("pos.printer.online")
            : t("pos.printer.offline")
        }`,
      );
    }
    if (stale) tooltipParts.push(t("pos.printer.stale"));
  }
  if (awaitingPrepayment) tooltipParts.unshift(t("pos.kitchen.awaiting_prepayment"));
  const tooltip = tooltipParts.length
    ? `${t("pos.kitchen.send")} — ${tooltipParts.join(" · ")}`
    : t("pos.kitchen.send");

  return (
    <button
      ref={fabRef}
      type="button"
      data-slot="kitchen-fire-button"
      title={tooltip}
      aria-label={t("pos.kitchen.send")}
      style={pos ? { left: pos.x, top: pos.y } : undefined}
      onPointerDown={onPointerDown}
      onPointerMove={onPointerMove}
      onPointerUp={(e) => endDrag(e, false)}
      onPointerCancel={(e) => endDrag(e, true)}
      onKeyDown={onKeyDown}
      className={cn(
        "absolute z-30 flex size-14 touch-none select-none items-center justify-center rounded-full text-white shadow-lg ring-1 transition-[background-color,box-shadow]",
        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2",
        nothingToFire && !firing
          ? "bg-emerald-600 ring-emerald-700/40 hover:bg-emerald-700 focus-visible:ring-emerald-500/50"
          : "bg-amber-600 ring-amber-700/40 hover:bg-amber-700 focus-visible:ring-amber-500/50",
        dragging
          ? "scale-105 cursor-grabbing shadow-xl"
          : "cursor-grab active:scale-95",
        !pos
          ? "pointer-events-none opacity-0"
          : disabled
            ? "pointer-events-none opacity-50"
            : // Dimmed but still interactive on purpose: `pointer-events-none`
              // would swallow both the tooltip and the tap that explains WHY
              // the button is waiting, leaving a grey circle with no story.
              awaitingPrepayment
              ? "opacity-50"
              : "opacity-100",
      )}
    >
      {firing ? (
        <Spinner className="size-6 text-white" />
      ) : nothingToFire ? (
        <CheckIcon className="size-6" strokeWidth={2.5} />
      ) : (
        <ChefHatIcon className="size-6" />
      )}

      {/* Pending-count badge — only while there are unprinted items. */}
      {!firing && !nothingToFire && (
        <span className="absolute -right-1.5 -top-1.5 inline-flex min-w-[1.35rem] items-center justify-center rounded-full border-2 border-card bg-white px-1 text-xs font-bold tabular-nums text-amber-700 shadow-sm">
          {pendingCount > 99 ? "99+" : pendingCount}
        </span>
      )}

      {/* Kitchen-printer reachability dot. */}
      {status && !firing && (
        <span
          aria-hidden
          className={cn(
            "absolute -bottom-0.5 -right-0.5 size-3.5 rounded-full border-2 border-card",
            dotClass,
          )}
        />
      )}
    </button>
  );
}
