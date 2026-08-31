"use client";

/**
 * Phần dựng của trang CHI TIẾT đơn hàng — dùng chung cho HAI màn hình:
 *
 *   - `/orders/{id}`          — khách vãng lai
 *   - `/account/orders/{id}`  — khách đã đăng nhập
 *
 * Giống hệt lý do của `order-history-card.tsx` ở trang danh sách: hai màn hình
 * này từng là hai bản dựng khác hẳn nhau cho cùng một thứ. Bản guest có status
 * banner đổi màu theo trạng thái, ảnh món, topping đã chọn, countdown hạn
 * thanh toán và nút trả tiền; bản account chỉ có badge status thô của BE và
 * dòng text `2 x ¥500`.
 *
 * Hai nguồn dữ liệu giờ cùng một contract: `CustomerOrderController::
 * formatOrder()` (guest) và `CustomerOrderDetailResource` (account).
 *
 * Những gì CHỈ trang account có — danh sách payment, tên chi nhánh, bàn, số
 * khách, ghi chú đơn — vào qua các field optional, nên trang guest không đổi
 * một pixel nào khi BE không gửi chúng.
 */

import { useTranslations, useLocale } from "next-intl";
import { Link, useRouter } from "@/i18n/routing";
import {
  AlertCircle,
  ArrowLeft,
  CheckCircle2,
  CreditCard,
  Loader2,
  Receipt,
  ShoppingBag,
  Utensils,
  XCircle,
} from "lucide-react";
import Header from "@/components/Header";
import { Button } from "@/components/ui/button";
import { formatCurrency } from "@/lib/currency";
import { formatGuestDate, formatGuestTime } from "@/lib/date-format";
import { isOrderStillPayable } from "@/lib/order-expiry";
import { computePrepEta } from "@/lib/order-prep-eta";
import OrderCountdownBadge from "@/components/order-countdown-badge";
import { useNowMs } from "@/hooks/use-now-ms";
import { TaxBreakdownLines } from "@/components/tax-breakdown-lines";
import type { TaxBreakdownRow } from "@/lib/tax";

// ─── BE response shape (union of both payloads) ───────────────────────────

export interface OrderDetailItemOption {
  id: string;
  name: string | null;
  unit_price: number;
  quantity: number;
}

export interface OrderDetailItem {
  id: string;
  name: string | null;
  image_url: string | null;
  variant: string | null;
  qty: number;
  unit_price: number;
  subtotal: number;
  note: string | null;
  options: OrderDetailItemOption[];
  status: string;
}

export interface OrderDetailPayment {
  id: string;
  amount: number;
  status: string;
  payment_method?: string | null;
  tip_amount?: number;
  created_at?: string | null;
  paid_at?: string | null;
}

export interface OrderDetailData {
  id: string;
  code: string;
  status: string;
  placed_at: string | null;
  items: OrderDetailItem[];
  total: number;
  paid: number;
  /** ISO 4217 currency the order was created in (from its branch). Money on
   *  this page formats with THIS, not the ambient selected-branch currency. */
  currency: string;
  remaining: number;
  is_fully_paid: boolean;
  /** Số payment record — 0 = counter flow chưa trả, >0 = card flow */
  payment_count: number;
  discount_amount: number;
  coupon_code_snapshot: string | null;
  tax_amount: number;
  service_charge: number;
  /** plan-043 — per-rate breakdown (8%対象 / 10%対象) + tax mode snapshot. */
  is_tax_included?: boolean;
  tax_breakdown?: TaxBreakdownRow[] | null;
  /** Kitchen prep timing — BE expose từ CustomerOrder model. */
  scheduled_pickup_time?: string | null;
  estimated_ready_time: string | null;
  actual_ready_time: string | null;
  preparation_minutes: number | null;
  /** plan-031 takeaway payment countdown — null = no deadline. */
  payment_due_at: string | null;
  /** plan-031 — server-anchored remaining seconds (skew-immune countdown). */
  seconds_until_due?: number | null;
  /** #370 — gate "Pay now" button on takeaway only. */
  order_type: string;

  // ── Chỉ có ở payload của trang account ────────────────────────────────
  branch?: { id?: string; name?: string; slug?: string | null } | null;
  tables?: Array<{ id: string; name: string }> | null;
  payments?: OrderDetailPayment[] | null;
  guest_count?: number;
  note?: string | null;
}

// ─── Frame (sub-header sticky mobile) ─────────────────────────────────────

export function OrderDetailFrame({
  children,
  title,
  onBack,
  backLabel,
}: {
  children: React.ReactNode;
  title: string;
  onBack: () => void;
  backLabel: string;
}) {
  return (
    <div className="flex min-h-screen flex-col bg-[#FAFAFA]">
      {/* hideShadow để mobile sub-header sticky bám flush global header */}
      <Header showLogo hideSwitcher hideOrderCta hideShadow hideRegister />

      {/* Sub-header.
          - Mobile: sticky `top-12 z-30 bg-white` + border-b (pattern
            khớp /checkout, /orders, /confirm).
          - Desktop: non-sticky, bg-#FAFAFA cùng page, `md:border-b-0`
            tránh duplicate với border global Header. Container
            `md:max-w-7xl` để title align cùng cột dọc với brand logo
            trong Header phía trên. */}
      <div className="sticky top-12 z-30 border-b border-neutral-200 bg-white md:static md:top-auto md:z-auto md:border-b-0 md:bg-[#FAFAFA]">
        <div className="mx-auto flex max-w-2xl items-center gap-2 px-4 py-3 md:max-w-7xl md:px-6 md:py-4">
          <button
            onClick={onBack}
            aria-label={backLabel}
            className="-ml-1 flex size-7 items-center justify-center rounded-lg text-neutral-700 transition-colors hover:bg-muted"
          >
            <ArrowLeft className="size-5" />
          </button>
          <h1 className="truncate text-base font-bold text-neutral-900">{title}</h1>
        </div>
      </div>

      {children}
    </div>
  );
}

export function OrderDetailLoader() {
  return (
    <div className="flex min-h-screen flex-col bg-[#FAFAFA]">
      <Header showLogo hideSwitcher hideRegister />
      <div className="flex flex-1 items-center justify-center">
        <Loader2 className="size-8 animate-spin text-primary" />
      </div>
    </div>
  );
}

export function OrderDetailErrorBlock({
  icon,
  title,
  message,
  ctaHref,
  ctaLabel,
}: {
  icon: React.ReactNode;
  title: string;
  message: string;
  ctaHref: string;
  ctaLabel: string;
}) {
  return (
    <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col items-center justify-center px-6 text-center">
      {icon}
      <h2 className="mt-3 text-lg font-semibold text-neutral-800">{title}</h2>
      <p className="mt-1 text-sm text-muted-foreground">{message}</p>
      <Link href={ctaHref} className="mt-5">
        <Button variant="outline">{ctaLabel}</Button>
      </Link>
    </main>
  );
}

// ─── Body ─────────────────────────────────────────────────────────────────

export interface OrderDetailBodyProps {
  order: OrderDetailData;
  /** Trang thanh toán tương ứng — null để ẩn hẳn nút (đơn không trả được nữa). */
  payHref: string;
  /** Gọi khi countdown chạm 0 để trang refetch đơn từ server. */
  onPaymentExpired?: () => void;
  /** Render dưới cùng — banner nhắc đăng nhập của guest, hoặc không có gì. */
  footer?: React.ReactNode;
}

export function OrderDetailBody({
  order,
  payHref,
  onPaymentExpired,
  footer,
}: OrderDetailBodyProps) {
  // Format money in the ORDER's own currency (not the ambient selected branch).
  const fmt = (v: number) => formatCurrency(v, order.currency);

  return (
    <main className="mx-auto w-full max-w-2xl flex-1 space-y-4 px-4 py-4 md:px-6 md:py-6">
      {/* Status banner — derive từ items[].status thay vì order.status.
          BE set order.status=closed ngay khi card paid, nhưng items
          vẫn pending cho tới khi staff bếp update → banner sẽ hiển
          thị "Đang chuẩn bị" đúng tiến độ. */}
      <StatusBanner order={order} onPaymentExpired={onPaymentExpired} />

      {/* Outer card — wrap order info + items trong 1 div lớn có
          border (theo Figma). Mỗi item bên trong vẫn có card riêng
          với border để tách lớp giữa các món. */}
      <div className="space-y-3 rounded-2xl border border-neutral-200 bg-white p-3 shadow-sm">
        {/* Order info — không có inner border, chỉ là content row */}
        <div className="px-1 pt-1">
          <OrderInfoBody order={order} />
        </div>

        {/* Mỗi item 1 card có border riêng */}
        {order.items.map((item) => (
          <div key={item.id} className="rounded-2xl border border-neutral-200 p-3">
            <ItemRow item={item} fmt={fmt} />
          </div>
        ))}

        {/* #515 — order-level money summary. Server-authoritative total
            (order.total) to avoid re-deriving a mismatched figure (#514). */}
        <OrderSummary order={order} fmt={fmt} />

        {/* Pay button — bottom-right corner of the card, below the items */}
        <div className="flex justify-end">
          <CornerPayButton order={order} payHref={payHref} />
        </div>
      </div>

      {/* Danh sách thanh toán — chỉ trang account nhận field này. */}
      {order.payments && order.payments.length > 0 && (
        <PaymentsCard payments={order.payments} fmt={fmt} />
      )}

      {footer}
    </main>
  );
}

// ─── Status Banner ────────────────────────────────────────────────────────

function StatusBanner({
  order,
  onPaymentExpired,
}: {
  order: OrderDetailData;
  onPaymentExpired?: () => void;
}) {
  const t = useTranslations("guestOrders");
  const nowMs = useNowMs();

  // Derive banner kind từ items[].status + payment state (chính xác hơn
  // order.status — BE set closed ngay khi card paid dù items chưa nấu).
  const cfg = resolveBannerCfg(
    order.status,
    order.items,
    order.is_fully_paid,
    order.payment_count,
  );

  // Subtitle theo banner kind. Cho "preparing" dùng real ETA data từ BE
  // theo thứ tự ưu tiên (xem `computePrepEta`); fallback heuristic
  // 15 + 3*qty nếu BE chưa set field nào.
  const totalQty = order.items.reduce((sum, it) => sum + it.qty, 0);
  const eta = computePrepEta({
    placedAt: order.placed_at,
    estimatedReadyTime: order.estimated_ready_time,
    actualReadyTime: order.actual_ready_time,
    preparationMinutes: order.preparation_minutes,
    totalQty,
    nowMs,
  });

  const subtitle =
    cfg.kind === "preparing"
      ? eta.label
        ? t(eta.labelKey, eta.params)
        : t("estimatedCompletion", { minutes: eta.fallbackMinutes })
      : cfg.kind === "ready"
        ? order.actual_ready_time
          ? t("readyForPickupAt", { time: formatTime(order.actual_ready_time) })
          : t("readyForPickup")
        : cfg.kind === "completed"
          ? t("completedMessage")
          : cfg.kind === "unpaid"
            ? t("unpaidMessage")
            : cfg.kind === "cancelled"
              ? t("cancelledMessage")
              : "";

  return (
    <div
      className="flex items-center gap-3 rounded-2xl px-4 py-4 shadow-sm"
      style={{ background: cfg.bg, color: cfg.fg }}
    >
      <div
        className="flex size-12 shrink-0 items-center justify-center rounded-full"
        style={{ backgroundColor: cfg.iconBg }}
      >
        {cfg.icon}
      </div>
      <div className="min-w-0 flex-1">
        <p className="text-base font-bold leading-tight">{t(cfg.labelKey)}</p>
        {subtitle && (
          <p className="mt-0.5 text-xs leading-snug opacity-95">{subtitle}</p>
        )}
      </div>

      {/* plan-031 — payment countdown. Chỉ hiện khi đơn chưa trả (banner
          kind "unpaid") và BE có set deadline. Countdown neo vào server
          `seconds_until_due` nên không lệch khi client sai giờ. Hết giờ →
          refetch để trạng thái đơn đồng bộ lại với BE. */}
      {cfg.kind === "unpaid" && order.payment_due_at && (
        <div className="flex shrink-0 flex-col items-end gap-0.5">
          <span className="text-[10px] font-medium uppercase tracking-wide opacity-80">
            {t("countdownRemaining")}
          </span>
          <OrderCountdownBadge
            paymentDueAt={order.payment_due_at}
            secondsUntilDue={order.seconds_until_due}
            onExpired={onPaymentExpired}
          />
        </div>
      )}
    </div>
  );
}

function formatTime(iso: string): string {
  try {
    const d = new Date(iso);
    const hh = String(d.getHours()).padStart(2, "0");
    const mi = String(d.getMinutes()).padStart(2, "0");
    return `${hh}:${mi}`;
  } catch {
    return iso;
  }
}

type StatusBannerCfg = {
  kind: "preparing" | "ready" | "completed" | "unpaid" | "cancelled";
  bg: string;
  /** Text/icon color — phải đủ contrast với bg (vd bg vàng nhạt cần text dark amber) */
  fg: string;
  iconBg: string;
  icon: React.ReactNode;
  labelKey:
    | "statusPreparing"
    | "statusReady"
    | "statusCompleted"
    | "statusUnpaid"
    | "statusCancelled";
};

/**
 * Aggregate per-item OrderItemStatusEnum thành 1 kitchen state.
 * Sync với helper cùng tên ở `lib/order-history.ts` (trang danh sách).
 */
function deriveKitchenFromItems(
  items: OrderDetailItem[],
): "preparing" | "ready" | "all-served" | null {
  if (items.length === 0) return null;
  const active = items.map((i) => i.status).filter((s) => s !== "voided");
  if (active.length === 0) return null;

  if (active.every((s) => s === "served")) return "all-served";
  if (active.every((s) => s === "ready" || s === "served")) return "ready";
  return "preparing";
}

/**
 * Combine items[].status + payment state thành banner config. Priority:
 *   1. order.status === "voided" → cancelled
 *   2. Counter chưa trả (!isFullyPaid && paymentCount === 0) → unpaid
 *   3. items all served → completed (đã giao + có thể đã ăn)
 *   4. items all ready → ready (bếp xong, mời khách đến lấy)
 *   5. Else → preparing (mặc định khi card paid mà items chưa server xong)
 */
function resolveBannerCfg(
  status: string,
  items: OrderDetailItem[],
  isFullyPaid: boolean,
  paymentCount: number,
): StatusBannerCfg {
  if (status === "voided" || status === "cancelled") {
    return {
      kind: "cancelled",
      bg: "#6B7280",
      fg: "#FFFFFF",
      iconBg: "rgba(255,255,255,0.2)",
      icon: <XCircle className="size-6" strokeWidth={2} />,
      labelKey: "statusCancelled",
    };
  }

  // Counter flow chưa trả tại quầy (không có Stripe PI). Spec Figma:
  // bg #FFE9AB (vàng nhạt) — cần fg dark amber để contrast đọc được.
  if (!isFullyPaid && paymentCount === 0) {
    return {
      kind: "unpaid",
      bg: "#FFE9AB",
      fg: "#92400E", // amber-800
      iconBg: "rgba(146, 64, 14, 0.15)", // amber-800 with low alpha
      icon: <AlertCircle className="size-6" strokeWidth={2} />,
      labelKey: "statusUnpaid",
    };
  }

  const derived = deriveKitchenFromItems(items);

  if (derived === "all-served") {
    return {
      kind: "completed",
      // Flat brand green (#2D8336) — same green used across customer-web
      // (CTA buttons, other status banners), kept simple per design.
      bg: "#2D8336",
      fg: "#FFFFFF",
      iconBg: "rgba(255,255,255,0.2)",
      icon: <CheckCircle2 className="size-6" strokeWidth={2} />,
      labelKey: "statusCompleted",
    };
  }

  if (derived === "ready") {
    return {
      kind: "ready",
      bg: "#F59E0B",
      fg: "#FFFFFF",
      iconBg: "rgba(255,255,255,0.2)",
      icon: <ShoppingBag className="size-6" strokeWidth={2} />,
      labelKey: "statusReady",
    };
  }

  // derived === "preparing" hoặc null (items rỗng / toàn voided) → mặc
  // định "Đang chuẩn bị" — phù hợp khi card paid mà items chưa server.
  return {
    kind: "preparing",
    bg: "#2D8336",
    fg: "#FFFFFF",
    iconBg: "rgba(255,255,255,0.2)",
    icon: <KitchenIcon />,
    labelKey: "statusPreparing",
  };
}

// ─── Order info body (render trong card chung với items list) ────────────

/**
 * Compact "Thanh toán" button in the corner of the order-info card for any
 * still-unpaid takeaway order — mirrors the pay button on the Order History
 * list card.
 */
function CornerPayButton({
  order,
  payHref,
}: {
  order: OrderDetailData;
  payHref: string;
}) {
  const t = useTranslations("guestOrders");
  const router = useRouter();
  // plan-054 T6.10 — the shared predicate; see `isOrderStillPayable` for why
  // each status is in it.
  if (!isOrderStillPayable(order)) return null;
  return (
    <Button
      onClick={() => router.push(payHref)}
      className="h-8 rounded-lg px-4 text-xs text-white md:text-sm"
      style={{ height: "32px", fontWeight: 500, backgroundColor: "#2D8336" }}
    >
      {t("actionContinuePay")}
    </Button>
  );
}

function OrderInfoBody({ order }: { order: OrderDetailData }) {
  const t = useTranslations("guestOrders");
  const locale = useLocale();
  const isTakeaway = (order.order_type ?? "takeaway") === "takeaway";
  const tableNames = (order.tables ?? []).map((tbl) => tbl.name).join(", ");

  return (
    <>
      {/* Row 1: Mã đơn hàng + green pill code (4 ký tự cuối, prefix #) */}
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-bold text-neutral-900 md:text-[18px]">
          {t("orderCode")}
        </span>
        <span
          className="rounded-full px-2.5 py-0.5 text-[11px] font-bold tabular-nums md:text-xs"
          style={{ backgroundColor: "#DCFCE7", color: "#15803D" }}
        >
          #{order.code.slice(-4)}
        </span>
      </div>

      {/* Row 2: Ngày đặt + datetime */}
      {order.placed_at && (
        <InfoRow
          label={t("orderDate")}
          value={formatDateTime(order.placed_at, locale)}
        />
      )}

      {/* Các dòng dưới chỉ có ở payload trang account — guest không gửi
          `branch` / `tables` / `guest_count` nên không dòng nào render. */}
      {order.branch?.name && (
        <InfoRow label={t("branchLabel")} value={order.branch.name} />
      )}
      {tableNames && <InfoRow label={t("tableLabel")} value={tableNames} />}
      {typeof order.guest_count === "number" && order.guest_count > 0 && (
        <InfoRow
          label={t("guestCountLabel")}
          value={t("guestCountValue", { count: order.guest_count })}
        />
      )}

      {/* Row cuối: 🛍 Mang về / 🍴 Tại chỗ (right aligned) */}
      <div className="mt-2 flex justify-end">
        <span className="inline-flex items-center gap-1 text-xs text-neutral-700">
          {isTakeaway ? (
            <TakeawayIcon />
          ) : (
            <Utensils className="size-3.5 shrink-0" color="#27A14F" />
          )}
          {isTakeaway ? t("takeawayLabel") : t("dineInLabel")}
        </span>
      </div>

      {/* Ghi chú cả đơn (chỉ payload account) */}
      {order.note && (
        <div className="mt-2 rounded-lg bg-neutral-50 px-3 py-2 text-xs italic text-neutral-600">
          “{order.note}”
        </div>
      )}
    </>
  );
}

function InfoRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="mt-2 flex items-center justify-between gap-2 text-sm">
      <span className="shrink-0 text-neutral-600">{label}</span>
      <span className="min-w-0 truncate text-right text-neutral-900 tabular-nums">
        {value}
      </span>
    </div>
  );
}

// ─── Order money summary (#515) ───────────────────────────────────────────

function OrderSummary({
  order,
  fmt,
}: {
  order: OrderDetailData;
  fmt: (n: number) => string;
}) {
  const t = useTranslations("guestOrders");
  // Subtotal = sum of per-line subtotals (each already includes qty + options).
  // There is no order-level subtotal field on the BE payload, so derive it from
  // the same server-provided line figures we already render above.
  const subtotal = order.items.reduce((sum, item) => sum + item.subtotal, 0);
  const discount = order.discount_amount ?? 0;
  const serviceCharge = order.service_charge ?? 0;
  const tax = order.tax_amount ?? 0;

  // plan-043 — chỉ tách theo mức thuế khi đơn THỰC SỰ có nhiều mức (8% + 10%).
  // Đơn một mức — đại đa số — giữ đúng một dòng "Thuế" như cũ; hoá đơn nhiều
  // mức thì một dòng gộp là giấu mất thông tin インボイス bắt buộc phải nêu.
  const rateRows = (order.tax_breakdown ?? []).filter(
    (r) => r.rate > 0 || r.tax !== 0,
  );
  const showPerRate = rateRows.length > 1;

  return (
    <div className="space-y-2.5 border-t border-neutral-100 px-1 pt-3">
      <div className="flex items-center justify-between gap-3 text-sm">
        <span className="min-w-0 flex-1 text-neutral-600">{t("subtotal")}</span>
        <span className="shrink-0 font-medium text-neutral-900 tabular-nums">
          {fmt(subtotal)}
        </span>
      </div>

      {discount > 0 && (
        <div className="flex items-center justify-between gap-3 text-sm">
          <span className="min-w-0 flex-1 text-green-600">
            {order.coupon_code_snapshot
              ? t("discountWithCoupon", { code: order.coupon_code_snapshot })
              : t("discount")}
          </span>
          <span className="shrink-0 font-medium text-green-600 tabular-nums">
            - {fmt(discount)}
          </span>
        </div>
      )}

      {serviceCharge > 0 && (
        <div className="flex items-center justify-between gap-3 text-sm">
          <span className="min-w-0 flex-1 text-neutral-600">
            {t("serviceCharge")}
          </span>
          <span className="shrink-0 font-medium text-neutral-900 tabular-nums">
            {fmt(serviceCharge)}
          </span>
        </div>
      )}

      {showPerRate ? (
        <TaxBreakdownLines
          breakdown={rateRows}
          isTaxIncluded={order.is_tax_included}
          format={fmt}
          namespace="guestOrders"
          className="space-y-2.5"
        />
      ) : tax > 0 ? (
        <div className="flex items-center justify-between gap-3 text-sm">
          <span className="min-w-0 flex-1 text-neutral-600">{t("tax")}</span>
          <span className="shrink-0 font-medium text-neutral-900 tabular-nums">
            {fmt(tax)}
          </span>
        </div>
      ) : null}

      <div className="flex items-center justify-between gap-3 border-t border-neutral-100 pt-2.5">
        <span className="min-w-0 flex-1 text-base font-bold text-neutral-900">
          {t("total")}
        </span>
        <span
          className="shrink-0 text-lg font-bold tabular-nums"
          style={{ color: "#006A34" }}
        >
          {fmt(order.total)}
        </span>
      </div>
    </div>
  );
}

function ItemRow({
  item,
  fmt,
}: {
  item: OrderDetailItem;
  fmt: (n: number) => string;
}) {
  return (
    <div className="flex gap-3">
      {/* Thumb */}
      <div className="size-14 shrink-0 overflow-hidden rounded-lg bg-neutral-100">
        {item.image_url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={item.image_url}
            alt={item.name ?? ""}
            className="h-full w-full object-cover"
          />
        ) : (
          <div className="flex h-full w-full items-center justify-center text-neutral-300">
            <Receipt className="size-5" strokeWidth={1.5} />
          </div>
        )}
      </div>

      {/* Body */}
      <div className="min-w-0 flex-1">
        <div className="flex items-start justify-between gap-2">
          <p className="text-sm font-bold text-neutral-900 md:text-base">
            {item.name ?? "—"}
          </p>
          {/* Quantity x3 — spec Figma: 16px / 700 / #1F2937 */}
          <span
            className="shrink-0 tabular-nums"
            style={{ fontSize: "16px", fontWeight: 700, color: "#1F2937" }}
          >
            x{item.qty}
          </span>
        </div>

        {/* Variant (if any, from BE `variant` field) */}
        {item.variant && (
          <p className="mt-0.5 text-[11px] text-neutral-500 md:text-xs">
            {item.variant}
          </p>
        )}

        {/* Options — "+ Loại Đặc biệt (¥100)" format. Filter ra option có
            name để tránh empty bullet khi BE trả null. */}
        {item.options.length > 0 && (
          <ul className="mt-0.5 space-y-0.5">
            {item.options
              .filter((opt) => opt.name)
              .map((opt) => (
                <li
                  key={opt.id}
                  className="text-[11px] text-neutral-500 tabular-nums md:text-xs"
                >
                  + {opt.name}
                  {opt.unit_price > 0 && ` (${fmt(opt.unit_price)})`}
                </li>
              ))}
          </ul>
        )}

        {/* Item note (vd "Không hành") nếu có */}
        {item.note && (
          <p className="mt-0.5 text-[11px] italic text-neutral-500 md:text-xs">
            “{item.note}”
          </p>
        )}

        {/* Subtotal — tổng tiền của dòng (đã nhân qty + options) */}
        <p className="mt-1 text-sm font-bold tabular-nums text-neutral-900 md:text-base">
          {fmt(item.subtotal)}
        </p>
      </div>
    </div>
  );
}

// ─── Payments (account only) ──────────────────────────────────────────────

function PaymentsCard({
  payments,
  fmt,
}: {
  payments: OrderDetailPayment[];
  fmt: (n: number) => string;
}) {
  const t = useTranslations("guestOrders");
  const locale = useLocale();

  return (
    <div className="rounded-2xl border border-neutral-200 bg-white p-3 shadow-sm">
      <h2 className="flex items-center gap-2 px-1 pb-2 pt-1 text-sm font-bold text-neutral-900">
        <CreditCard className="size-4 text-neutral-500" />
        {t("paymentsTitle", { count: payments.length })}
      </h2>
      <div className="space-y-2">
        {payments.map((payment) => {
          const at = payment.paid_at ?? payment.created_at ?? null;
          return (
            <div
              key={payment.id}
              className="flex items-start justify-between gap-3 rounded-2xl border border-neutral-200 p-3"
            >
              <div className="min-w-0 flex-1">
                <p className="text-sm font-bold tabular-nums text-neutral-900">
                  {fmt(payment.amount)}
                </p>
                {payment.payment_method && (
                  <p className="mt-0.5 text-xs font-medium text-emerald-700">
                    {payment.payment_method}
                  </p>
                )}
                {(payment.tip_amount ?? 0) > 0 && (
                  <p className="text-xs text-neutral-500 tabular-nums">
                    {t("tipAmount", { amount: fmt(payment.tip_amount ?? 0) })}
                  </p>
                )}
                {at && (
                  <p className="mt-0.5 text-[11px] text-neutral-500 tabular-nums">
                    {formatDateTime(at, locale)}
                  </p>
                )}
              </div>
              <span className="shrink-0 rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium text-neutral-600">
                {payment.status}
              </span>
            </div>
          );
        })}
      </div>
    </div>
  );
}

// ─── Icons ────────────────────────────────────────────────────────────────

/**
 * Custom knife + fork crossed icon — dùng cho status banner "Đang chuẩn
 * bị". Replace `UtensilsCrossed` lucide để có shape + fill #F7FFF3
 * (off-white) theo Figma, nổi rõ trên bg xanh đậm #2D8336.
 */
function KitchenIcon() {
  return (
    <svg
      width="23"
      height="23"
      viewBox="0 0 23 23"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden="true"
    >
      <path
        d="M1.75 22.5L0 20.75L12.8125 7.9375C12.4375 7.0625 12.3854 6.07292 12.6562 4.96875C12.9271 3.86458 13.5208 2.875 14.4375 2C15.5417 0.895833 16.7708 0.25 18.125 0.0625C19.4792 -0.125 20.5833 0.208333 21.4375 1.0625C22.2917 1.91667 22.625 3.02083 22.4375 4.375C22.25 5.72917 21.6042 6.95833 20.5 8.0625C19.625 8.97917 18.6354 9.57292 17.5312 9.84375C16.4271 10.1146 15.4375 10.0625 14.5625 9.6875L13 11.25L22.5 20.75L20.75 22.5L11.25 13.0625L1.75 22.5ZM5.4375 11.8125L1.6875 8.0625C0.5625 6.9375 0 5.59375 0 4.03125C0 2.46875 0.5625 1.125 1.6875 0L9.4375 7.8125L5.4375 11.8125Z"
        fill="#F7FFF3"
      />
    </svg>
  );
}

/**
 * Custom takeaway box icon (hộp đựng đồ với fill #27A14F) — đồng nhất
 * với icon ở trang danh sách. Replace `ShoppingBag` lucide để đúng
 * brand color per Figma.
 */
function TakeawayIcon() {
  return (
    <svg
      width="14"
      height="14"
      viewBox="0 0 14 14"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden="true"
      className="shrink-0"
    >
      <path
        d="M1.96875 1.75C1.36442 1.75 0.875 2.24 0.875 2.84375V3.28125C0.875 3.88558 1.365 4.375 1.96875 4.375H12.0312C12.635 4.375 13.125 3.885 13.125 3.28125V2.84375C13.125 2.23942 12.635 1.75 12.0312 1.75H1.96875Z"
        fill="#27A14F"
      />
      <path
        fillRule="evenodd"
        clipRule="evenodd"
        d="M1.80078 5.25L2.11578 10.6027C2.14196 11.0481 2.33727 11.4667 2.66179 11.7729C2.98631 12.079 3.41553 12.2497 3.8617 12.25H10.1366C10.583 12.25 11.0125 12.0795 11.3373 11.7733C11.662 11.467 11.8575 11.0483 11.8837 10.6027L12.1993 5.25H1.80078ZM5.39586 7.4375C5.39586 7.32147 5.44196 7.21019 5.52401 7.12814C5.60605 7.04609 5.71733 7 5.83336 7H8.1667C8.28273 7 8.39401 7.04609 8.47606 7.12814C8.5581 7.21019 8.6042 7.32147 8.6042 7.4375C8.6042 7.55353 8.5581 7.66481 8.47606 7.74686C8.39401 7.82891 8.28273 7.875 8.1667 7.875H5.83336C5.71733 7.875 5.60605 7.82891 5.52401 7.74686C5.44196 7.66481 5.39586 7.55353 5.39586 7.4375Z"
        fill="#27A14F"
      />
    </svg>
  );
}

// ─── Helpers ──────────────────────────────────────────────────────────────

/** `30/07/2026 - 14:05` in vi, `2026/07/30 - 14:05` in ja (#1261). */
function formatDateTime(iso: string, locale: string): string {
  const date = formatGuestDate(iso, locale);
  const time = formatGuestTime(iso, locale);
  if (!date) return iso;
  return time ? `${date} - ${time}` : date;
}
