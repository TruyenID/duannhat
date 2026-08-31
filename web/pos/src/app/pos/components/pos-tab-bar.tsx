/**
 * PosTabBar — Chrome-style row of open orders.
 *
 * Each tab uses the `chrome-tab` utility from globals.css for the
 * trapezoidal silhouette (curved "ears" at the bottom corners on the
 * active tab so it merges into the content surface below). Tabs share
 * the available width evenly (flex-1 with min/max bounds) so they shrink
 * as more orders open, just like Chrome.
 *
 * Counts and totals derive from the parent's getOrder(orderId) lookup so
 * React Query is the single source of truth (plan-007 Decision 2).
 */

import {
  useEffect,
  useMemo,
  useRef,
  useState,
  type PointerEvent as ReactPointerEvent,
  type WheelEvent,
} from "react";
import { useParams } from "react-router-dom";
import { LayoutGridIcon, PlusIcon, ShoppingBagIcon, XIcon } from "lucide-react";
import { useTables } from "@/hooks/api/use-tables";
import { Button } from "@godxjp/ui";
import { cn } from "@/lib/utils";
import { HelpButton } from "@/help/help-button";
import { useTranslation } from "@/providers/app-provider";
import type { PosTab } from "../hooks/use-pos-tabs";
import type { CustomerOrder, TableResource } from "../types";
import { resolveTabLabel, tableLabelsByOrderId } from "../lib/tab-label";

export const OVERVIEW_TAB_ID = "__overview__";
export const TAKEAWAY_TAB_ID = "__takeaway__";

export interface PosTabBarProps {
  tabs: PosTab[];
  activeTabId: string | null;
  /** Parent resolves tab → order (from React Query cache or fresh fetch). */
  getOrder: (orderId: string) => CustomerOrder | undefined;
  onSelect: (tabId: string) => void;
  onClose: (tabId: string) => void;
  onCreate: () => void;
  /** Active takeaway-order count — drives the pinned Takeaway tab's badge. */
  takeawayCount?: number;
  /**
   * Feed bàn của màn sơ đồ — nguồn PHỤ để đặt tên tab theo bàn.
   *
   * Cần nó vì `GET /pos/orders` KHÔNG trả `tables` (đo trên API thật: `[]` cả
   * với đơn `dine_in` đang ngồi bàn), mà dải tab thì vẽ mọi tab kể cả tab chưa
   * nạp chi tiết — và tab sống qua reload. Xem `tableLabelsByOrderId`.
   *
   * Bỏ trống thì tab lùi về mã đơn: kém thông tin, không sai.
   */
  tables?: readonly TableResource[];
  className?: string;
}

const CLOSE_ANIM_MS = 180;

function statusDot(status: CustomerOrder["status"] | undefined): string | null {
  switch (status) {
    case "checkout":
    case "paying":
      return "bg-amber-400";
    case "closed":
      return "bg-emerald-400";
    case "voided":
      return "bg-red-400";
    default:
      return null;
  }
}

export function PosTabBar({
  tabs,
  activeTabId,
  getOrder,
  onSelect,
  onClose,
  onCreate,
  takeawayCount = 0,
  tables,
  className,
}: PosTabBarProps) {
  const { t } = useTranslation();

  // `orderId → "A-1, A-2"`, dựng lại chỉ khi feed bàn đổi. Xem
  // `tableLabelsByOrderId` để biết vì sao dải tab cần feed này chứ không đọc
  // được `order.tables` cho mọi tab.
  const tableLabels = useMemo(() => tableLabelsByOrderId(tables), [tables]);

  // Tabs marked as "closing" play a width/opacity collapse before the
  // parent removes them from state. This gives the Chrome-style exit feel.
  const [closingIds, setClosingIds] = useState<Set<string>>(() => new Set());
  const closeTimers = useRef<Map<string, number>>(new Map());

  function handleClose(tabId: string) {
    if (closingIds.has(tabId)) return;
    setClosingIds((prev) => {
      const next = new Set(prev);
      next.add(tabId);
      return next;
    });
    const timer = window.setTimeout(() => {
      onClose(tabId);
      closeTimers.current.delete(tabId);
      setClosingIds((prev) => {
        if (!prev.has(tabId)) return prev;
        const next = new Set(prev);
        next.delete(tabId);
        return next;
      });
    }, CLOSE_ANIM_MS);
    closeTimers.current.set(tabId, timer);
  }

  // Clean up any pending close timers on unmount.
  useEffect(() => {
    const timers = closeTimers.current;
    return () => {
      timers.forEach((id) => window.clearTimeout(id));
      timers.clear();
    };
  }, []);

  // Translate vertical wheel delta → horizontal scroll so staff can use a
  // plain mouse wheel to reveal off-screen tabs.
  function handleWheel(e: WheelEvent<HTMLDivElement>) {
    if (e.deltaY !== 0 && e.deltaX === 0) {
      e.currentTarget.scrollLeft += e.deltaY;
    }
  }

  // Click-and-drag to pan the tab strip horizontally (mouse only — touch keeps
  // native momentum scrolling). A tab click is suppressed if the pointer moved
  // past the threshold so dragging never accidentally switches orders.
  const scrollRef = useRef<HTMLDivElement | null>(null);
  const drag = useRef<{
    startX: number;
    startScroll: number;
    moved: boolean;
    pointerId: number;
  } | null>(null);
  const suppressClick = useRef(false);
  const [dragging, setDragging] = useState(false);

  function handlePointerDown(e: ReactPointerEvent<HTMLDivElement>) {
    if (e.pointerType !== "mouse" || e.button !== 0) return;
    const el = scrollRef.current;
    if (!el) return;
    drag.current = {
      startX: e.clientX,
      startScroll: el.scrollLeft,
      moved: false,
      pointerId: e.pointerId,
    };
  }

  function handlePointerMove(e: ReactPointerEvent<HTMLDivElement>) {
    const d = drag.current;
    const el = scrollRef.current;
    if (!d || !el) return;
    const dx = e.clientX - d.startX;
    if (!d.moved) {
      if (Math.abs(dx) <= 6) return; // below threshold — still a click
      d.moved = true;
      setDragging(true);
      el.setPointerCapture?.(d.pointerId);
    }
    el.scrollLeft = d.startScroll - dx;
  }

  function endDrag() {
    const d = drag.current;
    drag.current = null;
    if (!d) return;
    if (d.moved) {
      // Swallow the click that terminates the drag so the tab under the
      // cursor isn't selected. Reset happens in the capture handler.
      suppressClick.current = true;
      scrollRef.current?.releasePointerCapture?.(d.pointerId);
    }
    setDragging(false);
  }

  // Keep the active tab in view when the active id changes.
  const tabRefs = useRef<Map<string, HTMLDivElement | null>>(new Map());
  useEffect(() => {
    if (!activeTabId) return;
    const node = tabRefs.current.get(activeTabId);
    // Optional-chained: jsdom (tests) and some embedded webviews don't
    // implement scrollIntoView, and it must never crash the tab bar.
    node?.scrollIntoView?.({
      behavior: "smooth",
      block: "nearest",
      inline: "nearest",
    });
  }, [activeTabId]);

  return (
    <div
      data-slot="pos-tab-bar"
      className={cn(
        "chrome-tab-bar relative flex items-end gap-1.5 px-1.5 pt-1.5 sm:gap-2 sm:px-3 sm:pt-2",
        className,
      )}
    >
      <div
        ref={scrollRef}
        onWheel={handleWheel}
        onPointerDown={handlePointerDown}
        onPointerMove={handlePointerMove}
        onPointerUp={endDrag}
        onPointerCancel={endDrag}
        onClickCapture={(e) => {
          if (suppressClick.current) {
            suppressClick.current = false;
            e.stopPropagation();
            e.preventDefault();
          }
        }}
        className={cn(
          "flex min-w-0 flex-1 items-end overflow-x-auto px-1 pb-0 select-none sm:px-2 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden",
          dragging ? "cursor-grabbing [&_*]:!cursor-grabbing" : "cursor-grab",
        )}
      >
        {/* Pinned overview tab — always present, no close button. */}
        <div
          data-slot="pos-tab"
          data-active={activeTabId === OVERVIEW_TAB_ID}
          data-overview="true"
          className={cn(
            "chrome-tab group flex h-9 shrink-0 items-center gap-1.5 px-2.5 text-[13px] sm:px-3",
            activeTabId === OVERVIEW_TAB_ID
              ? "mb-[-1px] pb-[1px] text-foreground"
              : "text-muted-foreground hover:text-foreground",
          )}
        >
          <button
            type="button"
            onClick={() => onSelect(OVERVIEW_TAB_ID)}
            className="flex cursor-pointer items-center gap-1.5 transition-colors hover:text-foreground focus:outline-none"
            title={t("pos.tab.overview_title")}
          >
            <LayoutGridIcon
              className={cn(
                "size-4 shrink-0 sm:size-3.5",
                activeTabId === OVERVIEW_TAB_ID ? "text-primary" : "",
              )}
            />
            {/* Label hidden on the smallest screens — icon stays. */}
            <span className="hidden font-semibold sm:inline">{t("pos.tab.overview")}</span>
          </button>
        </div>

        {/* Pinned takeaway tab — always present, no close button. Lists all
            takeaway orders (which have no table, so they never appear on the
            overview grid). Badge shows the live active-order count. */}
        <div
          data-slot="pos-tab"
          data-active={activeTabId === TAKEAWAY_TAB_ID}
          data-takeaway="true"
          className={cn(
            "chrome-tab group flex h-9 shrink-0 items-center gap-1.5 px-2.5 text-[13px] sm:px-3",
            activeTabId === TAKEAWAY_TAB_ID
              ? "mb-[-1px] pb-[1px] text-foreground"
              : "text-muted-foreground hover:text-foreground",
          )}
        >
          <button
            type="button"
            onClick={() => onSelect(TAKEAWAY_TAB_ID)}
            className="flex cursor-pointer items-center gap-1.5 transition-colors hover:text-foreground focus:outline-none"
            title={t("pos.overview.takeaway")}
          >
            <ShoppingBagIcon
              className={cn(
                "size-4 shrink-0 sm:size-3.5",
                activeTabId === TAKEAWAY_TAB_ID ? "text-primary" : "",
              )}
            />
            <span className="hidden font-semibold sm:inline">{t("pos.overview.takeaway")}</span>
            {takeawayCount > 0 && (
              <span
                className={cn(
                  "inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-bold tabular-nums",
                  activeTabId === TAKEAWAY_TAB_ID
                    ? "bg-primary text-primary-foreground"
                    : "bg-primary/15 text-primary",
                )}
              >
                {takeawayCount}
              </span>
            )}
          </button>
        </div>

        {tabs.map((tab, index) => {
          const active = tab.tabId === activeTabId;
          const closing = closingIds.has(tab.tabId);
          const order = getOrder(tab.orderId);
          const dot = statusDot(order?.status);
          // BÀN nếu đơn có bàn, MÃ ĐƠN nếu không — thu ngân nghĩ theo bàn, mã
          // chỉ là định danh chứng từ. Luật ở `resolveTabLabel`.
          const label = resolveTabLabel({
            order,
            fallbackCode: tab.label,
            orderId: tab.orderId,
            tableLabels,
          });
          const codeLabel =
            label.kind === "pending" ? t("pos.order.code_pending") : label.text;
          // Nhãn hiện là tên bàn thì MÃ ĐƠN biến mất khỏi màn hình — giữ nó ở
          // tooltip, nếu không thu ngân mất đường đối chiếu với phiếu in.
          const rawCode = (order?.order_code || tab.label || "").trim();
          const titleLabel =
            label.kind === "table" && rawCode
              ? `${label.text} · ${rawCode}`
              : codeLabel;
          const prev = tabs[index - 1];
          const showSeparator =
            index > 0 &&
            !active &&
            prev?.tabId !== activeTabId &&
            !closingIds.has(prev?.tabId ?? "");

          return (
            <div
              key={tab.tabId}
              ref={(el) => {
                if (el) tabRefs.current.set(tab.tabId, el);
                else tabRefs.current.delete(tab.tabId);
              }}
              data-slot="pos-tab"
              data-active={active}
              data-closing={closing}
              className={cn(
                "chrome-tab group flex h-9 shrink-0 items-center gap-1.5 overflow-hidden px-2 text-[12px] sm:px-2.5 sm:text-[13px]",
                // Content-sized (capped) so the FULL order code shows with NO
                // ellipsis; the strip scrolls (wheel / drag) when tabs overflow
                // instead of squeezing each tab to an unreadable sliver.
                "max-w-[240px]",
                // Sit flush with the content area below — overlap by 1px so
                // the seam disappears at sub-pixel rounding.
                active
                  ? "mb-[-1px] pb-[1px] text-foreground"
                  : "text-muted-foreground hover:text-foreground",
                // Exit animation when the tab is closing (max-width is the
                // transitioned property — see .chrome-tab in globals.css).
                closing && "pointer-events-none !max-w-0 !px-0 !opacity-0",
              )}
            >
              {showSeparator && <span className="chrome-tab-sep" aria-hidden />}

              <button
                type="button"
                onClick={() => onSelect(tab.tabId)}
                className="flex cursor-pointer items-center gap-1.5 whitespace-nowrap tabular-nums transition-colors hover:text-foreground focus:outline-none"
                title={titleLabel}
              >
                {dot && (
                  <span
                    className={cn(
                      "size-1.5 shrink-0 rounded-full",
                      dot,
                    )}
                  />
                )}
                {/* Tab KHÔNG có bàn (mang đi / chưa gán) mang màu riêng: giữa
                    một dải toàn tên bàn, chúng phải nhặt ra được bằng mắt chứ
                    không phải bằng cách đọc từng chữ.
                    `primary`, KHÔNG phải amber/emerald/red — ba màu đó đã thuộc
                    về chấm trạng thái ngay bên trái, dùng lại là làm nhiễu một
                    tín hiệu đang có nghĩa.
                    Màu KHÔNG phải tín hiệu duy nhất: "A-2" với "ORD-2026-3251"
                    khác nhau về hình dạng, nên người không phân biệt được màu
                    vẫn đọc ra. */}
                <span
                  className={cn(
                    "whitespace-nowrap font-medium",
                    label.kind !== "table" &&
                      (active ? "text-primary" : "text-primary/70"),
                  )}
                  data-tab-label={label.kind}
                >
                  {codeLabel}
                </span>
              </button>

              <button
                type="button"
                onClick={(e) => {
                  e.stopPropagation();
                  handleClose(tab.tabId);
                }}
                aria-label={t("pos.tab.close", { label: codeLabel })}
                className={cn(
                  "flex size-5 shrink-0 cursor-pointer items-center justify-center rounded-full text-muted-foreground/80 transition-all",
                  "hover:bg-foreground/10 hover:text-foreground",
                  // Chrome shows the X on the active tab always, and on
                  // hover for inactive tabs.
                  active
                    ? "opacity-100"
                    : "opacity-0 group-hover:opacity-100 focus-visible:opacity-100",
                )}
              >
                <XIcon className="size-3.5" />
              </button>
            </div>
          );
        })}
      </div>

      <Button
        type="button"
        size="sm"
        onClick={() => onCreate()}
        aria-label={t("pos.tab.create_order")}
        className="mb-2 size-9 shrink-0 gap-1.5 rounded-full p-0 sm:h-9 sm:w-auto sm:px-5"
      >
        <PlusIcon className="size-4" />
        {/* Label collapses to icon-only on the smallest screens. */}
        <span className="hidden sm:inline">{t("pos.tab.create_order")}</span>
      </Button>

      {/* Right of "+": what that button does depends on a shop setting
          (quick order), and closing a tab deletes an order outright. Both
          are surprises worth one tap of explanation. */}
      <HelpButton topic="pos-tabs" className="mb-2 shrink-0" />
    </div>
  );
}

/**
 * Bản NỐI DÂY của {@link PosTabBar} — tự lấy feed bàn, không nhận thêm prop.
 *
 * `page.tsx` đang NẰM ĐÚNG TRÊN trần 926 dòng
 * (`src/__tests__/page-size-budget.arch.test.ts`), nên thêm một prop ở đó là
 * đỏ; luật của chính rào ấy là "tính năng mới đi ra `components/`". Vì vậy dây
 * nối ở đây, còn `PosTabBar` giữ nguyên là component THUẦN — các bài test hiện
 * có dựng nó với mỗi `AppProvider`, không có QueryClient, và vẫn phải chạy.
 *
 * `useTables(shopSlug, {})` dùng CHUNG khoá truy vấn với lượt gọi ở `page.tsx`
 * (`tableKeys.list(shopSlug, {})`), nên TanStack dedupe — không phát sinh
 * request nào.
 */
export function PosTabBarConnected(
  props: Omit<PosTabBarProps, "tables">,
): React.ReactElement {
  const params = useParams<{ shopSlug: string }>();
  const tables = useTables(params.shopSlug ?? "", {}).data?.data;

  return <PosTabBar {...props} tables={tables} />;
}
