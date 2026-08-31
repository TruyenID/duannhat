/**
 * OrderCart — server-authoritative order detail, header actions (assign /
 * change / merge / unmerge tables, edit guest count, void order), inline
 * checkout draft panel, mutation error banner, and partial-swap recovery
 * Alert.
 *
 * Plan-007 Phase 1: totals come straight from the order resource; no client
 * side recomputation needed since the backend recalculates and returns the
 * full order on every item mutation. The bottom panel uses the original
 * 2-step checkout flow: click "Tính tiền" to open the draft form with raw
 * amount inputs, edit the values, then Cancel or Chốt đơn to commit.
 */

import { useEffect, useState } from "react";
import { ReopenOrderDialog } from "./reopen-order-dialog";
import { VoidOrderDialog } from "./void-order-dialog";
import {
  Alert,
  AlertDescription,
  Button,
  Popover,
  PopoverContent,
  PopoverTrigger,
  ScrollArea,
  Skeleton,
} from "@godxjp/ui";
import {
  AlertCircleIcon,
  ArmchairIcon,
  ArrowRightLeftIcon,
  CheckIcon,
  ChevronDownIcon,
  CreditCardIcon,
  MergeIcon,
  MinusIcon,
  PencilIcon,
  PlusIcon,
  PrinterIcon,
  ReceiptTextIcon,
  SplitIcon,
  Trash2Icon,
  UsersIcon,
  XIcon,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { HelpButton } from "@/help/help-button";
import { PromotionBadge } from "./promotion-badge";
import { KitchenFireButton } from "./kitchen-fire-button";
import { workstationPrintService } from "@/services/workstation-print-service";
import { toast } from "sonner";
import { useTranslation } from "@/providers/app-provider";
import type {
  CustomerOrder,
  OrderItemStatus,
  TaxBreakdownEntry,
} from "../types";
import {
  formatCurrency as sharedFormatCurrency,
  formatTaxAmount as sharedFormatTaxAmount,
} from "../lib/totals";
import { isProvisionalCode } from "../lib/order-code";
import { lineSubtotal } from "../lib/line-total";
import { collapseMirroredToppingName } from "../lib/topping-name";
import { orderItemDisplayName } from "../lib/order-item-name";
import { Spinner } from "@/components/ui/spinner";
import { ItemThumb } from "./item-thumb";
import { menuDisplayPrice, showsMoneyRow } from "../lib/tax-display";
import { orderTotals } from "../lib/order-totals";
import { joinTableNames } from "../lib/table-names";
import { parseCouponError } from "../lib/coupon";

/**
 * BR-SOS05 — POS no longer collects tax / service charge from staff. The
 * backend reads them off the branch's `ShopOrderSetting` (tax_rate +
 * service_charge_rate) and computes the amounts at checkout.
 *
 * plan-019 — `discount_amount` is no longer entered as a free-form number
 * either; it is driven by an applied coupon (fixed or percent with cap) via
 * `couponService->apply`. The cart simply forwards whatever the server has
 * computed in `order.discount_amount` so checkout stays a no-op confirm.
 */
export interface CheckoutDraft {
  discount_amount: number;
}

export interface PendingUnmerge {
  fromTableCode: string;
  toTableCode: string;
  message: string;
}

export interface OrderCartProps {
  order: CustomerOrder | undefined;
  isLoading: boolean;
  /** Most-recent mutation error message; null when clear. */
  errorMessage: string | null;
  onDismissError: () => void;
  /** Pending unmerge retry (step-2 of change-table) — parent owns retrying. */
  pendingUnmerge: PendingUnmerge | null;
  onRetryUnmerge: () => void;
  onDismissPendingUnmerge: () => void;
  onAddItem: (productSkuId: string) => void;
  onChangeQty: (itemId: string, next: number) => void;
  onUpdateItemStatus: (
    itemId: string,
    status: Exclude<OrderItemStatus, "voided">,
  ) => void;
  /** Open VoidItemDialog with this item's label. */
  onVoidItem: (itemId: string, label: string) => void;
  /** Plan 016 — open ProductOptionsDialog in `edit` mode for a pending line. */
  onEditItemToppings: (itemId: string) => void;
  /**
   * Chuyển đơn sang `checkout`. **Phải trả về true khi và chỉ khi đơn đã
   * chuyển thật** — giỏ dùng giá trị đó để quyết định có mở màn thu tiền hay
   * không, và `POST /payments` 409 nếu đơn còn `open`.
   */
  onCheckout: (draft: CheckoutDraft) => Promise<boolean>;
  /**
   * Gate firing the moment staff clicks the "Tính tiền" button — before the
   * inline draft form opens. Return false to block (the parent shows its own
   * toast explaining why; the cart stays in its current view). Used to
   * enforce business rules like "every item must be served before billing".
   * Omitted = always allow.
   */
  onAttemptCheckout?: () => boolean;
  /**
   * plan-019 — apply a coupon to the active order. Resolves on success;
   * the parent's mutation already cache-sets the response, so the cart
   * re-renders with the new `discount_amount`. On a 422 ApiError, the
   * caller surfaces the structured `error_code` via `onCouponError`.
   *
   * `downgradeExclusivePromotions=true` opts into the backend's
   * "revert HH lines to original_unit_price before apply" flow. Used by
   * the CouponRow when staff picks "Use coupon instead of promotion"
   * on the conflict alert.
   */
  onApplyCoupon: (
    code: string,
    opts?: { downgradeExclusivePromotions?: boolean },
  ) => Promise<void>;
  onReleaseCoupon: () => Promise<void>;
  onPay: () => void;
  onSplitBill: () => void;
  onVoid: (reason: string) => Promise<void>;
  /**
   * #2479 — mở lại bill chốt nhầm. Hộp thoại lý do do CHÍNH OrderCart giữ, không
   * phải page.tsx: file đó đang nằm đúng trên trần 926 dòng, nên mọi state/JSX
   * mới phải ra khỏi nó (xem `page-size-budget.arch.test.ts`).
   */
  onReopen: (reason: string) => Promise<void>;
  /**
   * Accept a customer-submitted takeaway (pending|confirmed → open) — shows
   * the "Tiếp nhận đơn" CTA in the footer for those statuses. Omit to hide.
   */
  onAcceptOrder?: () => void;
  /** True while the accept mutation is in flight — disables the CTA. */
  acceptPending?: boolean;
  onAssignTable: () => void;
  onEditGuestCount: () => void;
  onChangeTable: () => void;
  onMergeTable: () => void;
  onUnmergeTable: () => void;
  /**
   * BR-SOS05 — branch-level service charge rate (%) from ShopOrderSetting.
   * Used for the live service-charge preview while staff edits the discount
   * in checkout draft mode. plan-043 — the tax preview no longer needs a rate:
   * per-rate tax is read straight from the server's `order.tax_breakdown`
   * (recomputed + cache-set on every coupon apply/release).
   */
  serviceChargeRate?: number;
  /**
   * plan-043 — the shop's 総額表示 (`prices_include_tax`) DISPLAY flag, sourced
   * from the same ShopOrderSetting the menu reads. When true the cart presents
   * line prices + subtotal + service charge as 税込 (gross) so they match the
   * menu card the operator tapped, with the per-rate tax shown as 内消費税
   * (informational). It does NOT change what the order is charged — the total
   * stays `order.total_amount` — only how the breakdown is displayed. This is
   * distinct from `order.is_tax_included` (the engine's entry-basis snapshot).
   */
  pricesIncludeTax?: boolean;
  /**
   * plan-051 (#1149) — the shop's per-status void matrix, resolved by
   * resolveVoidableStatuses (settings list → legacy flag fallback).
   * `pending` is always present. Default = pending-only.
   */
  voidableStatuses?: readonly OrderItemStatus[];
  /** Currency code for the 税込 rounding step (JPY/VND = integer). */
  currencyCode?: string | null;
  className?: string;
}

const ITEM_STATUS_CLASS: Record<OrderItemStatus, string> = {
  pending: "bg-muted text-muted-foreground",
  preparing:
    "bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300",
  ready: "bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300",
  served:
    "bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300",
  voided: "bg-destructive/10 text-destructive",
};

const ITEM_STATUS_I18N: Record<OrderItemStatus, string> = {
  pending: "pos.item_status.pending",
  preparing: "pos.item_status.preparing",
  ready: "pos.item_status.ready",
  served: "pos.item_status.served",
  voided: "pos.item_status.voided",
};

const SELECTABLE_STATUS_KEYS: Exclude<OrderItemStatus, "voided">[] = [
  "pending",
  "preparing",
  "ready",
  "served",
];

function formatCurrency(v: number | string | null | undefined): string {
  return sharedFormatCurrency(Number(v ?? 0));
}

/**
 * plan-045 option-B — format a TAX figure with the order's `tax_rounding_decimals`
 * so a sub-unit tax (¥93.50 on a decimals=2 JPY shop) shows in full, while
 * whole-yen figures (subtotal, total) keep using {@link formatCurrency}.
 */
function formatTax(
  v: number | string | null | undefined,
  decimals?: number | null,
): string {
  return sharedFormatTaxAmount(Number(v ?? 0), decimals);
}

/** subtotal × pct / 100, rounded to integer (matches backend amount precision). */
function pctToAmount(subtotal: number, pct: number): number {
  if (!Number.isFinite(pct) || pct <= 0) return 0;
  return Math.round((subtotal * pct) / 100);
}

/**
 * Derive percentage from saved amount + subtotal. Used to seed the draft
 * form's pct inputs from the order's already-saved values so reopening
 * the form mid-edit reflects what's currently on the server. Returns 0
 * when subtotal is 0 to avoid div-by-zero. One decimal of precision so
 * a 5.0% tax doesn't render as "5.000001".
 */
/**
 * Local-to-component draft model. plan-019: discount is no longer a
 * staff-entered percent — it is determined by the applied coupon. The
 * draft state now carries the in-progress coupon code input (before
 * Apply is pressed) and any structured error_code returned by the
 * backend's CouponException so the cart can render a localized message.
 */
type DraftPct = {
  couponInput: string;
  couponError: { code: string; meta?: Record<string, unknown> } | null;
  couponPending: boolean;
};

export function OrderCart({
  order,
  isLoading,
  errorMessage,
  onDismissError,
  pendingUnmerge,
  onRetryUnmerge,
  onDismissPendingUnmerge,
  onChangeQty,
  onUpdateItemStatus,
  onVoidItem,
  onEditItemToppings,
  onCheckout,
  onAttemptCheckout,
  onApplyCoupon,
  onReleaseCoupon,
  onPay,
  onSplitBill,
  onVoid,
  onReopen,
  onAcceptOrder,
  acceptPending = false,
  onAssignTable,
  onEditGuestCount,
  onChangeTable,
  onMergeTable,
  onUnmergeTable,
  serviceChargeRate = 0,
  pricesIncludeTax = false,
  voidableStatuses = ["pending"],
  currencyCode,
  className,
}: OrderCartProps) {
  const { t } = useTranslation();
  const [showVoided, setShowVoided] = useState(false);
  // Full-order QR bill for the customer ("in phiếu order") — on-demand button,
  // unlimited reprints (distinct from the per-fire delta slips).
  const [printingBill, setPrintingBill] = useState(false);
  async function handlePrintOrderBill() {
    if (!order || printingBill) return;
    setPrintingBill(true);
    try {
      const res = await workstationPrintService.printOrderBill({
        orderId: order.id,
      });
      if (res.status === "no_printer") {
        toast.warning(t("pos.cart.print_order_bill_no_printer"));
      } else {
        toast.success(t("pos.cart.print_order_bill_ok"));
      }
    } catch {
      toast.error(t("pos.cart.print_order_bill_error"));
    } finally {
      setPrintingBill(false);
    }
  }
  // Per-item topping expansion. Item ids in this set show the full topping
  // list; the rest collapse to the first 2 entries + a "show more" toggle.
  // Default-collapsed keeps cards compact when many modifiers are stacked.
  const [expandedToppings, setExpandedToppings] = useState<Set<string>>(
    () => new Set(),
  );
  const toggleExpandedToppings = (id: string) =>
    setExpandedToppings((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  // 2-step checkout flow: null = button visible; non-null = draft form
  // visible with the user's in-progress percentage inputs. Cleared on
  // Confirm (which converts pct→amounts and fires onCheckout) or Cancel.
  const [draft, setDraft] = useState<DraftPct | null>(null);

  /**
   * Một chạm: chốt đơn RỒI mở luôn màn thu tiền.
   *
   * Trước đây thu ngân phải bấm ba lần — "Tính tiền" (chỉ mở form mã giảm giá,
   * không gọi server) → "Chốt đơn" (`POST /checkout`) → "Thu tiền" (chỉ
   * `setPaymentOpen`). Chạm 1 và 3 không mang quyết định nào; chạm 2 mới là
   * chuyển trạng thái thật. Giờ cả ba gói vào một hành động, còn mã giảm giá
   * lùi thành đường nhánh (`onOpenDraft`).
   *
   * Ba điều bắt buộc, đảo lại là hỏng:
   *
   * 1. **Chỉ mở màn thu tiền khi checkout THÀNH CÔNG.** `POST /payments` trả 409
   *    `Order not in checkout/paying status` — mở màn tiền trên một đơn còn
   *    `open` là đẩy thu ngân vào lỗi giữa lúc khách đứng chờ.
   * 2. **Khoá trong lúc đang gọi.** `checkout` chỉ nhận `open|dining`; cú chạm
   *    thứ hai trên tablet cảm ứng sẽ 409. Cờ `checkoutPending` chặn ngay tại
   *    nguồn thay vì để người dùng gặp lỗi.
   * 3. **Guard chạy TRƯỚC.** Còn món chưa phục vụ thì dừng ở đây, đúng như
   *    hành vi cũ của nút "Tính tiền".
   */
  const [checkoutPending, setCheckoutPending] = useState(false);

  const [reopenOpen, setReopenOpen] = useState(false);
  const [voidOpen, setVoidOpen] = useState(false);

  // Đổi đơn (đổi tab) thì đóng cả hai hộp thoại. Trước #2479 việc này do
  // `closeAllDialogs()` ở page.tsx làm; state đã dời vào đây nên hành vi phải
  // dời theo — bỏ quên nó là hộp thoại huỷ của đơn CŨ còn mở trên đơn MỚI, và
  // lý do thu ngân vừa gõ sẽ áp lên nhầm bill.
  useEffect(() => {
    setVoidOpen(false);
    setReopenOpen(false);
  }, [order?.id]);

  async function checkoutAndPay(): Promise<void> {
    if (!order || checkoutPending) return;
    if (onAttemptCheckout && !onAttemptCheckout()) return;

    setCheckoutPending(true);
    try {
      const moved = await onCheckout({
        // plan-019 — discount_amount đã nằm trên đơn (couponService::apply ghi
        // nguyên tử). Truyền lại chỉ để giữ nguyên contract; backend bỏ qua giá
        // trị client gửi khi đơn đang gắn coupon.
        discount_amount: Number(order.discount_amount ?? 0),
      });
      if (!moved) return;
      setDraft(null);
      onPay();
    } finally {
      setCheckoutPending(false);
    }
  }

  // Default view hides voided items so the cart stays clean; the row is
  // soft-voided server-side (status=voided, void_reason stored), never
  // deleted. A toggle below the list expands the voided rows for audit.
  const allItems = order?.items ?? [];
  const activeItems = allItems.filter((i) => i.status !== "voided");
  const voidedItems = allItems.filter((i) => i.status === "voided");
  const visibleItems = showVoided
    ? [...activeItems, ...voidedItems]
    : activeItems;

  // plan-043 — present line prices as 税込 (gross) only when BOTH (a) the shop
  // displays tax-included prices (総額表示) AND (b) the engine stored the line
  // net (is_tax_included=false, the system's invariant). If a snapshot ever
  // carried gross already (is_tax_included=true), the stored amount IS the gross
  // — re-multiplying would double-count — so we show it as-is, which is already
  // the 税込 figure. menuDisplayPrice below is a no-op when this is false.
  const showGrossLines = pricesIncludeTax && !order?.is_tax_included;

  if (!order && !isLoading) {
    return (
      <aside
        data-slot="order-cart"
        className={cn(
          "flex h-full w-full flex-col items-center justify-center bg-card p-6 text-center lg:border-l",
          className,
        )}
      >
        <ReceiptTextIcon className="size-10 text-muted-foreground/60" />
        <p className="mt-3 text-sm font-medium text-foreground">
          {t("pos.cart.empty")}
        </p>
        <p className="mt-1 max-w-60 text-xs text-muted-foreground">
          {t("pos.cart.empty_hint")}
        </p>
        {/* Also on the empty state: "why is there nothing here" is exactly the
            question a new operator has, and the header button above is gone. */}
        <HelpButton topic="order-cart" className="mt-3" withLabel />
      </aside>
    );
  }

  if (!order || isLoading) {
    return (
      <aside
        data-slot="order-cart"
        className={cn(
          "flex h-full w-full flex-col gap-3 bg-card p-6 lg:border-l",
          className,
        )}
      >
        <Skeleton className="h-8 w-40" />
        <Skeleton className="h-4 w-32" />
        <Skeleton className="h-9 w-full" />
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-24 w-full" />
      </aside>
    );
  }

  const locked = order.status !== "open";
  // Item mutations (add / edit / void) are also allowed on a `confirmed`
  // order — a counter-pay takeaway submitted from customer-web that staff
  // adjusts at the counter before taking payment. Cloud + workstation gates
  // accept the same pair. Order-HEADER actions (tables / guests / kitchen
  // fire / checkout) stay `open`-only via `locked` — their server gates
  // still 409 on anything else.
  const itemsEditable = order.status === "open" || order.status === "confirmed";
  const isCheckingOut =
    order.status === "checkout" || order.status === "paying";
  const empty = activeItems.length === 0;
  const tables = order.tables ?? [];
  // Cùng cách viết với dải tab (`A-3 + A-4`), qua cùng một hàm: hai nhãn của
  // CÙNG một đơn nằm cách nhau vài centimet trên màn hình, viết khác nhau là
  // bắt thu ngân tự đối chiếu.
  const tablesLabel = joinTableNames(tables) || null;
  const singleTable = tables.length === 1 ? tables[0] : null;
  const multiTable = tables.length >= 2;

  // Signature that changes on any order/line-item mutation. Drives
  // KitchenFireButton's print-status refresh so the "Gửi bếp" CTA re-probes
  // the workstation immediately — instead of staying stale on its 30s poll —
  // whenever staff adds, voids, re-quantifies, or edits (toppings/note) a line.
  //   - new id          → item added
  //   - id leaves list   → item voided (filtered out of activeItems)
  //   - per-item updated_at / qty → qty or topping/note edit
  //   - order.updated_at → any order-level change (totals recompute on add)
  // String equality is by value, so a render that doesn't change the order is
  // a no-op for the child effect.
  const kitchenFireSignal = [
    order.updated_at ?? "",
    ...activeItems.map((i) => `${i.id}:${i.quantity}:${i.updated_at ?? ""}`),
  ].join("|");

  return (
    <aside
      data-slot="order-cart"
      className={cn(
        "relative flex h-full min-h-0 flex-col overflow-hidden bg-card lg:border-l",
        className,
      )}
    >
      {/* HEADER --------------------------------------------------------- */}
      {/* Compact by design: the header + footer must not crowd out the item
          list. Secondary actions (debt lookup / print slip) live in a ⋯ menu
          instead of two full-width rows, and the paddings are tightened so the
          scrollable middle gets the most vertical room. */}
      <div className="shrink-0 space-y-2 border-b px-4 py-2.5">
        {/* Row 1: identity + table + guests + primary actions, all on ONE line.
            Table / guests are shown as icon + value (with tooltips) so the whole
            context fits without a second row; the table label truncates first if
            space runs short, the order code + guest count never do. */}
        <div className="flex items-center justify-between gap-2">
          <div className="flex min-w-0 items-center gap-2">
            {isProvisionalCode(order.order_code) ? (
              // plan-041 — LAN order awaiting its Cloud ORD-#### code. Show a
              // "đang cấp mã" badge instead of the internal provisional value;
              // the order.code_assigned socket event swaps in the real code.
              <span
                title={order.order_code}
                className="shrink-0 rounded-md border border-amber-300 bg-amber-50 px-2 py-0.5 text-sm font-medium text-amber-700 dark:border-amber-700/50 dark:bg-amber-950/40 dark:text-amber-400"
              >
                {t("pos.order.code_pending")}
              </span>
            ) : (
              <span className="shrink-0 rounded-md border bg-muted/60 px-2 py-0.5 text-sm font-medium tabular-nums text-foreground">
                {order.order_code}
              </span>
            )}

            <span className="h-4 w-px shrink-0 bg-border" aria-hidden />

            {/* Table */}
            <span
              className="flex min-w-0 items-center gap-1 text-sm"
              title={`${t("pos.cart.table_prefix")}: ${tablesLabel ?? t("pos.cart.no_table")}`}
            >
              <ArmchairIcon className="size-4 shrink-0 text-muted-foreground" />
              {tablesLabel ? (
                <span className="truncate font-semibold text-foreground">
                  {tablesLabel}
                </span>
              ) : (
                <span className="truncate text-muted-foreground">
                  {t("pos.cart.no_table")}
                </span>
              )}
            </span>

            {/* Guests */}
            <span
              className="flex shrink-0 items-center gap-1 text-sm"
              title={t("pos.cart.guests_label")}
            >
              <UsersIcon className="size-4 text-muted-foreground" />
              <span className="font-semibold tabular-nums text-foreground">
                {order.guest_count ?? "—"}
              </span>
              {!locked && (
                <button
                  type="button"
                  onClick={onEditGuestCount}
                  aria-label={t("pos.cart.guests_label")}
                  className="flex size-6 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                >
                  <PencilIcon className="size-3.5" />
                </button>
              )}
            </span>
          </div>

          <div className="flex shrink-0 items-center gap-1.5">
            <HelpButton topic="order-cart" className="size-7" />
            {/* #2479 — đặt NGAY CẠNH nút huỷ một cách có chủ ý: khi bill đã chốt
                nhầm, hai đường ra phải hiện cùng lúc. Chỉ thấy "Huỷ đơn" thì thu
                ngân sẽ bấm nó — và huỷ để lại dấu vết nặng hơn cho một việc vốn
                không phải sự cố. Chỉ ở `checkout`; `paying` nghĩa là tiền đã bắt
                đầu vào và đường ra lúc đó là hoàn tiền. */}
            {order.status === "checkout" && (
              <button
                type="button"
                onClick={() => setReopenOpen(true)}
                className="cursor-pointer rounded-md bg-muted px-2.5 py-1.5 text-xs font-bold uppercase tracking-wide text-foreground transition-colors hover:bg-muted/70"
              >
                {t("pos.cart.reopen_order")}
              </button>
            )}
            <VoidOrderDialog
              open={voidOpen}
              onOpenChange={setVoidOpen}
              orderCode={order.order_code}
              onConfirm={onVoid}
            />
            <ReopenOrderDialog
              open={reopenOpen}
              onOpenChange={setReopenOpen}
              orderCode={order.order_code}
              onConfirm={onReopen}
            />
            {(order.status === "open" ||
              order.status === "confirmed" ||
              order.status === "checkout" ||
              order.status === "paying") && (
              <button
                type="button"
                onClick={() => setVoidOpen(true)}
                className="cursor-pointer rounded-md bg-destructive/10 px-2.5 py-1.5 text-xs font-bold uppercase tracking-wide text-destructive transition-colors hover:bg-destructive/15"
              >
                {t("pos.cart.void_order")}
              </button>
            )}
          </div>
        </div>

        {/* Row 2: table actions — single Gán bàn when no table is assigned;
            otherwise the 3 ghép / đổi / tách actions. Disabled buttons fade out
            so staff can tell at a glance which apply to the current table count. */}
        {tables.length === 0 ? (
          <TableActionButton
            icon={<ArmchairIcon className="size-4" />}
            label={t("pos.cart.assign_table")}
            disabled={locked}
            onClick={onAssignTable}
          />
        ) : (
          <div className="grid grid-cols-3 gap-2">
            <TableActionButton
              icon={<MergeIcon className="size-4" />}
              label={t("pos.cart.merge_table")}
              disabled={locked}
              onClick={onMergeTable}
            />
            <TableActionButton
              icon={<ArrowRightLeftIcon className="size-4" />}
              label={t("pos.cart.change_table")}
              disabled={locked || !singleTable}
              onClick={onChangeTable}
            />
            <TableActionButton
              icon={<SplitIcon className="size-4" />}
              label={t("pos.cart.unmerge_table")}
              disabled={locked || !multiTable}
              onClick={onUnmergeTable}
            />
          </div>
        )}

        {/* Row 3: print the order slip.
            "Tra cứu nợ" used to sit beside it. It moved to the POS header —
            this cart early-returns without an order, so a shop-wide question
            ("who owes us money") could only be asked after creating an order
            for a customer who might not even be the debtor. */}
        {workstationPrintService.enabled && (
          <div className="grid grid-cols-1 gap-2">
            <TableActionButton
              icon={<PrinterIcon className="size-4" />}
              label={t("pos.cart.print_order_bill")}
              disabled={printingBill}
              onClick={handlePrintOrderBill}
            />
          </div>
        )}
      </div>

      {/* ERROR / PENDING-UNMERGE BANNERS -------------------------------- */}
      {errorMessage && (
        <Alert variant="destructive" className="mx-4 mt-3">
          <AlertCircleIcon className="size-4" />
          <AlertDescription className="flex items-center justify-between gap-2">
            <span>{errorMessage}</span>
            <Button
              variant="ghost"
              size="sm"
              className="h-6 w-6 p-0"
              onClick={onDismissError}
              aria-label={t("common.dismiss")}
            >
              <XIcon className="size-3" />
            </Button>
          </AlertDescription>
        </Alert>
      )}

      {pendingUnmerge && (
        <Alert variant="destructive" className="mx-4 mt-3">
          <AlertCircleIcon className="size-4" />
          <AlertDescription className="space-y-2">
            <div>
              {t("pos.cart.unmerge_pending", {
                toTable: pendingUnmerge.toTableCode,
                fromTable: pendingUnmerge.fromTableCode,
              })}{" "}
              {pendingUnmerge.message}
            </div>
            <div className="flex gap-2">
              <Button size="sm" variant="outline" onClick={onRetryUnmerge}>
                {t("pos.cart.retry_unmerge")}
              </Button>
              <Button
                size="sm"
                variant="ghost"
                onClick={onDismissPendingUnmerge}
              >
                {t("common.dismiss")}
              </Button>
            </div>
          </AlertDescription>
        </Alert>
      )}

      {/* ITEMS LIST ----------------------------------------------------- */}
      {empty && voidedItems.length === 0 ? (
        <div className="flex min-h-0 flex-1 items-center justify-center p-6 text-center text-sm text-muted-foreground">
          {t("pos.cart.no_items")}
        </div>
      ) : (
        <ScrollArea className="min-h-0 flex-1">
          <div className="space-y-2.5 px-4 py-3">
            {visibleItems.map((item) => {
              const lineLabel = orderItemDisplayName(
                item,
                t("pos.cart.undefined_item"),
              );
              const statusLabel = t(ITEM_STATUS_I18N[item.status]);
              const statusClass = ITEM_STATUS_CLASS[item.status];
              const isVoided = item.status === "voided";
              const isPending = item.status === "pending";
              // #1148 tightened — EDITS (qty / note / toppings) are
              // pending-only ALWAYS: once the kitchen owns the line, the dish
              // exists and the only honest mutations are void-with-reason +
              // add. plan-051 — the VOID gate is now a per-status matrix
              // (`voidableStatuses`, pending always allowed); backend +
              // workstation enforce the same matrix and demand a real reason
              // for a prepared dish. A voided line stays terminal.
              // `itemsEditable` extends both gates to `confirmed` counter-pay
              // takeaways (staff adjusts the cart before taking payment).
              // #2193 — dòng HOÀN là bút toán đảo bất biến: Cloud 409 mọi
              // void trên nó (#2173) và máy trạm cũng tự chặn tại chỗ tạo op.
              // Ẩn hẳn nút thay vì disable — một nút khoá vĩnh viễn chỉ tạo
              // nhiễu, và qty âm đã nói đây là dòng hoàn. Nhận diện bằng
              // `refund_of_item_id` (KHÔNG dùng `is_refund`: chỉ workstation
              // phát trường đó, qua đường Cloud-fallback nó undefined — #2159,
              // ghim ở types.ts).
              const isRefundLine = item.refund_of_item_id != null;
              const canEdit =
                itemsEditable && isPending && !isVoided && !isRefundLine;
              const canVoid =
                itemsEditable &&
                !isVoided &&
                !isRefundLine &&
                voidableStatuses.includes(item.status);
              // #2925 — LUẬT không đổi (#1148/plan-051), chỉ thôi IM LẶNG.
              // Trước đây nút thùng rác được bọc `{canVoid && …}`: khi bị chặn
              // nó BIẾN MẤT, và nhân viên đứng trước màn hình không có cách nào
              // biết vì sao — đúng cái sinh ra báo cáo "không xoá được món".
              // Giờ nút vẫn render, `disabled`, và NÓI RA lý do theo state
              // thật. Hai cổng, hai câu khác nhau:
              //   - `!itemsEditable`  → đơn đã rời open/confirmed (đã chốt)
              //   - status ∉ voidable → bếp đã nhận món
              // Cổng ĐƠN nói trước: nó chặn mọi dòng, nên "món đã vào bếp" ở
              // một đơn đã chốt là câu trả lời sai chỗ.
              const voidBlockedReason = canVoid
                ? null
                : !itemsEditable
                  ? t("pos.cart.void_blocked_order_closed")
                  : t("pos.cart.void_blocked_in_kitchen");

              // Toppings render one-per-line. Default view collapses to the
              // first 2 entries (with a toggle to expand) so cards stay
              // compact. Add modifiers carry unit_price; remove modifiers
              // show a "−" prefix without a price.
              const toppings = item.toppings ?? [];
              const COLLAPSED_TOPPING_LIMIT = 2;
              const isExpanded = expandedToppings.has(item.id);
              const visibleToppings =
                isExpanded || toppings.length <= COLLAPSED_TOPPING_LIMIT
                  ? toppings
                  : toppings.slice(0, COLLAPSED_TOPPING_LIMIT);
              const hiddenToppingCount =
                toppings.length - COLLAPSED_TOPPING_LIMIT;

              const imageUrl =
                item.product_sku?.image_url ??
                item.product_sku?.product?.image_url ??
                null;
              return (
                <article
                  key={item.id}
                  data-slot="order-cart-item"
                  className={cn(
                    "rounded-xl border bg-card p-3 transition-shadow",
                    isVoided && "opacity-60",
                  )}
                >
                  {/* Top: image (left) + status pill / name / trash (right) */}
                  <div className="flex items-start gap-3">
                    <div className="size-20 shrink-0 overflow-hidden rounded-lg bg-muted/60 text-2xl">
                      <ItemThumb imageUrl={imageUrl} label={lineLabel} />
                    </div>

                    <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                      <div className="flex items-start justify-between gap-2">
                        {!locked && !isVoided ? (
                          <StatusPicker
                            current={item.status}
                            onSelect={(s) => onUpdateItemStatus(item.id, s)}
                          />
                        ) : (
                          <span
                            className={cn(
                              "inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-[11px] font-medium",
                              statusClass,
                            )}
                          >
                            {statusLabel}
                          </span>
                        )}
                        {/* Dòng HOÀN và dòng ĐÃ HUỶ không bao giờ có nút:
                            #2193 đã chốt "ẩn hẳn thay vì disable" cho dòng
                            hoàn — một nút khoá VĨNH VIỄN chỉ tạo nhiễu — và
                            dòng đã huỷ tự nói ra trạng thái của nó bằng pill
                            + gạch ngang + lý do huỷ ngay dưới. Cái #2925 sửa
                            là dòng CÒN SỐNG mà tạm thời không huỷ được. */}
                        {!isVoided && !isRefundLine && (
                          <VoidLineButton
                            label={t("pos.cart.void_item")}
                            blockedReason={voidBlockedReason}
                            blockedLabel={
                              voidBlockedReason == null
                                ? null
                                : t("pos.cart.void_item_blocked", {
                                    reason: voidBlockedReason,
                                  })
                            }
                            onClick={() => onVoidItem(item.id, lineLabel)}
                          />
                        )}
                      </div>
                      <h4
                        className={cn(
                          "text-base font-bold leading-tight text-foreground",
                          isVoided && "line-through",
                        )}
                      >
                        {lineLabel}
                      </h4>
                      {/* plan-019 — Happy Hour Badge on lines where the
                          MenuPromotionService snapshotted a discount at
                          addItem. Reads from applied_promotion_snapshot
                          so the audit trail survives even if the source
                          promotion is later deactivated/deleted. */}
                      {item.original_unit_price != null &&
                        item.applied_promotion_snapshot?.discount_percent != null && (
                          <div className="mt-1">
                            <PromotionBadge
                              discountPercent={
                                item.applied_promotion_snapshot.discount_percent
                              }
                            />
                          </div>
                        )}
                    </div>
                  </div>

                  {/* Toppings / options — one entry per line, vertical-rule
                      separator on the left. Collapsed by default to the first
                      COLLAPSED_TOPPING_LIMIT rows; a clear expand/collapse
                      button below toggles the full list per line. */}
                  {toppings.length > 0 && (
                    <div
                      className={cn(
                        "mt-2.5 border-l-2 border-border pl-3 text-sm leading-relaxed text-muted-foreground",
                        isVoided && "line-through",
                      )}
                    >
                      <ul className="space-y-0.5">
                        {visibleToppings.map((tp) => {
                          const isRemove = tp.modifier_type === "remove";
                          const qtySuffix =
                            tp.quantity > 1 ? ` ×${tp.quantity}` : "";
                          const name =
                            tp.name != null
                              ? collapseMirroredToppingName(tp.name)
                              : (tp.topping_group_item_id ?? "");
                          const extra = Number(tp.unit_price);
                          const priceTag =
                            !isRemove && extra > 0
                              ? ` (${formatCurrency(extra)})`
                              : "";
                          const prefix = isRemove ? "− " : "";
                          return (
                            <li key={tp.id} className="truncate">
                              {prefix}
                              {name}
                              {priceTag}
                              {qtySuffix}
                            </li>
                          );
                        })}
                      </ul>
                      {hiddenToppingCount > 0 && (
                        <button
                          type="button"
                          onClick={() => toggleExpandedToppings(item.id)}
                          aria-expanded={isExpanded}
                          className="mt-1 -ml-1.5 inline-flex cursor-pointer items-center gap-1 rounded px-1.5 py-1 text-xs font-medium text-primary/80 transition-colors hover:text-primary focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring/50"
                        >
                          {isExpanded
                            ? t("pos.cart.show_less_options")
                            : t("pos.cart.show_more_options", {
                                count: hiddenToppingCount,
                              })}
                          <ChevronDownIcon
                            className={cn(
                              "size-3.5 transition-transform duration-200",
                              isExpanded && "rotate-180",
                            )}
                          />
                        </button>
                      )}
                    </div>
                  )}

                  {/* Customer kitchen note — golden italic, distinct from
                      regular toppings/text. */}
                  {item.note && (
                    <p
                      className={cn(
                        "mt-2 text-sm font-medium italic text-amber-600 dark:text-amber-400",
                        isVoided && "line-through",
                      )}
                    >
                      {t("pos.cart.note_prefix")} {item.note}
                    </p>
                  )}

                  {/* Voided reason */}
                  {isVoided && item.void_reason && (
                    <p className="mt-2 text-sm italic text-destructive">
                      {t("pos.cart.voided_prefix")} {item.void_reason}
                    </p>
                  )}

                  {/* Inline edit link — replaces the small pencil icon next
                      to the qty stepper. Reads as text so staff can reach
                      it easily on touch devices. */}
                  {canEdit && (
                    <button
                      type="button"
                      onClick={() => onEditItemToppings(item.id)}
                      className="mt-1.5 cursor-pointer text-sm font-medium italic text-primary transition-colors hover:text-primary/80"
                    >
                      {t("pos.cart.action_edit_line")}
                    </button>
                  )}

                  {/* Bottom row: subtotal (left) + qty stepper (right). Spans
                      the full card width below all the meta. */}
                  <div className="mt-3 flex items-center justify-between gap-2">
                    {(() => {
                      // plan-019 — when the line carries an
                      // original_unit_price, render the strikethrough
                      // original subtotal alongside the discounted one so
                      // staff see the saving. Topping cost is added to
                      // both sides identically.
                      const qty = Number(item.quantity);
                      const toppingSub = Number(item.topping_subtotal ?? 0);
                      const orig = item.original_unit_price;
                      // plan-043 — in 総額表示 (税込) shops the cart mirrors the
                      // menu card: present each line as gross (net + this line's
                      // consumption tax) so "add to order" shows the same number
                      // the operator tapped. menuDisplayPrice is a no-op when the
                      // shop is 税抜 or the line carries no resolved rate.
                      const lineRate =
                        item.tax_rate != null ? Number(item.tax_rate) : null;
                      const displaySubtotal = menuDisplayPrice(
                        Number(item.subtotal ?? 0),
                        lineRate,
                        showGrossLines,
                        currencyCode,
                      );
                      if (orig != null && !isVoided) {
                        // Through the shared rule, not re-typed here: this is a
                        // counterfactual subtotal (what the line WOULD have cost
                        // before the promotion) and it has to be built exactly
                        // the way the real one is, or the "saving" it implies is
                        // not the saving taken. It used to read
                        // `orig * qty + toppingSub`, charging the extras once
                        // per line — see `../lib/line-total.ts`.
                        const originalSubtotal = lineSubtotal(orig, toppingSub, qty);
                        const displayOriginalSubtotal = menuDisplayPrice(
                          originalSubtotal,
                          lineRate,
                          showGrossLines,
                          currencyCode,
                        );
                        return (
                          <span className="flex items-baseline gap-2">
                            <span className="text-xs text-muted-foreground line-through tabular-nums">
                              {formatCurrency(displayOriginalSubtotal)}
                            </span>
                            <span className="text-base font-bold tabular-nums text-amber-600">
                              {formatCurrency(displaySubtotal)}
                            </span>
                          </span>
                        );
                      }
                      return (
                        <span
                          className={cn(
                            "text-base font-bold tabular-nums text-foreground",
                            isVoided && "line-through",
                          )}
                        >
                          {formatCurrency(displaySubtotal)}
                        </span>
                      );
                    })()}
                    {!isVoided &&
                      (canEdit ? (
                        <div className="inline-flex items-center gap-1 rounded-full border bg-background px-1.5 py-0.5">
                          <button
                            type="button"
                            className={cn(
                              "flex size-7 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground",
                              "disabled:cursor-not-allowed disabled:text-muted-foreground/40 disabled:hover:bg-transparent disabled:hover:text-muted-foreground/40",
                            )}
                            disabled={Number(item.quantity) <= 1}
                            onClick={() =>
                              onChangeQty(item.id, Number(item.quantity) - 1)
                            }
                            aria-label={t("common.decrease")}
                          >
                            <MinusIcon className="size-3.5" />
                          </button>
                          <span className="min-w-7 text-center text-sm font-semibold tabular-nums">
                            {Math.trunc(Number(item.quantity))}
                          </span>
                          <button
                            type="button"
                            className="flex size-7 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                            onClick={() =>
                              onChangeQty(item.id, Number(item.quantity) + 1)
                            }
                            aria-label={t("common.increase")}
                          >
                            <PlusIcon className="size-3.5" />
                          </button>
                        </div>
                      ) : (
                        <span className="text-sm font-medium tabular-nums text-muted-foreground">
                          ×{Math.trunc(Number(item.quantity))}
                        </span>
                      ))}
                  </div>
                </article>
              );
            })}

            {voidedItems.length > 0 && (
              <button
                type="button"
                onClick={() => setShowVoided((v) => !v)}
                className="w-full cursor-pointer text-left text-[11px] text-muted-foreground transition-colors hover:text-foreground"
              >
                {showVoided
                  ? t("pos.cart.hide_voided", { count: voidedItems.length })
                  : t("pos.cart.show_voided", { count: voidedItems.length })}
              </button>
            )}
          </div>
        </ScrollArea>
      )}

      {/* TOTALS + ACTION ----------------------------------------------- */}
      <TotalsAndActions
        order={order}
        empty={empty}
        isCheckingOut={isCheckingOut}
        draft={draft}
        serviceChargeRate={serviceChargeRate}
        pricesIncludeTax={pricesIncludeTax}
        checkoutPending={checkoutPending}
        onCheckoutAndPay={checkoutAndPay}
        onOpenDraft={() => {
          // Business-rule guard runs BEFORE we expose the draft form so
          // staff sees the blocker immediately when clicking the headline
          // checkout button, not after they have already filled discount.
          if (onAttemptCheckout && !onAttemptCheckout()) return;
          setDraft({ couponInput: "", couponError: null, couponPending: false });
        }}
        onChangeDraft={setDraft}
        onCancelDraft={() => setDraft(null)}
        onConfirmDraft={() => {
          void checkoutAndPay();
        }}
        onApplyCoupon={async (code, opts) => {
          setDraft((prev) =>
            prev ? { ...prev, couponPending: true, couponError: null } : prev,
          );
          try {
            await onApplyCoupon(code, opts);
            setDraft((prev) =>
              prev
                ? { ...prev, couponInput: "", couponPending: false, couponError: null }
                : prev,
            );
          } catch (err) {
            // Structured CouponException → surface error_code so the UI can
            // render a localized message (see CouponError row below).
            const structured = parseCouponError(err);
            setDraft((prev) =>
              prev
                ? { ...prev, couponPending: false, couponError: structured }
                : prev,
            );
          }
        }}
        onReleaseCoupon={async () => {
          setDraft((prev) =>
            prev ? { ...prev, couponPending: true, couponError: null } : prev,
          );
          try {
            await onReleaseCoupon();
          } finally {
            setDraft((prev) =>
              prev ? { ...prev, couponPending: false } : prev,
            );
          }
        }}
        onPay={onPay}
        onSplitBill={onSplitBill}
        onAcceptOrder={onAcceptOrder}
        acceptPending={acceptPending}
      />

      {/* GỬI BẾP — plan-038: draggable floating action button that fires
          unprinted items to the kitchen/bar printer via workstation. Floats
          over the sidebar (no layout footprint) and can be parked anywhere;
          its position persists. Rendered last so it stacks above the footer.
          Hidden when no workstation print URL is configured (silent no-op) or
          once the order leaves `open` (items are already fired by then). */}
      {workstationPrintService.enabled && order.status === "open" && (
        <KitchenFireButton
          orderId={order.id}
          refreshSignal={kitchenFireSignal}
        />
      )}
    </aside>
  );
}

interface TotalsAndActionsProps {
  order: CustomerOrder;
  empty: boolean;
  isCheckingOut: boolean;
  draft: DraftPct | null;
  /** BR-SOS05 — branch service charge rate (%) used for live-preview. */
  serviceChargeRate: number;
  /** plan-043 — 総額表示 display flag (see OrderCartProps). */
  pricesIncludeTax: boolean;
  /** Một chạm: chốt đơn + mở màn thu tiền. Xem `checkoutAndPay` ở OrderCart. */
  onCheckoutAndPay: () => void | Promise<void>;
  /** Đang gọi `POST /checkout` — khoá mọi lối vào để cú chạm thứ hai không 409. */
  checkoutPending: boolean;
  onOpenDraft: () => void;
  onChangeDraft: (updater: (prev: DraftPct | null) => DraftPct | null) => void;
  onCancelDraft: () => void;
  onConfirmDraft: () => void;
  /** plan-019 */
  onApplyCoupon: (
    code: string,
    opts?: { downgradeExclusivePromotions?: boolean },
  ) => void | Promise<void>;
  onReleaseCoupon: () => void | Promise<void>;
  onPay: () => void;
  onSplitBill: () => void;
  onAcceptOrder?: () => void;
  acceptPending?: boolean;
}

function TotalsAndActions({
  order,
  empty,
  isCheckingOut,
  draft,
  serviceChargeRate,
  pricesIncludeTax,
  onCheckoutAndPay,
  checkoutPending,
  onOpenDraft,
  onChangeDraft,
  onCancelDraft,
  onConfirmDraft,
  onApplyCoupon,
  onReleaseCoupon,
  onPay,
  onSplitBill,
  onAcceptOrder,
  acceptPending = false,
}: TotalsAndActionsProps) {
  const { t } = useTranslation();
  const subtotal = Number(order.subtotal ?? 0);

  // plan-043 — 総額表示. `includeTax` here is the shop's DISPLAY preference
  // (prices_include_tax), NOT the order's engine snapshot (is_tax_included).
  // When on, the subtotal + service charge render as 税込 (gross) so they line
  // up with the menu card + the gross line rows above, and the per-rate tax
  // shows as 内消費税 (informational). The grand total never changes — it stays
  // `order.total_amount` — so the gross figures are just a re-presentation:
  //   gross subtotal  = net subtotal + item tax   (group-once, authoritative)
  //   gross service   = net service  + service tax (= total tax − item tax)
  //   gross subtotal − discount + gross service ≡ total  ✅
  // We can only split item vs service tax when the server shipped a per-rate
  // breakdown; without it (stale client / bare legacy order) the summary safely
  // falls back to the net + add-on-tax presentation, so no tax is misattributed
  // to the service line. The line rows above still follow their own stamped
  // per-line `tax_rate`, independent of the breakdown.
  // plan-019 — discount_amount now lives on the order (set by
  // couponService::apply). plan-043 — coupon apply/release is a server
  // round-trip whose response is cache-set, so `order.tax_breakdown`,
  // `order.service_charge`, `order.tax_amount`, and `order.total_amount`
  // are already authoritative for the current discount. The cart no longer
  // recomputes tax client-side from a single rate — it renders the server's
  // per-rate breakdown verbatim in BOTH draft and read-only modes.
  //
  // Phí phục vụ ở màn soạn giảm giá là ƯỚC LƯỢNG phía client (server chốt lại
  // khi áp mã); ngoài màn đó dùng con số đã lưu.
  const draftDiscountAmt = draft ? Number(order.discount_amount ?? 0) : 0;
  const draftDiscountedSubtotal = Math.max(0, subtotal - draftDiscountAmt);
  const draftServiceAmt = draft
    ? pctToAmount(draftDiscountedSubtotal, serviceChargeRate)
    : 0;

  // MỘT phép tính cho cả giỏ hàng lẫn hộp thoại thu tiền — xem `orderTotals()`.
  // Không màn nào được tự tính lại một con số ở đây.
  const totals = orderTotals(
    order,
    pricesIncludeTax,
    draft ? draftServiceAmt : undefined,
  );
  const subtotalDisplay = totals.subtotal;
  const showGrossSummary = totals.subtotalWasConverted;
  const taxInsideSubtotal = totals.taxIsInside;
  const serviceTax = totals.serviceTax;
  const savedService = totals.service;
  const draftService = totals.service;
  const liveTotal = totals.total;
  const taxDecimals = totals.taxDecimals;
  const roundingAdj = totals.rounding;
  const showRoundingAdj = totals.showRounding;

  // plan-043 — per-rate ITEM tax rows (8%対象 / 10%対象). Sorted ascending. A group
  // renders unless BOTH its base and its tax are zero — a 非課税 / zero-rated group
  // has base > 0 and tax = 0 and is MANDATORY on the document (Peppol BR-Z-08 /
  // BR-E-08). Dropping it was #2138; Cloud fixed the mirror-image bug at #2074 and
  // `CustomerOrderResource` now applies exactly this rule, so filtering harder here
  // would re-hide what the server deliberately sends.
  // The service-charge tax is NOT here — it sits
  // inside the service line above. Reconciliation:
  //   税抜: net subtotal + gross service + Σ item tax  ≡ total
  //   税込: gross subtotal + gross service             ≡ total (item tax = 内税 note)
  // Falls back to the flat `tax_amount` line when the server shipped no breakdown.
  const itemBreakdown = totals.taxGroups;

  // The breakdown card is collapsed ONLY while the order is still open and being
  // built — that's when the item list needs the room and the "Tính tiền" button
  // (which carries the live total) provides the reveal. In every other state
  // (draft/checkout/paying/closed/voided) there is no reveal button, so the
  // breakdown stays visible as before.
  const showBreakdown = !!draft || order.status !== "open";

  return (
    <div className="relative z-10 shrink-0 space-y-2.5 border-t bg-muted/60 px-4 py-3 shadow-[0_-6px_16px_-8px_rgba(0,0,0,0.18)]">
      {/* Receipt-style summary: adjustment rows → divider → grand total, all in
          one card so the eye reads straight down to the payable figure. Revealed
          only during checkout (draft) / payment — see showBreakdown. */}
      {showBreakdown && (
      <div className="space-y-2 overflow-hidden rounded-xl border bg-card px-4 py-3 text-sm shadow-sm duration-200 animate-in fade-in slide-in-from-bottom-2">
        {/* Subtotal — always visible */}
        <div className="flex items-center justify-between">
          <span className="text-muted-foreground">
            {t("pos.cart.subtotal")}
          </span>
          <span className="font-semibold tabular-nums text-foreground">
            {/* 税込 gross subtotal carries the item tax, which may be sub-unit
                (option-B) → render with the tax decimals; 税抜 net subtotal is
                whole-yen. */}
            {showGrossSummary
              ? formatTax(subtotalDisplay, taxDecimals)
              : formatCurrency(subtotalDisplay)}
          </span>
        </div>

        {draft ? (
          /* DRAFT MODE — coupon entry replaces the free-form discount %
             (plan-019). Apply / remove fire CouponService endpoints; the
             server returns the updated order with `discount_amount` +
             `coupon_code_snapshot`, which the cache-set surfaces here.
             Tax + service rows track the new discounted subtotal live. */
          <>
            <CouponRow
              label={t("pos.cart.discount")}
              order={order}
              input={draft.couponInput}
              onChangeInput={(v) =>
                onChangeDraft((prev) =>
                  prev ? { ...prev, couponInput: v, couponError: null } : prev,
                )
              }
              onApply={(opts) => onApplyCoupon(draft.couponInput, opts)}
              onRelease={() => onReleaseCoupon()}
              pending={draft.couponPending}
              error={draft.couponError}
              amount={`−${formatCurrency(draftDiscountAmt)}`}
            />
            {/* 税抜 (add-on) mode only — in 総額表示 the tax is already inside
                the gross subtotal and renders as an 内税 note below the total. */}
            {!taxInsideSubtotal && (
              <TaxBreakdownRows
                breakdown={itemBreakdown}
                includeTax={false}
                taxDecimals={taxDecimals}
              />
            )}
            {serviceChargeRate > 0 && (
              <ServiceChargeRow
                gross={draftService.gross}
                net={draftService.net}
                tax={serviceTax}
                ratePct={serviceChargeRate}
                taxDecimals={taxDecimals}
              />
            )}
          </>
        ) : (
          /* DEFAULT MODE — readonly server-saved adjustments. Skip rows
             whose stored amount is 0 so the panel stays compact when no
             discount/service/tax is on the order.

             The test is `!== 0`, NOT `> 0` (#2138). `> 0` also hid every NEGATIVE
             value, and a negative discount / service charge / tax is corruption,
             not absence — the split-bill truncation of #2130 produces `tax = -1`
             on a 0%-tax order, and under the old gate the POS rendered nothing at
             all. Hiding a wrong number is worse than showing it: the operator
             cannot report what the screen never draws. */
          <>
            {showsMoneyRow(order.discount_amount) && (
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">
                  {t("pos.cart.discount")}
                </span>
                <span className="font-semibold tabular-nums text-destructive">
                  −{formatCurrency(order.discount_amount)}
                </span>
              </div>
            )}
            {showsMoneyRow(order.service_charge) && (
              <ServiceChargeRow
                gross={savedService.gross}
                net={savedService.net}
                tax={serviceTax}
                taxDecimals={taxDecimals}
              />
            )}
            {/* 税抜 (add-on) mode only — ITEM tax as an additive row. The service
                tax already sits inside the gross service line above. In 総額表示
                the item tax is inside the gross subtotal and shows as an 内税
                note below the total instead. */}
            {!taxInsideSubtotal &&
              (itemBreakdown.length > 0 ? (
                <TaxBreakdownRows
                  breakdown={itemBreakdown}
                  includeTax={false}
                  taxDecimals={taxDecimals}
                />
              ) : (
                showsMoneyRow(order.tax_amount) && (
                  <div className="flex items-center justify-between">
                    <span className="text-muted-foreground">
                      {t("pos.cart.tax")}
                    </span>
                    <span className="font-semibold tabular-nums text-foreground">
                      {formatTax(order.tax_amount, taxDecimals)}
                    </span>
                  </div>
                )
              ))}
          </>
        )}

        {/* plan-045 option-B — 端数調整 (rounding). The tax figures above carry
            sub-unit precision (per tax_rounding_decimals); the payable total is
            rounded to whole yen, so this row books the difference and the column
            still sums to the total. Hidden when there is no fractional remainder. */}
        {showRoundingAdj && (
          <div className="flex items-center justify-between">
            <span className="text-muted-foreground">
              {t("pos.cart.rounding_adjustment")}
            </span>
            <span className="font-semibold tabular-nums text-foreground">
              {roundingAdj >= 0 ? "+" : "−"}
              {formatTax(Math.abs(roundingAdj), taxDecimals)}
            </span>
          </div>
        )}
        {/* Grand total — full-bleed footer inside the same card, divided from
            the rows above so it reads as their result, not a floating figure. */}
        <div className="-mx-4 -mb-3 mt-2.5 flex items-baseline justify-between gap-3 border-t bg-muted/40 px-4 py-2.5">
          <span className="text-[15px] font-bold text-foreground">
            {t("pos.cart.total")}
          </span>
          <span className="text-[26px] font-extrabold leading-none tabular-nums text-foreground">
            {formatCurrency(liveTotal)}
          </span>
        </div>
      </div>
      )}

      {/* plan-043 — 総額表示 内税 note (item tax). In tax-included mode the gross
          subtotal already contains the item consumption tax, so the total is
          subtotal + service (NOT + tax). This muted, parenthesized line below the
          total makes the item tax an unambiguous "of which" figure rather than a
          fourth addend the operator would sum in. The service-charge tax is not
          repeated here — it sits inside the gross service line above. */}
      {taxInsideSubtotal && showBreakdown && (
        <IncludedTaxNote breakdown={itemBreakdown} taxDecimals={taxDecimals} />
      )}

      {/* Bottom action area --------------------------------------- */}
      {draft && !isCheckingOut && !empty && order.status === "open" && (
        <div className="flex gap-2">
          <Button
            variant="outline"
            className="h-11 flex-1 rounded-xl"
            disabled={checkoutPending}
            onClick={onCancelDraft}
          >
            {t("common.cancel")}
          </Button>
          {/* Đường nhánh mã giảm giá cũng chỉ tốn thêm ĐÚNG một chạm: nút này
              chốt đơn RỒI mở màn thu tiền, y như nút chính. */}
          <Button
            className="h-11 flex-1 rounded-xl text-base font-semibold"
            disabled={checkoutPending}
            onClick={onConfirmDraft}
          >
            {checkoutPending && (
              <Spinner className="mr-2 size-4" />
            )}
            {t("pos.checkout.confirm")}
          </Button>
        </div>
      )}

      {/* Tiếp nhận đơn — a customer-submitted takeaway (pending/confirmed)
          must be accepted (→ open) before the regular checkout CTA appears.
          Rides POST /pos/orders/{id}/confirm (workstation-local, Cloud
          fallback); the mutation cache-sets the returned open order so this
          button swaps for the checkout CTA on success. */}
      {!draft &&
        !isCheckingOut &&
        onAcceptOrder &&
        (order.status === "pending" || order.status === "confirmed") && (
          <Button
            className="h-14 w-full rounded-xl px-4 text-base font-semibold"
            disabled={acceptPending}
            onClick={onAcceptOrder}
          >
            <span className="flex items-center gap-2">
              <CheckIcon className="size-5" />
              {t("pos.cart.action_accept")}
            </span>
          </Button>
        )}

      {/* MỘT CHẠM — chốt đơn rồi mở luôn màn thu tiền. Mã giảm giá lùi xuống
          thành đường nhánh: phần lớn đơn không có mã, và bắt mọi đơn đi qua
          form đó là bắt cả quán trả giá cho thiểu số. */}
      {!draft && !isCheckingOut && order.status === "open" && (
        <div className="space-y-1.5">
          <Button
            className="h-14 w-full rounded-xl px-4 text-base font-semibold"
            disabled={empty || checkoutPending}
            onClick={() => {
              void onCheckoutAndPay();
            }}
          >
            <span className="flex w-full items-center justify-between gap-3">
              <span className="flex items-center gap-2">
                {checkoutPending ? (
                  <Spinner className="size-5" />
                ) : (
                  <ReceiptTextIcon className="size-5" />
                )}
                {t("pos.cart.action_checkout")}
              </span>
              {!empty && (
                <span className="text-lg font-extrabold tabular-nums">
                  {formatCurrency(liveTotal)}
                </span>
              )}
            </span>
          </Button>
          {/* `type="button"`: trang POS có <form> thật ở các hộp thoại tiền, và
              một <button> trần mặc định là submit. */}
          {!empty && (
            <button
              type="button"
              disabled={checkoutPending}
              onClick={onOpenDraft}
              className="mx-auto block rounded px-2 py-1 text-xs text-muted-foreground underline-offset-2 transition-colors hover:text-foreground hover:underline disabled:cursor-not-allowed disabled:opacity-50"
            >
              {t("pos.cart.action_have_coupon")}
            </button>
          )}
        </div>
      )}

      {isCheckingOut && (
        <div className="flex gap-2">
          <Button className="h-11 flex-1 rounded-xl" onClick={onPay}>
            <CreditCardIcon className="mr-2 size-4" />
            {t("pos.cart.action_pay")}
          </Button>
          {order.guest_count != null && order.guest_count > 1 && (
            <Button
              variant="outline"
              className="h-11 flex-1 rounded-xl"
              onClick={onSplitBill}
            >
              <SplitIcon className="mr-2 size-4" />
              {t("pos.cart.action_split_bill")}
            </Button>
          )}
        </div>
      )}
    </div>
  );
}

/**
 * plan-043 — per-rate consumption-tax rows (インボイス 8%対象 / 10%対象).
 * Renders one row per rate group from the order's `tax_breakdown`. In
 * included mode (総額表示 / 内税) the amounts are 内消費税 (already inside the
 * price) so the label carries the 内 marker; excluded mode is add-on tax.
 */
export function TaxBreakdownRows({
  breakdown,
  includeTax,
  taxDecimals,
}: {
  breakdown: TaxBreakdownEntry[];
  includeTax: boolean;
  taxDecimals?: number | null;
}) {
  const { t } = useTranslation();
  if (breakdown.length === 0) return null;
  return (
    <>
      {breakdown.map((g) => (
        <div
          key={g.rate}
          className="flex items-center justify-between"
        >
          <span className="text-muted-foreground">
            {t(
              includeTax
                ? "pos.cart.tax_rate_included"
                : "pos.cart.tax_rate",
              { rate: Number(g.rate) },
            )}
          </span>
          <span className="font-semibold tabular-nums text-foreground">
            {formatTax(g.tax, taxDecimals)}
          </span>
        </div>
      ))}
    </>
  );
}

/**
 * plan-043 — 総額表示 内税 note, rendered BELOW the total. In tax-included mode
 * the subtotal + service charge already contain the consumption tax, so the
 * total = subtotal + service (the tax is NOT a separate addend). This muted,
 * parenthesized "of which tax" line prevents the operator from mentally summing
 * the tax into the total (the "982 + 50 + 89 = ?" confusion) — it mirrors the
 * 「（内消費税 ¥X）」 line on a Japanese 税込 receipt.
 */
export function IncludedTaxNote({
  breakdown,
  taxDecimals,
}: {
  breakdown: TaxBreakdownEntry[];
  taxDecimals?: number | null;
}) {
  const { t } = useTranslation();
  if (breakdown.length === 0) return null;
  return (
    <div className="space-y-0.5 px-1">
      {breakdown.map((g) => (
        <div
          key={g.rate}
          className="flex items-center justify-between text-xs text-muted-foreground"
        >
          <span>
            {t("pos.cart.tax_rate_included", { rate: Number(g.rate) })}
          </span>
          <span className="tabular-nums">({formatTax(g.tax, taxDecimals)})</span>
        </div>
      ))}
    </div>
  );
}

/**
 * plan-043 — the service-charge summary row. The headline `gross` figure is 税込
 * (service charge + its own consumption tax), consistent across the 税込/税抜
 * display modes. When the service carries tax, the "Phí phục vụ" label becomes a
 * toggle: click it to collapse / expand two aligned, indented sub-rows (with a
 * vertical guide) that break the figure down — 税抜 base + 税 — so the operator
 * can drill into "¥50 = ¥45 + ¥5" or keep the panel compact. Defaults to
 * expanded so the breakdown is visible without a first click.
 */
export function ServiceChargeRow({
  gross,
  net,
  tax,
  ratePct,
  taxDecimals,
}: {
  gross: number;
  net: number;
  tax: number;
  ratePct?: number;
  taxDecimals?: number | null;
}) {
  const { t } = useTranslation();
  const [expanded, setExpanded] = useState(true);
  const collapsible = tax > 0;

  const label = (
    <>
      {t("pos.cart.service_charge")}
      {ratePct != null && ratePct > 0 && (
        <span className="ml-1.5 text-xs text-muted-foreground/70 tabular-nums">
          ({ratePct}%)
        </span>
      )}
    </>
  );

  return (
    <div className="space-y-1">
      <div className="flex items-center justify-between">
        {collapsible ? (
          <button
            type="button"
            onClick={() => setExpanded((v) => !v)}
            aria-expanded={expanded}
            className="flex cursor-pointer items-center gap-1 rounded text-muted-foreground transition-colors hover:text-foreground"
          >
            <span>{label}</span>
            <ChevronDownIcon
              className={cn(
                "size-3.5 text-muted-foreground/60 transition-transform",
                !expanded && "-rotate-90",
              )}
            />
          </button>
        ) : (
          <span className="text-muted-foreground">{label}</span>
        )}
        <span className="font-semibold tabular-nums text-foreground">
          {formatTax(gross, taxDecimals)}
        </span>
      </div>
      {collapsible && expanded && (
        <div className="ml-1 space-y-1 border-l-2 border-border pl-3 text-xs text-muted-foreground">
          <div className="flex items-center justify-between">
            <span>{t("pos.cart.service_charge_base")}</span>
            <span className="tabular-nums">{formatCurrency(net)}</span>
          </div>
          <div className="flex items-center justify-between">
            <span>{t("pos.cart.tax")}</span>
            <span className="tabular-nums">+{formatTax(tax, taxDecimals)}</span>
          </div>
        </div>
      )}
    </div>
  );
}

export interface CouponRowProps {
  label: string;
  order: CustomerOrder;
  input: string;
  onChangeInput: (next: string) => void;
  onApply: (opts?: { downgradeExclusivePromotions?: boolean }) => void;
  onRelease: () => void;
  pending: boolean;
  error: { code: string; meta?: Record<string, unknown> } | null;
  amount: string;
}

/**
 * Coupon entry row inside the cart's checkout draft (plan-019). Two states:
 *
 *   - applied (order.coupon_id != null): chip with code snapshot + remove
 *     button. The discount column on the right mirrors the server-saved
 *     `discount_amount` (works for both `fixed` and `percent` coupons —
 *     CouponService applies the cap + min-subtotal rules atomically).
 *
 *   - empty: Input + Apply button. The Apply button is gated on
 *     `input.trim().length > 0`. On a 422 the parent fills `error` and we
 *     render a localized `coupon.error.<code>` line under the input plus
 *     any `meta.exclusive_item_names` for the cross-promo conflict case.
 */
export function CouponRow({
  label,
  order,
  input,
  onChangeInput,
  onApply,
  onRelease,
  pending,
  error,
  amount,
}: CouponRowProps) {
  const { t } = useTranslation();
  const applied = !!order.coupon_id;

  return (
    <div className="flex flex-col gap-1.5">
      {/* Header row: label + amount (only filled when a coupon is applied). */}
      <div className="flex items-baseline justify-between gap-2">
        <span className="text-muted-foreground">{label}</span>
        <span
          className={cn(
            "text-sm font-semibold tabular-nums",
            applied ? "text-destructive" : "text-muted-foreground",
          )}
        >
          {applied ? amount : "—"}
        </span>
      </div>

      {/* Body row: full-width input + Apply button, OR applied chip + Remove.
          Stacking under the header avoids the 3-column squeeze that used to
          push the Apply button off the sidebar. */}
      {applied ? (
        <div className="flex items-center gap-2">
          <span className="inline-flex flex-1 items-center justify-between gap-1 rounded-md bg-emerald-50 px-2 py-1 text-xs font-mono font-semibold text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-200 dark:ring-emerald-900">
            <span className="truncate">{order.coupon_code_snapshot ?? "?"}</span>
          </span>
          <button
            type="button"
            onClick={onRelease}
            disabled={pending}
            className="inline-flex h-7 shrink-0 items-center gap-1 rounded-md border px-2 text-xs text-muted-foreground hover:bg-muted hover:text-foreground disabled:opacity-50"
            aria-label={t("pos.coupon.remove")}
          >
            <XIcon className="size-3.5" />
            {t("pos.coupon.remove")}
          </button>
        </div>
      ) : (
        <div className="flex items-center gap-1.5">
          <input
            type="text"
            value={input}
            onChange={(e) => onChangeInput(e.target.value.toUpperCase())}
            onKeyDown={(e) => {
              if (e.key === "Enter") {
                e.preventDefault();
                if (input.trim().length > 0 && !pending) onApply();
              }
            }}
            placeholder={t("pos.coupon.placeholder")}
            maxLength={50}
            disabled={pending}
            aria-label={t("pos.coupon.section_title")}
            className={cn(
              "h-7 min-w-0 flex-1 rounded-md border bg-background px-2 text-xs font-mono uppercase shadow-none",
              "focus:outline-none focus:ring-2 focus:ring-ring/30",
            )}
          />
          <button
            type="button"
            onClick={() => {
              if (input.trim().length > 0) onApply();
            }}
            disabled={pending || input.trim().length === 0}
            className="h-7 shrink-0 rounded-md bg-primary px-2.5 text-xs font-semibold text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
          >
            {pending ? "…" : t("pos.coupon.apply")}
          </button>
        </div>
      )}

      {error && (
        <div
          role="alert"
          className="rounded-md bg-destructive/5 px-2 py-2 text-[11px] text-destructive"
        >
          <div>{t(`coupon.error.${error.code}`) || t("pos.coupon.error_generic")}</div>
          {error.code === "coupon_excluded_by_active_promotion" && (
            <>
              {Array.isArray(error.meta?.exclusive_item_names) && (
                <ul className="mt-1 list-disc pl-4">
                  {(error.meta?.exclusive_item_names as string[]).map((n) => (
                    <li key={n}>{n}</li>
                  ))}
                </ul>
              )}
              {/* Plan-019 — let staff pick which discount to keep. The
                  "Dùng coupon" CTA re-fires apply with the downgrade flag;
                  backend reverts the HH lines to original_unit_price + audit. */}
              <div className="mt-2 flex flex-wrap gap-2">
                <button
                  type="button"
                  onClick={() => onApply({ downgradeExclusivePromotions: true })}
                  disabled={pending || input.trim().length === 0}
                  className="h-7 rounded-md border border-destructive bg-destructive/10 px-2.5 text-[11px] font-semibold text-destructive hover:bg-destructive/15 disabled:opacity-50"
                >
                  {t("pos.coupon.use_coupon_over_promo")}
                </button>
                <span className="self-center text-[10px] text-muted-foreground">
                  {t("pos.coupon.or_keep_promo_hint")}
                </span>
              </div>
            </>
          )}
        </div>
      )}
    </div>
  );
}

interface TableActionButtonProps {
  icon: React.ReactNode;
  label: string;
  disabled?: boolean;
  onClick: () => void;
}

function TableActionButton({
  icon,
  label,
  disabled,
  onClick,
}: TableActionButtonProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={cn(
        // Enabled: white card with border, gentle muted hover, ring on focus.
        "group inline-flex h-9 w-full cursor-pointer items-center justify-center gap-1.5 rounded-lg border border-border bg-background px-2 text-xs font-medium text-foreground transition-colors",
        "hover:border-foreground/20 hover:bg-muted hover:text-foreground",
        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40",
        // Disabled: clearly grayed out + not-allowed cursor; hover removed.
        "disabled:cursor-not-allowed disabled:border-dashed disabled:border-border/60 disabled:bg-muted/40 disabled:text-muted-foreground/60 disabled:hover:border-border/60 disabled:hover:bg-muted/40 disabled:hover:text-muted-foreground/60",
      )}
    >
      <span className="text-muted-foreground transition-colors group-hover:text-foreground group-disabled:text-muted-foreground/60">
        {icon}
      </span>
      <span className="truncate">{label}</span>
    </button>
  );
}

interface VoidLineButtonProps {
  /** Nhãn khi huỷ được (`pos.cart.void_item`). */
  label: string;
  /** Lý do bị chặn, đã dịch. `null` = huỷ được. */
  blockedReason: string | null;
  /** Tên khả truy cập khi bị chặn (nhãn + lý do). `null` khi huỷ được. */
  blockedLabel: string | null;
  onClick: () => void;
}

/**
 * Nút huỷ MỘT dòng món. Cùng cách xử lý "không được phép" với
 * `TableActionButton`: giữ nút, `disabled`, viền đứt nét + xám — chứ không
 * biến mất (#2925).
 *
 * Khác một điểm có chủ đích: khi bị chặn nó KÈM CHỮ. Đây là màn hình cảm ứng,
 * `title` chỉ hiện khi rê chuột nên trên tablet nó vô hình; một nút xám câm vẫn
 * là câu trả lời "không" mà không nói vì sao. Lúc huỷ được thì nút giữ nguyên
 * hình tròn chỉ-biểu-tượng như cũ — đường đi thường ngày không đổi một pixel.
 */
function VoidLineButton({
  label,
  blockedReason,
  blockedLabel,
  onClick,
}: VoidLineButtonProps) {
  const blocked = blockedReason != null;
  return (
    <button
      type="button"
      disabled={blocked}
      onClick={onClick}
      aria-label={blocked ? (blockedLabel ?? label) : label}
      title={blocked ? (blockedLabel ?? label) : label}
      className={cn(
        "flex h-8 shrink-0 items-center justify-center gap-1.5 rounded-full transition-colors",
        blocked
          ? "max-w-[10rem] cursor-not-allowed border border-dashed border-border/60 bg-muted/40 px-2.5 text-[11px] font-medium text-muted-foreground/70"
          : "size-8 cursor-pointer bg-destructive/10 text-destructive hover:bg-destructive/15",
      )}
    >
      <Trash2Icon className="size-4 shrink-0" />
      {blocked && <span className="truncate">{blockedReason}</span>}
    </button>
  );
}

interface StatusPickerProps {
  current: OrderItemStatus;
  onSelect: (next: Exclude<OrderItemStatus, "voided">) => void;
}

/**
 * Pill that surfaces the item's current kitchen status and opens a small
 * status menu on click. Controlled `open` so we can close the popover
 * imperatively right after a selection — staff sees the new status on
 * the trigger without an extra click to dismiss.
 */
function StatusPicker({ current, onSelect }: StatusPickerProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const currentClass = ITEM_STATUS_CLASS[current];
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          type="button"
          className={cn(
            "inline-flex shrink-0 cursor-pointer items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-medium transition-opacity hover:opacity-80",
            currentClass,
          )}
        >
          {t(ITEM_STATUS_I18N[current])}
          <ChevronDownIcon className="size-3" />
        </button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-44 p-1">
        <div className="mb-0.5 px-1.5 pt-1 text-[9px] font-semibold uppercase tracking-wide text-muted-foreground">
          {t("pos.cart.update_status")}
        </div>
        <div>
          {SELECTABLE_STATUS_KEYS.map((s) => {
            const isCurrent = s === current;
            return (
              <button
                key={s}
                type="button"
                disabled={isCurrent}
                onClick={() => {
                  onSelect(s);
                  setOpen(false);
                }}
                className={cn(
                  "flex w-full items-center justify-between gap-1.5 rounded px-1.5 py-1 text-left transition-colors disabled:cursor-not-allowed",
                  isCurrent
                    ? "bg-muted/60"
                    : "cursor-pointer hover:bg-muted/60",
                )}
              >
                <span
                  className={cn(
                    "inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium",
                    ITEM_STATUS_CLASS[s],
                  )}
                >
                  {t(ITEM_STATUS_I18N[s])}
                </span>
                {isCurrent && (
                  <CheckIcon
                    className="size-3 text-muted-foreground"
                    aria-label={t("pos.cart.update_status")}
                  />
                )}
              </button>
            );
          })}
        </div>
      </PopoverContent>
    </Popover>
  );
}

