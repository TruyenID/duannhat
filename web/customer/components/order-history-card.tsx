"use client";

/**
 * Card lịch sử đơn hàng — dùng chung cho HAI màn hình:
 *
 *   - `/orders`          — khách vãng lai, danh sách dựng từ pointer localStorage
 *   - `/account/orders`  — khách đã đăng nhập, danh sách do BE phân trang
 *
 * Trước đây mỗi trang tự vẽ card của mình và hai bản đã lệch hẳn nhau: bản
 * guest theo Figma (ảnh món, trạng thái thanh toán, countdown, nút hành động)
 * còn bản account chỉ có mã đơn + badge status thô của BE — khách đăng nhập
 * thấy màn hình nghèo hơn khách vãng lai. Card sống ở đây để không lệch lại.
 *
 * Cả hai nguồn dữ liệu đều là `CustomerOrderSummaryResource`, nên component
 * chỉ nhận đúng resource đó cộng vài thứ phụ thuộc màn hình (link chi tiết,
 * mã hiển thị, ngày, branch slug).
 */

import { useState } from "react";
import { Link, useRouter } from "@/i18n/routing";
import { useTranslations, useLocale } from "next-intl";
import { toast } from "sonner";
import { Check, Receipt, Utensils } from "lucide-react";
import { Button } from "@/components/ui/button";
import { apiFetch, ApiError } from "@/lib/api";
import { formatCurrency } from "@/lib/currency";
import { formatGuestDate } from "@/lib/date-format";
import { isServerConfirmedExpired } from "@/lib/order-expiry";
import { applyPromotionPercent } from "@/components/happy-hour";
import OrderCountdownBadge from "@/components/order-countdown-badge";
import { useBrand } from "@/context/brand-context";
import {
  useCart,
  type CartItem,
  type ToppingQuantities as CartToppingQuantities,
  type ToppingItemVariantSelections,
  generateCartItemId,
  calculateUnitPrice,
} from "@/context/cart-context";
import type { MenuCategory, MenuItem } from "@/data/menu";
import {
  mapOrderStatusUI,
  type OrderHistorySummary,
  type OrderHistoryTab,
} from "@/lib/order-history";

// Hai màn hình chỉ cần import từ MỘT chỗ để dựng danh sách.
export { useNowMs } from "@/hooks/use-now-ms";
export {
  deriveKitchenFromItems,
  mapOrderStatusUI,
  matchesOrderTab,
} from "@/lib/order-history";
export type {
  OrderHistoryItem,
  OrderHistorySummary,
  OrderHistoryTab,
  OrderStatusUI,
} from "@/lib/order-history";

// ─── Shared chrome ────────────────────────────────────────────────────────

const TABS: ReadonlyArray<{
  value: OrderHistoryTab;
  labelKey: "filterAll" | "filterPending" | "filterPaid";
}> = [
  { value: "all", labelKey: "filterAll" },
  { value: "pending", labelKey: "filterPending" },
  { value: "paid", labelKey: "filterPaid" },
];

/** 3 tab gạch chân "Tất cả / Chưa thanh toán / Đã thanh toán". */
export function OrderHistoryTabs({
  value,
  onChange,
}: {
  value: OrderHistoryTab;
  onChange: (tab: OrderHistoryTab) => void;
}) {
  const t = useTranslations("guestOrders");

  return (
    /* Responsive: trên mobile cho phép text xuống dòng, nhỏ hơn */
    <div className="mb-4 grid grid-cols-3 border-b border-neutral-200 text-xs md:text-sm">
      {TABS.map((tab) => (
        <button
          key={tab.value}
          onClick={() => onChange(tab.value)}
          className={`relative pb-3 pt-2 text-center font-medium transition-colors ${
            value === tab.value
              ? "text-primary"
              : "text-neutral-600 hover:text-neutral-900"
          }`}
        >
          <span className="block whitespace-normal leading-snug md:whitespace-nowrap">
            {t(tab.labelKey)}
          </span>
          {value === tab.value && (
            <span className="absolute bottom-0 left-0 right-0 h-0.5 bg-primary" />
          )}
        </button>
      ))}
    </div>
  );
}

export function OrderHistoryEmptyState() {
  const t = useTranslations("guestOrders");
  return (
    <div className="flex flex-col items-center justify-center rounded-2xl border border-neutral-200 bg-white px-6 py-12 text-center">
      <Receipt className="mb-3 size-12 text-neutral-300" strokeWidth={1.5} />
      <p className="font-semibold text-neutral-700">{t("emptyTitle")}</p>
      <p className="mt-1 text-sm text-muted-foreground">{t("emptyHint")}</p>
      <Link href="/takeaway" className="mt-4">
        <Button variant="outline">{t("emptyCta")}</Button>
      </Link>
    </div>
  );
}

/** Custom takeaway icon (theo Figma) — hộp đựng đồ với fill #27A14F. */
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

// ─── Card ─────────────────────────────────────────────────────────────────

export interface OrderHistoryCardProps {
  order: OrderHistorySummary;
  /** Trang chi tiết tương ứng — `/orders/{id}` (guest) hoặc `/account/orders/{id}`. */
  detailHref: string;
  /** Mã đơn đầy đủ; card chỉ hiển thị 4 ký tự cuối. */
  code: string;
  /** ISO timestamp hiển thị ở góc phải trên. */
  dateIso: string;
  /** Branch slug — dùng cho link đánh giá + fallback khi đặt lại. */
  shopSlug: string;
  /** Gọi khi countdown chạm 0 để trang reconcile lại đơn với server. */
  onReconcileExpiry?: (id: string) => void;
}

export default function OrderHistoryCard({
  order,
  detailHref,
  code,
  dateIso,
  shopSlug,
  onReconcileExpiry,
}: OrderHistoryCardProps) {
  const t = useTranslations("guestOrders");
  const locale = useLocale();

  const firstImage = order.items?.[0]?.image_url ?? null;
  const itemNames =
    order.items
      ?.map((it) => it.name)
      .filter((n): n is string => !!n)
      .join(", ") ?? "";

  const ui = mapOrderStatusUI(
    order.status,
    order.is_fully_paid,
    order.payment_count,
    order.items,
    order.is_reviewed,
  );

  const isTakeaway = (order.order_type ?? "takeaway") === "takeaway";

  // Đơn takeaway đã hết hạn thanh toán (server-authoritative — payment_due_at
  // đã qua). KHÔNG dựa vào đồng hồ client để tránh chặn nhầm đơn còn trả được
  // khi máy khách bị lệch giờ. Khi hết hạn: không cho "Thanh toán" nữa, thay
  // bằng "Đặt lại" (reorder). Khi countdown chạm 0, onReconcileExpiry re-fetch
  // để server xác nhận is_payment_overdue → card tự chuyển sang trạng thái này.
  const expired = isServerConfirmedExpired(order);

  return (
    <Link
      href={detailHref}
      className="relative block rounded-2xl border border-neutral-200 bg-white p-3 shadow-sm transition-colors hover:border-neutral-300"
    >
      <div className="flex gap-3">
        {/* Image thumb */}
        <div className="size-16 shrink-0 overflow-hidden rounded-xl bg-neutral-100">
          {firstImage ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={firstImage}
              alt={itemNames || code}
              className="h-full w-full object-cover"
            />
          ) : (
            <div className="flex h-full w-full items-center justify-center text-neutral-300">
              <Receipt className="size-6" strokeWidth={1.5} />
            </div>
          )}
        </div>

        {/* Right column */}
        <div className="min-w-0 flex-1">
          {/* TOP kitchen pill — render khi `kitchen` không null. Spec
              Figma: 10px / #675200 / bg #FFF0B2 (đè màu mapOrderStatusUI). */}
          {ui.kitchen && (
            <div>
              <span
                className="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold md:text-xs"
                style={{ backgroundColor: "#FFF0B2", color: "#675200" }}
              >
                {t(ui.kitchen.labelKey)}
              </span>
            </div>
          )}

          {/* Row: order code (left) + date (right). Hiển thị 4 ký tự
              cuối (`code.slice(-4)`) — vd "ORD-2026-4263" → "4263".
              Full code vẫn dùng cho QR / review / continue-pay link. */}
          <div className="flex items-start justify-between gap-2">
            {/* Mã order: 14px mobile / 18px desktop / 700 / #111827 */}
            <p
              className="text-sm tabular-nums md:text-[18px]"
              style={{ fontWeight: 700, color: "#111827" }}
            >
              #{code.slice(-4)}
            </p>
            {/* Ngày: 12px mobile / 14px desktop / 400 / #6B7280 */}
            <span
              className="shrink-0 text-xs tabular-nums md:text-sm"
              style={{ fontWeight: 400, color: "#6B7280" }}
            >
              {formatGuestDate(dateIso, locale)}
            </span>
          </div>

          {/* Tên món: 14px / 400 / #374151 */}
          <p
            className="mt-0.5 truncate"
            style={{ fontSize: "14px", fontWeight: 400, color: "#374151" }}
          >
            {itemNames || "—"}
          </p>

          {/* Row: order-type icon + label (left) + price (right) */}
          <div className="flex items-center justify-between gap-2">
            <span
              className="inline-flex items-center gap-1"
              style={{ fontSize: "12px", fontWeight: 400, color: "#6B7280" }}
            >
              {isTakeaway ? (
                <TakeawayIcon />
              ) : (
                <Utensils className="size-3.5 shrink-0" color="#27A14F" />
              )}
              {isTakeaway ? t("takeawayLabel") : t("dineInLabel")}
            </span>
            {/* Giá: 14px / 600 / #111827 */}
            <span
              className="tabular-nums"
              style={{ fontSize: "14px", fontWeight: 600, color: "#111827" }}
            >
              {formatCurrency(order.total, order.currency)}
            </span>
          </div>
        </div>
      </div>

      {/* Bottom row — payment status (trái) + action button (phải).
          Căng đều 2 bên theo yêu cầu: "Chưa thanh toán" bên trái,
          nút "Thanh toán" / "Viết đánh giá" bên phải. */}
      {ui.payment && (
        <div className="mt-3 flex items-center justify-between gap-2 border-t border-neutral-100 pt-3">
          <div className="flex flex-col gap-1.5">
            {/* Status text: 12px / 500. Color: #D80027 cho unpaid, emerald
                cho paid/completed. */}
            <span
              className="inline-flex items-center gap-1.5 text-xs md:text-sm"
              style={{
                fontWeight: 500,
                color: ui.payment.dotColor === "red" ? "#D80027" : "#15803D",
              }}
            >
              <span
                className="size-2 rounded-full"
                style={{
                  backgroundColor:
                    ui.payment.dotColor === "red" ? "#D80027" : "#10B981",
                }}
              />
              {t(ui.payment.labelKey)}
            </span>

            {/* plan-031 — Countdown chờ thanh toán cho takeaway-counter.
                Khi countdown về 0, coi như đơn đã hết hạn cho session hiện
                tại — nhưng KHÔNG xoá pointer theo đồng hồ client. onExpired
                reconcile với server: chỉ đơn thật sự biến mất (404) mới bị
                bỏ; đơn còn thanh toán được giữ nguyên. */}
            {order.payment_due_at &&
              // Chỉ hiển thị countdown khi chưa thanh toán đủ
              (order.is_fully_paid === false ||
                (typeof order.total === "number" &&
                  typeof order.paid === "number" &&
                  order.paid < order.total)) && (
                <OrderCountdownBadge
                  paymentDueAt={order.payment_due_at}
                  secondsUntilDue={order.seconds_until_due}
                  compact
                  onExpired={() => onReconcileExpiry?.(order.id)}
                />
              )}
          </div>

          {/* Đơn hết hạn + chưa thanh toán → nút "Đặt lại" thay cho
              "Thanh toán". Các action khác (review) giữ nguyên. */}
          {ui.payment.action === "continue-pay" && expired ? (
            <ExpiredOrderActions order={order} shopSlug={shopSlug} />
          ) : ui.payment.action === "reviewed" ? (
            <ReviewedBadge />
          ) : ui.payment.action ? (
            <OrderActionButton
              action={ui.payment.action}
              orderId={order.id}
              fullCode={order.code ?? code}
              shopSlug={shopSlug}
              orderType={isTakeaway ? "takeaway" : "dine_in"}
            />
          ) : null}
        </div>
      )}
    </Link>
  );
}

// ─── Actions ──────────────────────────────────────────────────────────────

function ExpiredOrderActions({
  order,
  shopSlug,
}: {
  order: OrderHistorySummary;
  shopSlug: string;
}) {
  const t = useTranslations("guestOrders");
  const router = useRouter();
  const { clearCart, addToCart, setOrderType } = useCart();
  const { switchBranch } = useBrand();
  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleReorder = async (e: React.MouseEvent<HTMLButtonElement>) => {
    e.preventDefault();
    e.stopPropagation();
    if (isSubmitting) return;
    setIsSubmitting(true);

    try {
      // 0) Lấy dữ liệu đơn MỚI NHẤT từ server thay vì tin vào prop `order`
      // (prop có thể bị stale/rỗng items sau khi đơn hết hạn → void). Dùng
      // batch endpoint (public, guest) cho đúng 1 id. Nếu vì lý do gì không
      // lấy được thì fallback về prop `order`.
      let src: OrderHistorySummary = order;
      try {
        const fresh = await apiFetch<{ data: OrderHistorySummary[] }>(
          "/api/v1/customer/orders/batch",
          {
            method: "POST",
            body: JSON.stringify({ ids: [order.id] }),
            headers: { "Content-Type": "application/json" },
            silent401: true,
          },
        );
        const freshOrder = fresh.data?.find((o) => o.id === order.id);
        if (freshOrder && (freshOrder.items?.length ?? 0) > 0) {
          src = freshOrder;
        }
      } catch {
        /* giữ nguyên `src = order` (prop) nếu refetch lỗi */
      }

      // Chi nhánh của đơn — ưu tiên slug từ chính đơn, fallback shopSlug.
      const branchSlug = src.branch?.slug || shopSlug;

      // 1) Đặt lại context: chế độ takeaway + đúng chi nhánh. KHÔNG clearCart
      // ở đây — chỉ xoá giỏ SAU khi đã build xong danh sách món (bên dưới), để
      // một order không map được món nào không làm mất giỏ hiện tại + đẩy khách
      // vào trang checkout rỗng. `switchBranch` + `setOrderType` được gọi trước
      // lần `await` đầu tiên để các effect chuyển-chi-nhánh / chuyển-mode kịp
      // settle trước khi ta thêm món (tránh race clear giỏ).
      setOrderType("takeaway");
      switchBranch(branchSlug);

      // Danh sách CartItem dựng lại từ order cũ — build trước, add sau.
      const built: CartItem[] = [];

      // 2) Lấy menu hiện tại của chi nhánh để map SKU → MenuItem.
      // 404 = chi nhánh không còn / không có menu đang mở (ngoài khung giờ,
      // menu bị gỡ) → báo "không còn trong thực đơn" thay vì lỗi chung chung.
      type BranchMenuPayload = {
        menu_id: string;
        menu_name: string;
        schedule_start_time: string | null;
        schedule_end_time: string | null;
        cart_timeout_minutes: number;
        cart_deadline_iso: string | null;
        categories: MenuCategory[];
      };
      let data: BranchMenuPayload;
      try {
        ({ data } = await apiFetch<{ data: BranchMenuPayload }>(
          `/api/v1/customer/branches/${branchSlug}/menu`,
          { silent401: true },
        ));
      } catch (menuErr) {
        if (menuErr instanceof ApiError && menuErr.status === 404) {
          console.warn("[order-history] reorder: branch/menu unavailable", {
            branchSlug,
            status: menuErr.status,
          });
          toast.error(t("reorderUnavailable"));
          return;
        }
        throw menuErr;
      }

      const categories = data.categories ?? [];
      const menuItems: MenuItem[] = categories.flatMap((cat) => cat.items);

      // Precompute deadline metadata một lần cho toàn bộ line
      let addedAtIso: string | undefined;
      let itemDeadlineIso: string | undefined;
      if (
        typeof data.cart_timeout_minutes === "number" &&
        typeof data.schedule_end_time === "string"
      ) {
        const now = new Date();
        const todayStr = now.toISOString().slice(0, 10); // YYYY-MM-DD
        const menuEndMs = new Date(
          `${todayStr}T${data.schedule_end_time}`,
        ).getTime();
        const deadlineMs = menuEndMs + data.cart_timeout_minutes * 60 * 1000;
        addedAtIso = now.toISOString();
        itemDeadlineIso = new Date(deadlineMs).toISOString();
      }

      // 3) Duyệt từng món trong order cũ → build CartItem giống logic ProductModal
      for (const line of src.items) {
        const skuId = line.product_sku_id;
        if (!skuId) continue;

        let matchedItem: MenuItem | null = null;
        const pickedVariantByOption: Record<string, string> = {};

        // Tìm MenuItem theo SKU (base sku hoặc option variant sku)
        outer: for (const item of menuItems) {
          if (item.sku_id && item.sku_id === skuId) {
            matchedItem = item;
            break outer;
          }
          if (item.options) {
            for (const opt of item.options) {
              for (const variant of opt.variants) {
                if (variant.sku_id === skuId) {
                  matchedItem = item;
                  pickedVariantByOption[opt.id] = variant.id;
                  break outer;
                }
              }
            }
          }
        }

        if (!matchedItem) {
          // Món không còn trong menu hiện tại → bỏ qua
          continue;
        }

        // Selections cho options: giữ variant khớp SKU nếu có, còn lại dùng default
        const selections: Record<string, string[]> = {};
        if (matchedItem.options) {
          for (const opt of matchedItem.options) {
            const picked = pickedVariantByOption[opt.id];
            const defaults =
              picked != null
                ? [picked]
                : opt.variants.filter((v) => v.default).map((v) => v.id);
            if (defaults.length > 0) {
              selections[opt.id] = defaults;
            }
          }
        }

        // Map toppings từ order.options sang CartItem.toppingQuantities / toppingItemVariants
        let toppingQuantities: CartToppingQuantities | undefined;
        let toppingItemVariants: ToppingItemVariantSelections | undefined;

        if (
          matchedItem.toppingGroups &&
          line.options &&
          line.options.length > 0
        ) {
          const tq: CartToppingQuantities = {};
          const tiv: ToppingItemVariantSelections = {};

          for (const opt of line.options) {
            const toppingSku = opt.product_sku_id;
            if (!toppingSku) continue;
            const qty = opt.quantity ?? 1;
            let found = false;

            for (const group of matchedItem.toppingGroups) {
              for (const topping of group.items) {
                // SKU gắn trực tiếp vào topping item
                if (topping.sku_id === toppingSku) {
                  if (!tq[group.id]) tq[group.id] = {};
                  tq[group.id][topping.id] =
                    (tq[group.id][topping.id] ?? 0) + qty;
                  found = true;
                  break;
                }
                // Hoặc SKU nằm trên variant của topping
                if (topping.variants && topping.variants.length > 0) {
                  for (const variant of topping.variants) {
                    if (variant.sku_id === toppingSku) {
                      if (!tq[group.id]) tq[group.id] = {};
                      tq[group.id][topping.id] =
                        (tq[group.id][topping.id] ?? 0) + qty;
                      tiv[topping.id] = variant.id;
                      found = true;
                      break;
                    }
                  }
                  if (found) break;
                }
              }
              if (found) break;
            }
          }

          if (Object.keys(tq).length > 0) {
            toppingQuantities = tq;
          }
          if (Object.keys(tiv).length > 0) {
            toppingItemVariants = tiv;
          }
        }

        // 4) Tính unit price theo menu hiện tại (Happy Hour + toppings)
        const baseUnitPrice = calculateUnitPrice(matchedItem, selections);

        let toppingExtra = 0;
        if (matchedItem.toppingGroups && toppingQuantities) {
          for (const group of matchedItem.toppingGroups) {
            const groupQty = toppingQuantities[group.id];
            if (!groupQty) continue;
            for (const topping of group.items) {
              const qty = groupQty[topping.id] ?? 0;
              if (qty <= 0) continue;
              const basePrice = topping.price ?? 0;
              let variantExtra = 0;
              if (topping.variants && topping.variants.length > 0) {
                const variantId = toppingItemVariants?.[topping.id];
                if (variantId) {
                  const variant = topping.variants.find(
                    (v) => v.id === variantId,
                  );
                  if (variant) {
                    variantExtra = variant.price;
                  }
                }
              }
              toppingExtra += (basePrice + variantExtra) * qty;
            }
          }
        }

        const discountedBase = applyPromotionPercent(
          baseUnitPrice,
          matchedItem.active_promotion ?? null,
        );
        const lineUnitPrice = discountedBase + toppingExtra;

        const cartItemId = generateCartItemId(
          matchedItem.id,
          selections,
          toppingQuantities,
          toppingItemVariants,
        );

        const cartPayload: CartItem = {
          id: cartItemId,
          product: matchedItem,
          selections,
          quantity: line.qty,
          unitPrice: lineUnitPrice,
          ...(toppingQuantities ? { toppingQuantities } : {}),
          ...(toppingItemVariants ? { toppingItemVariants } : {}),
        };

        // Lock deadline per-item nếu BE có cấu hình timeout
        if (addedAtIso && itemDeadlineIso) {
          cartPayload.menuId = data.menu_id;
          cartPayload.menuName = data.menu_name;
          cartPayload.menuEndTime = data.schedule_end_time ?? undefined;
          cartPayload.addedAt = addedAtIso;
          cartPayload.itemDeadline = itemDeadlineIso;
        }

        built.push(cartPayload);
      }

      // Không còn món nào của đơn cũ khớp menu hiện tại (menu đã đổi / hết món)
      // → báo cho khách thay vì đẩy vào /checkout rỗng ("bấm không có tác dụng").
      if (built.length === 0) {
        console.warn("[order-history] reorder: no items matched", {
          branchSlug,
          orderItemCount: src.items?.length ?? 0,
          orderSkus: src.items?.map((i) => i.product_sku_id) ?? [],
          menuItemCount: menuItems.length,
        });
        toast.error(t("reorderUnavailable"));
        return;
      }

      // Chỉ xoá giỏ hiện tại rồi nạp món của đơn cũ SAU khi đã chắc chắn có món.
      clearCart();
      for (const ci of built) {
        await addToCart(ci);
      }

      router.push("/checkout");
    } catch (err) {
      console.error("[order-history] reorder failed", err);
      // KHÔNG xoá pointer khi lỗi — đơn vẫn còn trong lịch sử để khách thử lại.
      // Chỉ báo lỗi để khách biết thao tác chưa thành công.
      toast.error(t("reorderFailed"));
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="flex justify-end">
      <Button
        onClick={handleReorder}
        disabled={isSubmitting}
        className="h-8 rounded-lg px-3 text-xs text-white md:text-sm"
        style={{ height: "32px", fontWeight: 500, backgroundColor: "#2D8336" }}
      >
        {t("actionReorder")}
      </Button>
    </div>
  );
}

/**
 * #1758 — đơn đã đánh giá. Cố ý KHÔNG phải nút: trang `/review/{orderId}` với
 * một đơn đã đánh giá là trang rỗng (món `already_reviewed` bị lọc ra, còn
 * branch-review thì BE trả 422), nên một nút bấm được ở đây chỉ dẫn khách vào
 * ngõ cụt. Chiều cao 32px giữ nguyên như hai nút kia để dòng dưới không nhảy.
 */
function ReviewedBadge() {
  const t = useTranslations("guestOrders");

  return (
    <span
      className="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg bg-neutral-100 px-3 text-xs md:text-sm"
      style={{ height: "32px", fontWeight: 500, color: "#6B7280" }}
    >
      <Check className="size-3.5 shrink-0" strokeWidth={2.5} />
      {t("actionReviewed")}
    </span>
  );
}

function OrderActionButton({
  action,
  orderId,
  fullCode,
  shopSlug,
  orderType,
}: {
  action: "continue-pay" | "review";
  orderId: string;
  fullCode: string;
  shopSlug: string;
  /** Quyết định trang quay về sau khi gửi đánh giá — takeaway có `/takeaway/{shop}`,
   *  dine-in thì đơn đã đóng nên trang review fallback về home. */
  orderType: "takeaway" | "dine_in";
}) {
  const t = useTranslations("guestOrders");
  const router = useRouter();

  // Card outer dùng <Link> (= <a>). Nếu button bên trong cũng là
  // <Link>/<a> → HTML invalid (a lồng a) + hydration error.
  // Fix: button dùng <Button onClick> + router.push, không phải Link.
  // stopPropagation cần thiết để click vào button không trigger luôn
  // navigation của outer card link.

  if (action === "review") {
    const reviewHref = `/review/${orderId}?code=${encodeURIComponent(fullCode)}&type=${orderType}&shop=${encodeURIComponent(shopSlug)}`;
    return (
      <Button
        variant="outline"
        onClick={(e) => {
          e.preventDefault(); // không trigger outer Link
          e.stopPropagation();
          router.push(reviewHref);
        }}
        className="h-8 rounded-lg border-neutral-300 px-4 text-xs text-neutral-700 hover:bg-neutral-50 md:text-sm"
        style={{ height: "32px", fontWeight: 500 }}
      >
        {t("actionWriteReview")}
      </Button>
    );
  }

  // "continue-pay" — Figma spec: height 32px / fontSize 12px / white /
  // bg #2D8336. Go to the shared pay page (online / pay-at-counter) — same
  // destination as the order-detail "Thanh toán" button.
  const payHref = `/orders/${orderId}/pay`;
  return (
    <Button
      onClick={(e) => {
        e.preventDefault();
        e.stopPropagation();
        router.push(payHref);
      }}
      className="h-8 rounded-lg px-4 text-xs text-white md:text-sm"
      style={{
        height: "32px",
        fontWeight: 500,
        backgroundColor: "#2D8336",
      }}
    >
      {t("actionContinuePay")}
    </Button>
  );
}
