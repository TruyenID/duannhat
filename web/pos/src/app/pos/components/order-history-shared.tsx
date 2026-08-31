/**
 * Shared history rendering — the order-list row + the master-detail order story
 * (lifecycle, items with void/promo, per-rate totals, payment history). Used by
 * both TableHistoryView (one table) and the all-tables OrderHistoryPage, so the
 * "full listing" stays byte-identical between the two surfaces.
 */

import { useMemo } from "react";
import { ChevronLeftIcon, MapPinIcon } from "lucide-react";
import { Spinner } from "@/components/ui/spinner";
import { cn } from "@/lib/utils";
import { formatDateTime, formatTime } from "@/lib/format-date";
import { useOrder } from "@/hooks/api/use-orders";
import { formatCurrency } from "../lib/totals";
import { orderItemDisplayName } from "../lib/order-item-name";
import { collapseMirroredToppingName } from "../lib/topping-name";
import { isProvisionalCode } from "../lib/order-code";
import { showsMoneyRow } from "../lib/tax-display";
import { ReprintButton } from "./reprint-button";
import { PrintDocActions } from "./print-doc-actions";
import { useOrderPrintCounts } from "../hooks/use-order-print-counts";
import { workstationPrintService, type PrintKindCounts } from "@/services/workstation-print-service";
import type {
  CustomerOrder,
  CustomerOrderItem,
  CustomerOrderStatus,
  OrderPayment,
} from "../types";

export type TFn = (k: string, v?: Record<string, string | number>) => string;

// Restrained -50 status tint, matching table-status.ts / takeaway-orders-view.
export const STATUS_BADGE: Record<CustomerOrderStatus, string> = {
  pending: "bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300",
  // Customer-web orders awaiting counter acceptance share the "incoming" sky
  // tint with `pending` — same operator meaning: newly arrived, not yet worked.
  awaiting_confirmation: "bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300",
  confirmed: "bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300",
  expired: "bg-muted text-muted-foreground",
  open: "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300",
  dining: "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300",
  checkout: "bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300",
  paying: "bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300",
  closed: "bg-muted text-muted-foreground",
  voided: "bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300",
};

/**
 * #2049 — nhãn + màu trạng thái, có tính tới ĐANG TREO.
 *
 * Đơn treo được cho nợ TOÀN BỘ vẫn mang `status = 'closed'` trong DB — payment
 * `on_account` đẩy `paid_amount` lên bằng `total_amount` nên vòng đời đơn đóng
 * lại bình thường, bàn được nhả, doanh thu vào sổ đúng như luật plan-038 ("debt
 * = revenue, not cash"). Đó là chủ ý và không đổi.
 *
 * Nhưng đọc `closed` ra chữ "Hoàn thành" thì SAI với người đọc: quán chưa cầm
 * đồng nào. Nên nhãn — và chỉ nhãn — bị đè.
 *
 * Màu hổ phách chứ không xám: xám là màu của việc đã xong. Đơn treo là việc còn
 * dở, và nó phải nhìn giống `checkout`/`paying` (cùng hổ phách) chứ không giống
 * `closed`.
 *
 * MỘT helper cho cả hai chỗ vẽ badge trong file này. Hai bản sẽ lệch, và lệch ở
 * đây nghĩa là danh sách đơn nói một đằng, màn chi tiết nói một nẻo.
 */
export function orderStatusBadge(order: CustomerOrder, t: TFn): { className: string; label: string } {
  if (order.is_on_hold === true) {
    return {
      className: "bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300",
      label: t("pos.on_hold.status"),
    };
  }
  return {
    className: STATUS_BADGE[order.status] ?? STATUS_BADGE.open,
    label: t(`pos.order_status.${order.status}`),
  };
}

const PAYMENT_STATUS_TINT: Record<string, string> = {
  succeeded: "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300",
  confirmed: "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300",
  pending: "bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300",
  failed: "bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300",
  refunded: "bg-muted text-muted-foreground",
};

export function num(v: number | string | null | undefined): number {
  return Number(v ?? 0);
}

/**
 * Tiền đã thực sự vào — điều kiện DUY NHẤT để mời in lại hoá đơn.
 *
 * Hai chữ chứ không một: Cloud ghi `succeeded`, workstation ghi `confirmed`, và
 * cùng một đơn có thể được đọc từ cả hai nguồn tuỳ chế độ API. Bỏ sót một chữ
 * làm nút in biến mất trên đúng nửa số đơn, tuỳ máy thu ngân đang nối đâu.
 *
 * `refunded` KHÔNG tính: tờ giấy ghi ĐÃ THANH TOÁN cho một khoản đã hoàn là một
 * chứng từ sai. Workstation cũng từ chối (`PaymentSettled`), nên đây chỉ là để
 * giao diện đừng mời bấm một nút chắc chắn 409.
 */
function isSettledPayment(payment: Pick<OrderPayment, "status">): boolean {
  return payment.status === "succeeded" || (payment.status as string) === "confirmed";
}

/** Tên người mua để prefill hoá đơn đỏ. Cùng cách ghép với `use-receipt-flow`
 *  và `takeaway-orders-view` — khách vãng lai trả về undefined, và workstation
 *  in một dòng gạch chân để thu ngân viết tay. */
function customerDisplayName(order: CustomerOrder): string | undefined {
  const name = [order.customer?.first_name, order.customer?.last_name]
    .filter(Boolean)
    .join(" ")
    .trim();
  return name || undefined;
}

function itemName(it: CustomerOrderItem): string {
  return orderItemDisplayName(it, "—");
}

/**
 * Where the order lives, for the all-tables list: the bound table name(s), or a
 * "Mang đi" / "Tại quầy" label for takeaway / spot orders that carry no table.
 */
export function orderPlaceLabel(order: CustomerOrder, t: TFn): string {
  const names = (order.tables ?? [])
    .map((tb) => tb.name?.trim() || tb.code)
    .filter(Boolean);
  if (names.length) return names.join(", ");
  if (order.order_type === "takeaway") return t("pos.order_history.takeaway");
  if (order.order_type === "spot") return t("pos.order_history.spot");
  return "—";
}

// Promotion info for a line. Menu promotions are percent-only; the workstation
// snapshot carries just the name, so derive the percent from the pre-promo
// price (original_unit_price) vs the discounted unit_price when the Cloud
// snapshot's discount_percent isn't present.
function promoInfo(it: CustomerOrderItem) {
  const orig = num(it.original_unit_price);
  const unit = num(it.unit_price);
  const snap = it.applied_promotion_snapshot;
  const pct =
    snap?.discount_percent != null
      ? Number(snap.discount_percent)
      : orig > 0 && orig > unit
        ? Math.round(((orig - unit) / orig) * 100)
        : 0;
  return { name: snap?.name ?? null, pct, orig, unit, has: !!snap?.name || orig > unit };
}

// ── Order list row ──────────────────────────────────────────────────────────

/**
 * A tappable order row for the master list. `showPlace` adds the table /
 * takeaway label (used by the all-tables view, where the same list mixes every
 * table); the per-table view leaves it off since the table is implied.
 */
export function OrderRow({
  order,
  active,
  onSelect,
  showPlace,
  t,
  locale,
}: {
  order: CustomerOrder;
  active: boolean;
  onSelect: () => void;
  showPlace?: boolean;
  t: TFn;
  locale: string;
}) {
  const badge = orderStatusBadge(order, t);
  const when = order.created_at ?? order.opened_at;
  return (
    <button
      type="button"
      onClick={onSelect}
      className={cn(
        "flex w-full flex-col gap-1.5 border-l-2 px-4 py-3 text-left transition-colors",
        active
          ? "border-primary bg-primary/[0.045]"
          : "border-transparent hover:bg-muted/40",
      )}
    >
      <div className="flex items-center gap-2">
        {isProvisionalCode(order.order_code) ? (
          <span className="text-sm font-medium text-amber-600 dark:text-amber-400">
            {t("pos.order.code_pending")}
          </span>
        ) : (
          <span
            className={cn(
              "text-sm font-semibold tabular-nums tracking-tight",
              active ? "text-primary" : "text-foreground",
            )}
          >
            {order.order_code}
          </span>
        )}
        <span
          className={cn(
            "ml-auto shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium",
            badge.className,
          )}
        >
          {badge.label}
        </span>
      </div>
      <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
        <span className="flex min-w-0 items-center gap-1.5">
          {showPlace && (
            <>
              <span className="min-w-0 truncate font-medium text-foreground/70">
                {orderPlaceLabel(order, t)}
              </span>
              <span className="shrink-0 text-muted-foreground/40">·</span>
            </>
          )}
          <span className="shrink-0 tabular-nums">
            {when ? formatTime(when, locale, { withSeconds: false }) : "—"}
          </span>
        </span>
        <span className="shrink-0 font-semibold tabular-nums text-foreground">
          {formatCurrency(num(order.total_amount))}
        </span>
      </div>
    </button>
  );
}

// ── Order detail (lifecycle + items + payments) ─────────────────────────────

export function OrderDetail({
  shopSlug,
  orderId,
  onBack,
  showPlace = true,
  allowReprint = false,
  t,
  locale,
}: {
  shopSlug: string;
  orderId: string;
  onBack: () => void;
  /** Show the table / takeaway "place" line. Off for the per-table view where
   *  the table is already in the page header (redundant there). */
  showPlace?: boolean;
  /**
   * Offer "In lại hoá đơn" for the settled payments on this order.
   *
   * Opt-in, not on by default: the per-table history is a lookup panel a
   * waiter opens mid-service beside a live order, and a print button there is
   * one mis-touch away from paper the customer never asked for. The all-tables
   * history page is where somebody deliberately goes to find a finished order,
   * so that is where the button belongs.
   */
  allowReprint?: boolean;
  t: TFn;
  locale: string;
}) {
  const orderQuery = useOrder(shopSlug, orderId);
  const order = orderQuery.data?.data;

  // Một lượt probe cho CẢ màn: `GET /api/lan/print/status?order_id=` trả tally
  // của mọi loại chứng từ trong một response, và cặp `In gốc` / `In lại` của
  // hoá đơn lẫn hoá đơn đỏ đều đọc từ đây. Đọc theo từng nút sẽ là N round-trip
  // cho một màn, và N câu trả lời có thể mâu thuẫn vì mỗi cái chạm server ở một
  // thời điểm khác nhau.
  const { counts: printCounts, refresh: refreshPrintCounts } = useOrderPrintCounts(
    orderId,
    allowReprint,
  );

  const dt = (v: string | null | undefined) =>
    v ? formatDateTime(v, locale, { withSeconds: false }) : "—";
  const tm = (v: string | null | undefined) =>
    v ? formatTime(v, locale, { withSeconds: false }) : "—";

  // Items in the order they were added, so the detail reads as a timeline.
  const items = useMemo(() => {
    const rows = order?.items ?? [];
    return [...rows].sort((a, b) => {
      const ta = a.created_at ? new Date(a.created_at).getTime() : 0;
      const tb = b.created_at ? new Date(b.created_at).getTime() : 0;
      return ta - tb;
    });
  }, [order]);

  if (orderQuery.isLoading || !order) {
    return (
      <div className="flex items-center justify-center py-16">
        <Spinner />
      </div>
    );
  }

  const payments = order.payments ?? [];
  const remaining = num(order.remaining_amount);
  const settledPayments = payments.filter(isSettledPayment);

  /* Ba tờ giấy, ba điều kiện khác nhau — và chúng khác nhau vì tờ giấy nói
     những điều khác nhau.

     HOÁ ĐƠN chỉ hiện trên đơn `closed` (ruling của chủ quán): tờ đó khẳng định
     việc mua bán đã KẾT THÚC, nên mời in nó trên một đơn khách còn đang ngồi ăn
     là đưa cho khách một chứng từ về một việc chưa xảy ra. Cộng thêm điều kiện
     có thanh toán đã vào tiền — `closed` mà không có dòng thanh toán nào thì
     workstation không có gì để dựng tờ giấy, và nút sẽ 404.

     PHIẾU BẾP và PHIẾU ORDER thì không: cả hai chỉ kể lại đơn đang có gì. Bếp
     làm rơi phiếu giữa giờ phục vụ là lúc CẦN in lại nhất, và đơn lúc đó còn
     đang mở. Khoá chúng theo `closed` là khoá đúng lúc chúng hữu ích nhất. */
  const liveItems = (order.items ?? []).filter((it) => it.status !== "voided");
  const canReprintReceipt =
    allowReprint && order.status === "closed" && settledPayments.length > 0;
  const canReprintOrderSlips = allowReprint && liveItems.length > 0;
  // `enabled` phải nằm ở ĐÂY, không chỉ ở các nút con (#2538 review). Con tự
  // `return null` khi chưa ghép máy trạm, nhưng dải bao ngoài thì không kiểm —
  // nên máy chưa ghép vẫn thấy một đường kẻ trên và ba nhãn "Hoá đơn / Hoá đơn
  // đỏ / Phiếu" rỗng không nút nào. Trái đúng câu "nút ẩn hoàn toàn khi chưa
  // ghép" mà tính năng này tự đặt ra.
  const hasAnyReprint =
    workstationPrintService.enabled && (canReprintReceipt || canReprintOrderSlips);

  // Chỉ chia bill mới cần nút theo từng khách. Đơn một thanh toán thì nút ở trên
  // đã in đúng tờ đó rồi — hai nút làm cùng một việc chỉ tạo phân vân.
  const perPaymentReprint = canReprintReceipt && settledPayments.length > 1;

  return (
    <div className="mx-auto max-w-2xl px-4 py-6 sm:px-8">
      {/* mobile back */}
      <button
        type="button"
        onClick={onBack}
        aria-label={t("common.back")}
        className="text-muted-foreground hover:text-foreground -ml-1 mb-3 flex cursor-pointer items-center gap-1 text-sm lg:hidden"
      >
        <ChevronLeftIcon className="size-4" />
        {t("common.back")}
      </button>

      {/* Header — code + status, then the lifecycle as a quiet meta flow */}
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-xl font-semibold tracking-tight tabular-nums">
              {isProvisionalCode(order.order_code) ? t("pos.order.code_pending") : order.order_code}
            </h2>
            {showPlace && (
              // Which table (or "Mang đi" / "Tại quầy") the selected order is on
              // — a prominent chip next to the code so the all-tables history
              // makes the order's place unmistakable.
              <span className="inline-flex items-center gap-1 rounded-md border bg-muted/60 px-2 py-0.5 text-xs font-medium text-foreground">
                <MapPinIcon className="size-3.5 text-muted-foreground" />
                <span className="text-muted-foreground/80">
                  {t("pos.order_history.place")}
                </span>
                {orderPlaceLabel(order, t)}
              </span>
            )}
          </div>
          <div className="mt-2 flex flex-col gap-1 text-xs sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-4">
            <LifeEvent
              label={t("pos.table_history.opened_at")}
              value={dt(order.opened_at ?? order.created_at)}
            />
            {order.checkout_at && (
              <LifeEvent label={t("pos.table_history.checkout_at")} value={dt(order.checkout_at)} />
            )}
            {order.closed_at && (
              <LifeEvent label={t("pos.table_history.closed_at")} value={dt(order.closed_at)} />
            )}
            {order.voided_at && (
              <LifeEvent
                label={t("pos.table_history.order_voided_at")}
                value={
                  dt(order.voided_at) +
                  " · " +
                  (order.void_reason?.trim() || t("pos.table_history.no_reason"))
                }
                danger
              />
            )}
          </div>
        </div>
        <span
          className={cn(
            "shrink-0 rounded px-2 py-0.5 text-xs font-medium",
            orderStatusBadge(order, t).className,
          )}
        >
          {orderStatusBadge(order, t).label}
        </span>
      </div>

      {/* In — một dải riêng, không nhét vào cụm trạng thái ở trên. Đây là thứ
          DUY NHẤT trên trang này có tác dụng ra thế giới thật (giấy chạy ra khỏi
          máy in), nên nó phải đọc như hành động, chứ không như một huy hiệu nữa
          trong phần meta.

          Mỗi dòng có NHÃN của tờ giấy ở đầu. Không có nhãn thì sáu cái nút cạnh
          nhau đọc thành một hàng nút, và thu ngân phải suy ra cái nào ra tờ nào
          từ chữ trên nút — đúng lúc đang có khách đứng chờ. */}
      {hasAnyReprint && (
        <div
          data-slot="order-reprint-bar"
          className="mt-4 space-y-2 border-t border-border/60 pt-4"
        >
          {canReprintReceipt && (
            <>
              <PrintDocRow label={t("pos.print_doc.receipt.label")}>
                <PrintDocActions
                  kind="receipt"
                  orderId={order.id}
                  scopePaymentId={printCounts.untargetedScopePaymentId}
                  counts={printCounts.receipt}
                  onPrinted={refreshPrintCounts}
                  t={t}
                />
              </PrintDocRow>
              <PrintDocRow label={t("pos.print_doc.red_invoice.label")}>
                <PrintDocActions
                  kind="red_invoice"
                  orderId={order.id}
                  scopePaymentId={printCounts.untargetedScopePaymentId}
                  counts={printCounts.redInvoice}
                  customerName={customerDisplayName(order)}
                  onPrinted={refreshPrintCounts}
                  t={t}
                />
              </PrintDocRow>
            </>
          )}
          {canReprintOrderSlips && (
            <PrintDocRow label={t("pos.print_doc.slips.label")}>
              <ReprintButton
                kind="kitchen"
                orderId={order.id}
                label={t("pos.order_history.reprint_kitchen")}
                t={t}
              />
              <ReprintButton
                kind="hold"
                orderId={order.id}
                label={t("pos.order_history.reprint_hold")}
                t={t}
              />
            </PrintDocRow>
          )}
        </div>
      )}

      {/* Items — added-when, voided-when + why */}
      <Section label={t("pos.table_history.items")} count={items.length}>
        {items.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t("pos.table_history.no_items")}</p>
        ) : (
          <ul className="divide-y divide-border/50">
            {items.map((it) => {
              const voided = it.status === "voided";
              const promo = promoInfo(it);
              return (
                <li key={it.id} className="py-2.5 first:pt-0">
                  <div className="flex items-start justify-between gap-3 text-sm">
                    <span
                      className={cn(
                        "flex min-w-0 items-baseline gap-2",
                        voided && "text-muted-foreground line-through",
                      )}
                    >
                      <span className="shrink-0 tabular-nums text-muted-foreground">
                        {num(it.quantity)}×
                      </span>
                      <span className="min-w-0 break-words font-medium">{itemName(it)}</span>
                    </span>
                    <span
                      className={cn(
                        "shrink-0 tabular-nums",
                        voided ? "text-muted-foreground line-through" : "font-medium",
                      )}
                    >
                      {formatCurrency(num(it.subtotal))}
                    </span>
                  </div>
                  <ItemToppings item={it} voided={voided} />
                  {voided ? (
                    <div className="mt-1 text-xs text-amber-600 dark:text-amber-400">
                      {t("pos.table_history.item_voided", { time: tm(it.voided_at) })}
                      {" · "}
                      {t("pos.table_history.reason")}:{" "}
                      {it.void_reason?.trim() || t("pos.table_history.no_reason")}
                    </div>
                  ) : (
                    <>
                      {it.created_at && (
                        <div className="mt-0.5 text-xs text-muted-foreground">
                          {t("pos.table_history.item_added", { time: tm(it.created_at) })}
                        </div>
                      )}
                      {promo.has && (
                        <div className="mt-0.5 text-xs text-muted-foreground">
                          <span className="font-medium text-emerald-700 dark:text-emerald-400">
                            {t("pos.table_history.promotion")}
                            {promo.pct > 0 && ` −${promo.pct}%`}:
                          </span>{" "}
                          {promo.name ?? "—"}
                          {promo.orig > promo.unit && (
                            <>
                              {" · "}
                              <span className="line-through">{formatCurrency(promo.orig)}</span>
                              {" → "}
                              {formatCurrency(promo.unit)}
                            </>
                          )}
                        </div>
                      )}
                    </>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </Section>

      {/* Summary — discount / coupon, service charge, per-rate tax, total.

          Rows test `!== 0`, NOT `> 0` (#2138). `> 0` hid every NEGATIVE value too,
          and a negative discount / service charge / tax is corruption, not absence
          — #2130's split-bill truncation yields `tax = -1` on a 0%-tax order, which
          the old gate rendered as nothing at all. A wrong number on screen can be
          reported; a row that was never drawn cannot.

          The per-rate map below is deliberately NOT filtered: a 非課税 group carries
          base > 0 with tax = 0 and is mandatory on the document (Peppol BR-Z-08 /
          BR-E-08). `order-cart.tsx` had that same filter and dropped them (#2138). */}
      <div className="mt-5 rounded-lg bg-muted/30 px-4 py-3 text-sm">
        <TotalRow label={t("pos.table_history.subtotal")} value={formatCurrency(num(order.subtotal))} muted />
        {showsMoneyRow(order.discount_amount) && (
          <TotalRow label={t("pos.table_history.discount")} value={"−" + formatCurrency(num(order.discount_amount))} muted />
        )}
        <CouponSummary order={order} t={t} tm={tm} />
        {showsMoneyRow(order.service_charge) && (
          <TotalRow label={t("pos.table_history.service")} value={formatCurrency(num(order.service_charge))} muted />
        )}
        {order.tax_breakdown && order.tax_breakdown.length > 0 ? (
          order.tax_breakdown.map((b) => (
            <div key={b.rate} className="flex items-center justify-between gap-2 py-0.5">
              <span className="text-muted-foreground">
                {t("pos.table_history.tax_rate", { rate: b.rate })}
                <span className="ml-1 text-xs">
                  ({t("pos.table_history.tax_base", { amount: formatCurrency(b.taxable) })})
                </span>
              </span>
              <span className="tabular-nums">{formatCurrency(b.tax)}</span>
            </div>
          ))
        ) : showsMoneyRow(order.tax_amount) ? (
          <TotalRow label={t("pos.table_history.tax")} value={formatCurrency(num(order.tax_amount))} muted />
        ) : null}
        {order.is_tax_included && (
          <div className="pt-0.5 text-xs text-muted-foreground">
            {t("pos.table_history.tax_included")}
          </div>
        )}
        <div className="mt-2 border-t border-border/50 pt-2">
          <TotalRow label={t("pos.table_history.total")} value={formatCurrency(num(order.total_amount))} strong />
        </div>
        {remaining > 0 && (
          <TotalRow
            label={t("pos.table_history.remaining")}
            value={formatCurrency(remaining)}
            className="text-amber-600 dark:text-amber-400"
          />
        )}
      </div>

      {/* Payments — when, method, cash tendered + change */}
      <Section label={t("pos.table_history.payments")} count={payments.length}>
        {payments.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t("pos.table_history.no_payments")}</p>
        ) : (
          <ul className="space-y-2">
            {payments.map((p) => (
              <PaymentRow
                key={p.id}
                payment={p}
                orderId={order.id}
                // Bill chia: mỗi khách một tờ, và một bộ đếm bản in RIÊNG. Nút ở
                // dải trên in "thanh toán xác nhận gần nhất", tức tờ của khách
                // CUỐI — không phải tờ của người đang đứng đây hỏi. Khách #1 đã
                // có giấy cũng không được làm khách #3 mất bản GỐC của mình.
                allowReprint={perPaymentReprint && isSettledPayment(p)}
                receiptCounts={printCounts.receipt}
                redInvoiceCounts={printCounts.redInvoice}
                customerName={customerDisplayName(order)}
                onPrinted={refreshPrintCounts}
                t={t}
                tm={tm}
              />
            ))}
          </ul>
        )}
      </Section>
    </div>
  );
}

// ── Small pieces ────────────────────────────────────────────────────────────

/**
 * Toppings / options chosen on a line — same reading as the cart
 * (`order-cart.tsx`): one per row, `−` prefix for a remove-modifier, the extra
 * charge in parentheses when it costs something, `×N` when more than one.
 *
 * Two deliberate differences from the cart:
 *
 *  - **No collapse.** The cart hides everything past the first two behind a
 *    "xem thêm" toggle to keep live tickets compact; history is a document
 *    somebody opened to find out what was actually sold, and a line whose
 *    options are folded away cannot answer that.
 *  - **No `topping_subtotal` row.** The line's `subtotal` already includes the
 *    topping cost, so a second money number here would read as an extra charge.
 *
 * The name goes through `collapseMirroredToppingName` for the same reason the
 * cart does: orders written before the workstation-side fix carry a frozen
 * "Fish sauce · Fish sauce", and the LAN read path returns the stored value
 * verbatim — history is exactly where those old orders live.
 */
function ItemToppings({
  item,
  voided,
}: {
  item: CustomerOrderItem;
  voided: boolean;
}) {
  const toppings = item.toppings ?? [];
  if (toppings.length === 0) return null;

  return (
    <ul
      className={cn(
        "mt-1 space-y-0.5 border-l-2 border-border/70 pl-2.5 text-xs leading-relaxed text-muted-foreground",
        voided && "line-through",
      )}
    >
      {toppings.map((tp) => {
        const isRemove = tp.modifier_type === "remove";
        const name =
          tp.name != null
            ? collapseMirroredToppingName(tp.name)
            : tp.topping_group_item_id;
        const extra = num(tp.unit_price);
        return (
          <li key={tp.id} className="break-words">
            {isRemove ? "− " : ""}
            {name}
            {!isRemove && extra > 0 ? ` (${formatCurrency(extra)})` : ""}
            {Number(tp.quantity) > 1 ? ` ×${tp.quantity}` : ""}
          </li>
        );
      })}
    </ul>
  );
}

// A titled block separated from what precedes it by a hairline rule — reads as
// a document section, not a stacked card.
function Section({
  label,
  count,
  children,
}: {
  label: string;
  count?: number;
  children: React.ReactNode;
}) {
  return (
    <section className="mt-6 border-t border-border/60 pt-5">
      <h3 className="mb-2.5 flex items-baseline gap-1.5 text-sm font-semibold tracking-tight">
        {label}
        {count != null && (
          <span className="text-xs font-normal tabular-nums text-muted-foreground">{count}</span>
        )}
      </h3>
      {children}
    </section>
  );
}

// Một dòng của dải in: nhãn tờ giấy bên trái, các nút của nó bên phải.
function PrintDocRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
      <span className="w-24 shrink-0 text-xs font-medium text-muted-foreground">{label}</span>
      <div className="flex flex-wrap items-center gap-1.5">{children}</div>
    </div>
  );
}

// One lifecycle moment ("Tạo lúc 14:44") in the header meta flow.
function LifeEvent({
  label,
  value,
  danger,
}: {
  label: string;
  value: string;
  danger?: boolean;
}) {
  return (
    <span className={cn("min-w-0", danger && "text-red-600 dark:text-red-400")}>
      <span className={cn(danger ? "opacity-80" : "text-muted-foreground/70")}>{label}</span>{" "}
      <span className="tabular-nums">{value}</span>
    </span>
  );
}

// Coupon line in the totals block: name (+ code), what it does (10% up to ¥X,
// or a fixed amount), when it was applied, and the discount it took off.
function CouponSummary({
  order,
  t,
  tm,
}: {
  order: CustomerOrder;
  t: TFn;
  tm: (v: string | null | undefined) => string;
}) {
  const code = order.coupon_code_snapshot?.trim();
  if (!code) return null;

  const name = order.coupon_name?.trim();
  const discount = num(order.coupon_discount);
  const value = num(order.coupon_discount_value);
  const cap = num(order.coupon_max_discount_cap);
  const rule =
    order.coupon_discount_type === "percent"
      ? `${value}%` +
        (cap > 0 ? " · " + t("pos.table_history.coupon_cap", { amount: formatCurrency(cap) }) : "")
      : order.coupon_discount_type === "fixed"
        ? formatCurrency(value)
        : "";

  return (
    <div className="py-0.5 pl-3">
      <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
        <span className="min-w-0 truncate">
          {t("pos.table_history.coupon")}:{" "}
          <span className="font-medium text-foreground/80">{name || code}</span>
          {name && <span className="ml-1 font-mono text-[11px]">({code})</span>}
        </span>
        {discount > 0 && (
          <span className="shrink-0 tabular-nums">−{formatCurrency(discount)}</span>
        )}
      </div>
      {(rule || order.coupon_applied_at) && (
        <div className="text-[11px] text-muted-foreground/80">
          {rule}
          {rule && order.coupon_applied_at ? " · " : ""}
          {order.coupon_applied_at &&
            t("pos.table_history.coupon_applied", { time: tm(order.coupon_applied_at) })}
        </div>
      )}
    </div>
  );
}

function TotalRow({
  label,
  value,
  muted,
  strong,
  className,
}: {
  label: string;
  value: string;
  muted?: boolean;
  strong?: boolean;
  className?: string;
}) {
  return (
    <div className={cn("flex items-center justify-between gap-2 py-0.5", strong && "text-base", className)}>
      <span className={cn(muted && "text-muted-foreground", strong && "font-semibold")}>{label}</span>
      <span className={cn("tabular-nums", strong && "font-semibold")}>{value}</span>
    </div>
  );
}

function PaymentRow({
  payment,
  orderId,
  allowReprint,
  receiptCounts,
  redInvoiceCounts,
  customerName,
  onPrinted,
  t,
  tm,
}: {
  payment: OrderPayment & { payment_method_name?: string };
  orderId: string;
  allowReprint?: boolean;
  receiptCounts?: PrintKindCounts;
  redInvoiceCounts?: PrintKindCounts;
  customerName?: string | null;
  onPrinted?: () => void;
  t: TFn;
  tm: (v: string | null | undefined) => string;
}) {
  const tint = PAYMENT_STATUS_TINT[payment.status] ?? "bg-muted text-muted-foreground";
  const method =
    payment.payment_method_name?.trim() ||
    payment.payment_method?.name?.trim() ||
    "—";
  const when = payment.paid_at ?? payment.created_at;
  const tendered = num(payment.tendered_amount);
  const change = num(payment.change_amount);
  const refunded = num((payment as { refunded_amount?: number | string }).refunded_amount);

  return (
    <li className="rounded-lg bg-muted/30 px-3 py-2.5 text-sm">
      <div className="flex items-center gap-2">
        <span className="font-medium">{method}</span>
        <span className={cn("rounded px-1.5 py-0.5 text-[10px] font-medium", tint)}>
          {t(`pos.payment_status.${payment.status}`)}
        </span>
        <span className="ml-auto font-semibold tabular-nums">
          {formatCurrency(num(payment.amount))}
        </span>
      </div>
      <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
        {when && <span>{t("pos.table_history.paid_at", { time: tm(when) })}</span>}
        {tendered > 0 && (
          <span>
            {t("pos.table_history.tendered")} {formatCurrency(tendered)}
          </span>
        )}
        {change > 0 && (
          <span>
            {t("pos.table_history.change")} {formatCurrency(change)}
          </span>
        )}
        {refunded > 0 && (
          <span className="text-amber-600 dark:text-amber-400">
            {t("pos.table_history.refunded")} {formatCurrency(refunded)}
          </span>
        )}
      </div>
      {allowReprint && (
        <div className="mt-2 space-y-1.5 border-t border-border/40 pt-2">
          <PrintDocActions
            kind="receipt"
            orderId={orderId}
            paymentId={payment.id}
            counts={receiptCounts}
            onPrinted={onPrinted}
            compact
            t={t}
          />
          <PrintDocActions
            kind="red_invoice"
            orderId={orderId}
            paymentId={payment.id}
            counts={redInvoiceCounts}
            customerName={customerName}
            onPrinted={onPrinted}
            compact
            t={t}
          />
        </div>
      )}
    </li>
  );
}
