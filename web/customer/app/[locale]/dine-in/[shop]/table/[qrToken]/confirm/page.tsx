"use client";

import { useParams } from "next/navigation";
import { useRouter } from "@/i18n/routing";
import { useTranslations } from 'next-intl';
import { useState, useEffect, useCallback, useRef } from "react";
import { AlertCircle, ArrowLeft, Minus, Plus, Loader2, X, Clock, Info, ShoppingCart } from "lucide-react";
import { useCart } from "@/context/cart-context";
import { useBrand } from "@/context/brand-context";
import { apiFetch, ApiError } from "@/lib/api";
import { toast } from "sonner";
import Header from "@/components/Header";
import ProductModal from "@/components/product-modal";
import { CartItemOptionsList, buildCartOptionLines } from "@/components/cart-item-options";
import { RemoveItemConfirmDialog } from "@/components/remove-item-confirm-dialog";
import { useCurrency } from "@/lib/currency";
import type { ActiveOrderItem } from "@/data/orders";
import type { Branch } from "@/data/brands";
import type { MenuCategory, MenuItem } from "@/data/menu";
import type { MergedMenuContext } from "@/lib/menu-item-match";
import type { CartItem } from "@/context/cart-context";
import { mapCartItemToppings } from "@/lib/cart-toppings";
import { driftUpdatesFromError } from "@/lib/price-drift";
import { useTableSessionRealtime } from "@/hooks/use-table-session-realtime";

const CART_IMAGE_FALLBACK = "/images/craft-pho-bowl.webp";

interface OrderStoreResponse {
  data: {
    id: string;
    code: string;
    status: string;
    items: ActiveOrderItem[];
    subtotal: number;
    total: number;
  };
}

function resolveCartItemImage(item: CartItem): string | null {
  // Prefer the selected variant's photo when the customer picked a
  // variant — that's the per-SKU shot. Fall back to the product's
  // first gallery image (`product.image` is already SKU-aware on the
  // server: CustomerMenuService prefers defaultSku.galleryFirst over
  // the product's hero).
  //
  // Bug 2026-06-12: this used `image_url` which is NOT a field on
  // MenuItem / ProductVariant — every lookup returned undefined and
  // every confirm-page card showed the same fallback bowl.
  if (item.product.options && item.selections) {
    for (const opt of item.product.options) {
      const selectedIds = item.selections[opt.id];
      if (selectedIds && selectedIds.length > 0) {
        for (const variant of opt.variants) {
          if (selectedIds.includes(variant.id) && variant.image) {
            return variant.image;
          }
        }
      }
    }
  }

  return item.product.image ?? null;
}

export default function ConfirmOrderPage() {
  const router = useRouter();
  const params = useParams<{ shop: string; qrToken: string }>();
  const { shop, qrToken } = params;
  const t = useTranslations('dineIn');
  const tCommon = useTranslations('common');
  const { currentBranch } = useBrand();
  // Format money in the current branch's currency (was hard-coded ¥).
  const { format: fmt } = useCurrency();

  const {
    items,
    dineInTable,
    updateQuantity,
    removeFromCart,
    // clearCartItems: chỉ xoá món, GIỮ lại dineInTable. Trước đây alias nhầm
    // `clearCart` (xoá cả bàn) → sau khi đặt, guard !dineInTable đá sang
    // "Không tìm thấy bàn" thay vì hiện success modal.
    clearCartItems,
    isItemExpired,
    applyServerPrices,
    secondsRemaining,
    isExpired,
    cartMetadata,
    reconcileCrossTimeItems,
  } = useCart();

  // Create menuBranch object for Header
  const menuBranch: Branch | undefined = dineInTable ? {
    ...currentBranch,
    id: currentBranch.id,
    name: currentBranch.name,
    slug: currentBranch.slug,
  } : undefined;

  const [submitting, setSubmitting] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  // Gate the trash action behind a confirm dialog — never delete on first tap.
  const [removeTarget, setRemoveTarget] = useState<string | null>(null);
  const [editingCartItem, setEditingCartItem] = useState<CartItem | null>(null);
  const [selectedItem, setSelectedItem] = useState<MenuItem | null>(null);
  const [showSuccessModal, setShowSuccessModal] = useState(false);
  // Countdown shown on the success modal before it auto-returns to the menu.
  const AUTO_RETURN_SECONDS = 5;
  const [autoReturnSeconds, setAutoReturnSeconds] = useState(AUTO_RETURN_SECONDS);

  // Idempotency-Key cho POST append dine-in. BE dedupe theo key → retry sau
  // network timeout / double-tap KHÔNG tạo batch trùng → không in trùng vé
  // bếp. Reset khi chữ ký items đổi: clearCartItems() sau mỗi lần đặt làm
  // items rỗng → key tự rotate cho lần "thêm món" kế = batch mới. Mirror
  // pattern ở components/checkout-page.tsx:185-195.
  const idempotencyKeyRef = useRef<string | null>(null);
  // Chữ ký giỏ — đổi khi thêm/bớt/sửa món hoặc sau clearCartItems() (→ "").
  // #1715 — GIÁ nằm trong chữ ký: giá đổi ⇒ key xoay ⇒ không tái dùng batch cũ.
  const itemsSignature = items.map((i) => `${i.id}:${i.quantity}:${i.unitPrice}:${i.note ?? ""}`).join("|");
  useEffect(() => {
    idempotencyKeyRef.current = null;
  }, [itemsSignature]);

  // plan-034 (A3) — nghe edit-lock của POS. Khi nhân viên đang sửa order trên
  // POS (`order.editing-started`), chặn khách bấm "Xác nhận" để không race đơn.
  // sessionId đọc 1 lần từ localStorage (set bởi /join ở trang bàn); null →
  // hook no-op → editingByStaff = false (không chặn nhầm).
  const [sessionId] = useState<string | null>(() => {
    if (typeof window === "undefined") return null;
    try {
      return localStorage.getItem(`dine_in_session_${qrToken}`);
    } catch {
      return null;
    }
  });
  const { editingByStaff } = useTableSessionRealtime(sessionId);

  // Close the success modal and go back to the table menu. Stable identity so
  // the auto-dismiss effect below isn't re-triggered on every render.
  const goToMenu = useCallback(() => {
    setShowSuccessModal(false);
    router.push(`/dine-in/${shop}/table/${qrToken}`);
  }, [router, shop, qrToken]);

  // Auto-dismiss the success modal after AUTO_RETURN_SECONDS and return to the
  // menu. A 1s interval drives the visible countdown; a separate timeout does
  // the actual navigation. Both are cleared if the modal closes early (manual
  // close / unmount), so there's no double navigation.
  useEffect(() => {
    if (!showSuccessModal) return;
    const tick = setInterval(() => {
      setAutoReturnSeconds((s) => Math.max(0, s - 1));
    }, 1000);
    const done = setTimeout(goToMenu, AUTO_RETURN_SECONDS * 1000);
    return () => {
      clearInterval(tick);
      clearTimeout(done);
    };
  }, [showSuccessModal, goToMenu]);

  // Cross-time reconciliation (TC-DICONF03): khi đứng ở trang xác nhận mà menu
  // chuyển ca, đối chiếu lại giỏ với menu đang active. Món còn giá y nguyên →
  // re-ref sang menu mới (vẫn đặt được); món đổi giá / biến mất → flag để
  // disable nút "Xác nhận". Best-effort: lỗi fetch không chặn flow.
  useEffect(() => {
    if (items.length === 0) return;
    let cancelled = false;
    async function reconcile() {
      try {
        const res = await apiFetch<{
          data: {
            menu_id: string;
            menu_name: string;
            schedule_end_time: string | null;
            cart_timeout_minutes: number;
            categories: MenuCategory[];
            menus?: MergedMenuContext[];
          };
        }>(`/api/v1/customer/tables/${qrToken}/menu`, { silent401: true });
        if (cancelled) return;
        reconcileCrossTimeItems({
          menuId: res.data.menu_id,
          menuName: res.data.menu_name,
          scheduleEndTime: res.data.schedule_end_time,
          cartTimeoutMinutes: res.data.cart_timeout_minutes,
          categories: res.data.categories,
          menus: res.data.menus,
        });
      } catch {
        // best-effort
      }
    }
    reconcile();
    const interval = setInterval(reconcile, 30000);
    return () => {
      cancelled = true;
      clearInterval(interval);
    };
  }, [qrToken, items.length, reconcileCrossTimeItems]);

  // Wait for cart context to hydrate before checking table
  useEffect(() => {
    // Give context time to hydrate from sessionStorage
    const timer = setTimeout(() => {
      setIsLoading(false);
    }, 100);
    return () => clearTimeout(timer);
  }, [dineInTable, items.length]);

  // Filter active and expired items
  const activeItems = items.filter((item) => !isItemExpired(item));
  const expiredItems = items.filter((item) => isItemExpired(item));
  const hasExpiredItem = expiredItems.length > 0;
  const activeTotalPrice = activeItems.reduce((sum, i) => sum + i.unitPrice * i.quantity, 0);
  // godx-tempo#1719 — đếm theo SỐ LƯỢNG, không phải số dòng, y như cart-drawer.
  // `activeTotalPrice` ngay trên đã nhân số lượng, nên đếm dòng làm hai vế cùng
  // một hàng nói hai chuyện khác nhau (một dòng số lượng 2 hiện "Tạm tính 1 món").
  const activeTotalQuantity = activeItems.reduce((sum, i) => sum + i.quantity, 0);

  // NOTE: Tax & service charge are intentionally NOT shown on this per-batch
  // dish-confirmation screen — they would repeat on every confirmation and
  // confuse the diner. They are applied once on the final checkout/payment
  // screen (payment-view), where the backend is the source of truth.

  // Grace-window countdown: chỉ hiện khi menu cũ đã hết giờ nhưng giỏ chưa
  // hết timeout (secondsRemaining ≤ cart timeout), giỏ còn món. Quá deadline
  // → isExpired = true, không hiện countdown nữa.
  const cartTimeoutSeconds =
    typeof cartMetadata?.timeout_minutes === "number"
      ? cartMetadata.timeout_minutes * 60
      : null;
  const isCartInGraceWindow =
    !!cartTimeoutSeconds &&
    secondsRemaining > 0 &&
    secondsRemaining <= cartTimeoutSeconds &&
    items.length > 0;
  const cartCountdownMmSs = (() => {
    const mm = Math.floor(secondsRemaining / 60);
    const ss = secondsRemaining % 60;
    return `${mm.toString().padStart(2, "0")}:${ss.toString().padStart(2, "0")}`;
  })();

  // Show loading while cart context hydrates
  if (isLoading) {
    return (
      <div className="flex min-h-screen flex-col bg-[#FAFAFA]">
	        <Header showBack menuBranch={menuBranch} hideAuth />
        <div className="flex flex-1 items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-primary" />
        </div>
      </div>
    );
  }

  // If no items in cart, show empty state
  // Bỏ qua empty-state khi success modal đang bật: handleSubmit gọi
  // clearCartItems() ngay sau khi đặt thành công nên items về 0 — nếu
  // không loại trừ, early-return này sẽ chặn mất success modal (render
  // ở main return bên dưới).
  if (items.length === 0 && !showSuccessModal) {
    return (
      <div className="flex min-h-screen flex-col bg-[#FAFAFA]">
	        <Header showBack menuBranch={menuBranch} hideAuth />
        <div className="flex flex-1 items-center justify-center p-6">
          <div className="text-center">
            <p className="text-muted-foreground">{t('emptyCart')}</p>
            <button
              onClick={() => router.back()}
              className="mt-4 text-primary hover:underline"
            >
              {t('backToMenuShort')}
            </button>
          </div>
        </div>
      </div>
    );
  }

  // If no table info, show error
  if (!dineInTable) {
    return (
      <div className="flex min-h-screen flex-col bg-[#FAFAFA]">
	        <Header showBack menuBranch={menuBranch} hideAuth />
        <div className="flex flex-1 items-center justify-center p-6">
          <div className="text-center">
            <AlertCircle className="mx-auto h-12 w-12 text-muted-foreground mb-4" />
            <p className="text-lg font-semibold">{t('tableNotFound')}</p>
            <p className="mt-2 text-sm text-muted-foreground">
              {t('tableNotFoundDesc')}
            </p>
            <button
              onClick={() => router.push('/')}
              className="mt-4 text-primary hover:underline"
            >
              {t('backToHome')}
            </button>
          </div>
        </div>
      </div>
    );
  }

  const table = {
    code: dineInTable.number,
    zone: dineInTable.zoneName,
    seats: dineInTable.seats,
  };

  async function handleSubmit() {
    setSubmitting(true);
    try {
      const orderItems = items.map((item) => {
        let resolvedSkuId = item.product.sku_id;
        if (item.product.options && Object.keys(item.selections).length > 0) {
          outer: for (const opt of item.product.options) {
            const selectedIds = item.selections[opt.id] ?? [];
            for (const variant of opt.variants) {
              if (selectedIds.includes(variant.id) && variant.sku_id) {
                resolvedSkuId = variant.sku_id;
                break outer;
              }
            }
          }
        }
        // Forward per-item note (special request) lên BE — BE đã accept
        // `items.*.note` (nullable string) trong CustomerOrderStoreRequest.
        // `undefined` để JSON.stringify drop khoá khi không có note.
        const toppings = mapCartItemToppings(item);
        return {
          product_sku_id: resolvedSkuId,
          quantity: item.quantity,
          // #1715 — giá dòng này đang HIỂN THỊ cho khách. Server không bao giờ
          // tính theo nó, chỉ dùng để TỪ CHỐI (409 `line_unit_price_drift`) khi
          // giá vừa giải ra cao hơn — khách không bị tạo đơn ở một giá khác cái
          // vừa nhìn thấy.
          expected_unit_price: item.unitPrice,
          note: item.note?.trim() || undefined,
          ...(toppings.length > 0 ? { toppings } : {}),
        };
      });

      if (orderItems.some((i) => !i.product_sku_id)) {
        toast.error(t('invalidItem'), { description: t('invalidItemRetry') });
        return;
      }

      // Sinh key nếu chưa có (giữ nguyên khi retry cùng batch → BE dedupe;
      // rotate sau success qua reset-effect ở trên).
      if (!idempotencyKeyRef.current) {
        idempotencyKeyRef.current = crypto.randomUUID();
      }

      await apiFetch<OrderStoreResponse>(
        `/api/v1/customer/tables/${qrToken}/orders`,
        {
          method: "POST",
          headers: { "Idempotency-Key": idempotencyKeyRef.current },
          body: JSON.stringify({
            items: orderItems,
          }),
        },
      );

      // Đánh dấu device này đã đặt món trên bàn → khi navigate về
      // /table/{qrToken}, useEffect thấy status="occupied" sẽ check cờ này
      // (occupiedByMe = true) thay vì block "Bàn đang có người dùng".
      try {
        localStorage.setItem(`dine_in_occupied_${qrToken}`, "true");
      } catch {
        // localStorage có thể bị disable trong incognito — chấp nhận
        // BE-side order là source of truth cho lần load kế.
      }

      clearCartItems();

      // Show success modal instead of toast
      setAutoReturnSeconds(AUTO_RETURN_SECONDS);
      setShowSuccessModal(true);

      // Don't navigate immediately - let user close modal first
    } catch (err) {
      // #1715 — backend từ chối vì giá vừa đổi so với cái màn này đang hiện
      // (khung giờ ưu đãi đóng giữa lúc khách ngồi ở đây — trang này poll 30s
      // nhưng vẫn có khe giữa lượt soát cuối và lúc bấm). Thân 409 mang giá thật
      // từng dòng: áp vào giỏ rồi để khách bấm lại, KHÔNG dead-end.
      const repriced = driftUpdatesFromError(err, items.map((i) => i.id));
      if (repriced) {
        applyServerPrices(repriced);
        toast.error(t("priceChangedTitle"), { description: t("priceChangedBody") });
        setSubmitting(false);
        return;
      }
      console.error("Order submission failed:", err);
      // BE trả 422 khi order của bàn đã đóng/thanh toán (ISSUES.md #2): không
      // thể thêm món vào order closed/voided. Hiện lý do thật của BE (nếu có)
      // rồi đưa khách về trang bàn để BE resolve trạng thái đúng (paid/summary)
      // thay vì kẹt ở màn xác nhận với lỗi chung chung.
      if (err instanceof ApiError && err.status === 422) {
        const beMsg =
          err.body && typeof err.body === "object" && "message" in err.body
            ? String((err.body as { message?: unknown }).message ?? "")
            : "";
        // Only the closed/voided guard means the table's order is gone —
        // every other 422 here is a payload/validation problem (e.g.
        // toppings_below_min). Labelling those "order closed" and bouncing
        // the customer back to the table page hid the real cause and lost
        // the confirm screen; keep them on the page with the BE reason.
        if (beMsg.toLowerCase().includes("closed or voided")) {
          toast.error(t('orderClosedTitle'), { description: beMsg || t('orderClosedDesc') });
          router.push(`/dine-in/${shop}/table/${qrToken}`);
          return;
        }
        toast.error(t('orderFailed'), { description: beMsg || undefined });
        return;
      }
      toast.error(t('orderFailed'));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <style dangerouslySetInnerHTML={{ __html: `
        @keyframes fadeIn {
          from { opacity: 0; transform: scale(0.95); }
          to { opacity: 1; transform: scale(1); }
        }
        @keyframes draw-circle { to { stroke-dashoffset: 0; } }
        @keyframes draw-check { to { stroke-dashoffset: 0; } }
        @keyframes scale-in {
          from { transform: scale(0); opacity: 0; }
          to { transform: scale(1); opacity: 1; }
        }
        @keyframes pulse-success {
          0%, 100% {
            box-shadow:
              0px 4px 6px -4px #5EE83066,
              0px 10px 15px -3px #5EE83066,
              0 0 0 0 rgba(41, 146, 54, 0.4);
          }
          50% {
            box-shadow:
              0px 4px 10px -4px #5EE830AA,
              0px 10px 25px -3px #5EE830AA,
              0 0 0 20px rgba(41, 146, 54, 0);
          }
        }
      ` }} />

      <div className="flex min-h-screen flex-col bg-[#FAFAFA]">
        <Header showLogo hideSwitcher hideAuth hideOrderCta hideOrderHistory hideShadow />

        {/* Sub-header "← Đặt hàng" — bám đỉnh dưới global Header (giống
            trang Thanh toán mobile). Mobile sticky `top-12 z-30` (h-12=48px
            khớp global header); desktop giữ flow bình thường (`md:static`).
            Inner container dùng `max-w-7xl` để khớp width với Header,
            back arrow + title align cùng cột với logo brand ở Header trên
            desktop. Border-b chỉ hiện ở mobile (ẩn ở desktop). */}
        <div className="sticky top-12 z-30 shrink-0 border-b border-neutral-200 bg-[#FAFAFA] py-3 md:static md:top-auto md:z-auto md:border-b-0">
          <div className="mx-auto flex max-w-7xl items-center gap-2 px-4 md:px-6">
            <button
              aria-label={t('back')}
              onClick={() => router.back()}
              className="-ml-1 flex size-8 items-center justify-center rounded-lg text-neutral-700 transition-colors hover:bg-muted"
            >
              <ArrowLeft className="size-5" />
            </button>
            <h1 className="text-lg font-bold text-neutral-900 md:text-[18px]">{t('orderHeader')}</h1>
          </div>
        </div>

      {/* plan-034 (A3) — POS đang giữ edit-lock: chặn confirm để không race staff.
          Cùng banner với menu-view; nút "Xác nhận" cũng bị disable bên dưới. */}
      {editingByStaff && (
        <div className="bg-amber-50 border-b border-amber-200 px-4 py-2 text-center text-sm font-medium text-amber-900 shrink-0">
          {t('staffEditingBanner')}
        </div>
      )}

      <div className="flex-1 overflow-y-auto pb-24">
        <div className="mx-auto max-w-2xl px-4 py-4">
          {/* Title section */}
          <div className="mb-4">
            <h1 className="text-base font-semibold text-neutral-900 md:text-xl">
              {t('confirmTitle', { code: `C-${table.code.replace(/^C-/, '')}` })}
            </h1>
            <p className="text-sm text-neutral-600 font-normal mt-1">
              {t('confirmSubtitle')}
            </p>
          </div>

          {/* Grace-window info banner: nhắc các món của menu hiện tại sẽ hết
              hiệu lực sau N phút chuyển đổi thực đơn. */}
          {isCartInGraceWindow && !isExpired && (
            <div className="mb-4 flex items-start gap-2 rounded-xl px-3 py-2.5" style={{ backgroundColor: '#FEF3C7' }}>
              <Info className="h-4 w-4 shrink-0 mt-0.5 text-amber-600" />
              <p className="text-xs leading-relaxed text-amber-900">
                {t('graceWindowNotice', { menu: activeItems[0]?.menuName || t('currentOrder'), minutes: cartMetadata?.timeout_minutes ?? 15 })}
              </p>
            </div>
          )}

          {/* Warning banner for expired items */}
          {expiredItems.length > 0 && (
            <div className="mb-4 flex items-start gap-2 rounded-lg px-3 py-2.5" style={{ backgroundColor: '#FEF3C7' }}>
              <AlertCircle className="h-4 w-4 shrink-0 mt-0.5 text-amber-700" />
              <p className="text-xs leading-relaxed text-amber-900">
                {t('expiredItemsNotice', { menu: expiredItems[0]?.menuName || "" })}
              </p>
            </div>
          )}

          {/* Active items list — each item is its own bordered card with a
              small gap between cards instead of one shared border + divider.
              Easier scanning when the order has many items, matches Figma. */}
          {activeItems.length > 0 && (
            <div className="mb-4 flex flex-col gap-3">
              {activeItems.map((item) => {
                const itemImage = resolveCartItemImage(item) ?? CART_IMAGE_FALLBACK;

                // All selected options + toppings, rendered with the shared
                // expandable list (Xem thêm / Thu gọn) so the diner can review
                // every customization they chose — same UI as the takeaway cart.
                const optionLines = buildCartOptionLines(item);

                return (
                  <div key={item.id} className="flex gap-3 rounded-xl border bg-white p-4">
                    {/* Left: image */}
                    <div className="relative flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted">
                      {/* eslint-disable-next-line @next/next/no-img-element */}
                      <img
                        src={itemImage}
                        alt={item.product.name}
                        className="h-full w-full object-cover"
                      />
                    </div>

                    {/* Right: details */}
                    <div className="min-w-0 flex-1">
                      {/* Title row */}
                      <div className="flex items-start justify-between gap-2 mb-1">
                        <h4 className="text-neutral-900" style={{ fontSize: '16px', lineHeight: '22px', fontWeight: 700 }}>
                          {item.product.name}
                        </h4>
                        <button
                          onClick={() => setRemoveTarget(item.id)}
                          className="shrink-0 p-0.5 hover:opacity-70 transition-opacity"
                          aria-label={t('removeItem')}
                        >
                          <svg width="18" height="22" viewBox="0 0 18 22" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <g clipPath="url(#clip0_1_4141)">
                              <path d="M16 7V18.6C16 19.2365 15.7471 19.847 15.2971 20.2971C14.847 20.7471 14.2365 21 13.6 21H4.4C3.76348 21 3.15303 20.7471 2.70294 20.2971C2.25286 19.847 2 19.2365 2 18.6V7M13 4V2.2C13 1.54 12.46 1 11.8 1H6.2C5.54 1 5 1.54 5 2.2V4M13 4H5M13 4H18M5 4H0M9 10V16M12 10V16M6 10V16" stroke="#ef4444" strokeWidth="1.5" strokeMiterlimit="10" strokeLinecap="round" strokeLinejoin="round"/>
                            </g>
                            <defs>
                              <clipPath id="clip0_1_4141">
                                <rect width="18" height="22" fill="white"/>
                              </clipPath>
                            </defs>
                          </svg>
                        </button>
                      </div>

                      {/* Selected options + toppings (expandable) */}
                      {optionLines.length > 0 && (
                        <div className="mb-1.5">
                          <CartItemOptionsList lines={optionLines} />
                        </div>
                      )}

                      {/* Per-item note */}
                      {item.note && (
                        <p className="mb-1.5 text-xs italic text-muted-foreground">
                          {t('notePrefix')}: {item.note}
                        </p>
                      )}

                      {/* Edit link */}
                      <button
                        onClick={() => {
                          setEditingCartItem(item);
                          setSelectedItem(item.product as MenuItem);
                        }}
                        className="text-[#002AAF] hover:text-[#002AAF]/80 mb-1.5 inline-block italic"
                        style={{ fontSize: '14px', lineHeight: '16px', fontWeight: 500 }}
                      >
                        {t('editItem')}
                      </button>

                      {/* Price & quantity row */}
                      <div className="flex items-center justify-between">
                        <p className="text-sm font-semibold text-neutral-900 md:text-[16px]">
                          {fmt(item.unitPrice * item.quantity)}
                        </p>

                        {/* Quantity controls - minimal style */}
                        <div className="flex items-center gap-3">
                          <button
                            type="button"
                            onClick={() => updateQuantity(item.id, item.quantity - 1)}
                            className="flex h-9 w-9 items-center justify-center rounded-full text-neutral-600 hover:text-neutral-900 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                            style={{ backgroundColor: '#EEEEEE' }}
                            disabled={item.quantity <= 1}
                            aria-label={t('decreaseQuantity')}
                          >
                            <Minus className="h-4 w-4" strokeWidth={2} />
                          </button>
                          <span className="min-w-[20px] text-center text-sm font-medium text-neutral-900 tabular-nums md:text-[16px]">
                            {item.quantity}
                          </span>
                          <button
                            type="button"
                            onClick={() => updateQuantity(item.id, item.quantity + 1)}
                            className="flex h-9 w-9 items-center justify-center rounded-full text-neutral-600 hover:text-neutral-900 transition-colors"
                            style={{ backgroundColor: '#EEEEEE' }}
                            aria-label={t('increaseQuantity')}
                          >
                            <Plus className="h-4 w-4" strokeWidth={2} />
                          </button>
                        </div>
                      </div>

                      {/* Per-item grace-window countdown */}
                      {isCartInGraceWindow && !isExpired && (
                        <div
                          className="mt-2 inline-flex items-center gap-1 px-2"
                          style={{ backgroundColor: '#F59E0B1A', color: '#F59E0B', borderRadius: 8, height: 24 }}
                        >
                          <Clock className="h-3.5 w-3.5 shrink-0" />
                          <span className="text-[11px] font-medium tabular-nums leading-none">
                            {t('expiresIn', { time: cartCountdownMmSs })}
                          </span>
                        </div>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          )}

          {/* Expired items section */}
          {expiredItems.length > 0 && (
            <div className="mb-4">
              <div className="mb-2">
                <h3 className="text-sm font-semibold text-muted-foreground">
                  {t('expiredSectionTitle', { menu: expiredItems[0]?.menuName || "" })}
                </h3>
              </div>
              <div className="space-y-2 opacity-60">
                {expiredItems.map((item) => {
                  const itemImage = resolveCartItemImage(item) ?? CART_IMAGE_FALLBACK;
                  return (
                    <div
                      key={item.id}
                      className="rounded-lg border border-gray-200 bg-white px-3 py-3"
                    >
                      <div className="flex gap-3">
                        <div className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted text-muted-foreground/30">
                          {/* eslint-disable-next-line @next/next/no-img-element */}
                          <img
                            src={itemImage}
                            alt={item.product.name}
                            className="h-full w-full object-cover"
                          />
                        </div>
                        <div className="min-w-0 flex-1">
                          <div className="flex items-start justify-between gap-2">
                            <p className="text-sm font-semibold leading-tight truncate">
                              {item.product.name}
                            </p>
                            <button
                              aria-label={`${t('removeItem')}: ${item.product.name}`}
                              onClick={() => setRemoveTarget(item.id)}
                              className="shrink-0 p-0.5 hover:opacity-70 transition-opacity"
                            >
                              <svg width="18" height="22" viewBox="0 0 18 22" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <g clipPath="url(#clip0_1_4141_expired)">
                                  <path d="M16 7V18.6C16 19.2365 15.7471 19.847 15.2971 20.2971C14.847 20.7471 14.2365 21 13.6 21H4.4C3.76348 21 3.15303 20.7471 2.70294 20.2971C2.25286 19.847 2 19.2365 2 18.6V7M13 4V2.2C13 1.54 12.46 1 11.8 1H6.2C5.54 1 5 1.54 5 2.2V4M13 4H5M13 4H18M5 4H0M9 10V16M12 10V16M6 10V16" stroke="#ef4444" strokeWidth="1.5" strokeMiterlimit="10" strokeLinecap="round" strokeLinejoin="round"/>
                                </g>
                                <defs>
                                  <clipPath id="clip0_1_4141_expired">
                                    <rect width="18" height="22" fill="white"/>
                                  </clipPath>
                                </defs>
                              </svg>
                            </button>
                          </div>
                          <p className="mt-1 text-xs text-muted-foreground italic">{t('editItem')}</p>
                          <p className="mt-1.5 text-sm font-semibold text-neutral-700">
                            {fmt(item.unitPrice * item.quantity)}
                          </p>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {/* Add more items button */}
          {activeItems.length > 0 && (
            <div className="mb-6 flex justify-end">
              <button
                onClick={() => router.back()}
                className="flex items-center gap-2 rounded-full text-neutral-900 hover:opacity-80 transition-opacity"
                style={{
                  fontSize: '14px',
                  fontWeight: 500,
                  height: '36px',
                  paddingLeft: '16px',
                  paddingRight: '16px',
                  backgroundColor: '#F5F5F5'
                }}
              >
                <Plus className="h-4 w-4" strokeWidth={2} />
                <span>{t('addMore')}</span>
              </button>
            </div>
          )}

          {/* Total summary */}
          <div className="border-t border-neutral-200 pt-4 space-y-2">
            <div className="flex items-center justify-between">
              <span className="text-neutral-500 text-sm md:text-[18px]" style={{ lineHeight: '20px', fontWeight: 400 }}>
                {t('subtotalCount', { count: activeTotalQuantity })}
              </span>
              <span className="tabular-nums text-sm md:text-[18px]" style={{ lineHeight: '20px', fontWeight: 400, color: '#006A34' }}>
                {fmt(activeTotalPrice)}
              </span>
            </div>
          </div>
        </div>
      </div>

      {/* Bottom action bar */}
      <div className="fixed bottom-0 left-0 right-0 z-50 border-t bg-white px-4 py-3 shadow-[0_-2px_10px_rgba(0,0,0,0.05)]">
        <div className="mx-auto max-w-2xl">
          <button
            onClick={handleSubmit}
            disabled={submitting || activeItems.length === 0 || hasExpiredItem || editingByStaff}
            className="w-full h-12 rounded-xl bg-[#2D8336] text-white text-base font-semibold flex items-center justify-center gap-2 hover:bg-[#25692C] disabled:opacity-50 disabled:cursor-not-allowed transition-all"
          >
            {submitting ? (
              <>
                <span className="size-4 rounded-full border-2 border-white border-t-transparent animate-spin" />
                {t('processing')}
              </>
            ) : editingByStaff ? (
              t('staffEditingBanner')
            ) : hasExpiredItem ? (
              t('hasExpiredCannotOrder')
            ) : (
              <>
                <ShoppingCart className="size-5" />
                {t('confirmOrderShort')}
              </>
            )}
          </button>
        </div>
      </div>

      {/* Product Edit Modal */}
      {selectedItem && (
        <ProductModal
          item={selectedItem}
          open={!!selectedItem}
          onOpenChange={(open) => {
            if (!open) {
              setSelectedItem(null);
              setEditingCartItem(null);
            }
          }}
          mode="edit"
          initialQuantity={editingCartItem?.quantity}
          initialSelections={editingCartItem?.selections}
          initialToppingQuantities={editingCartItem?.toppingQuantities}
          initialToppingItemVariants={editingCartItem?.toppingItemVariants}
          initialNote={editingCartItem?.note}
          cartItemIdToReplace={editingCartItem?.id}
        />
      )}

      {/* Success Modal - Pure HTML/CSS for maximum compatibility */}
      {showSuccessModal && (
        <div
          className="fixed inset-0 flex items-center justify-center bg-black/50"
          style={{ zIndex: 99999 }}
          onClick={goToMenu}
        >
          <div
            className="relative bg-white rounded-2xl p-6 w-[90vw] max-w-[320px] shadow-2xl"
            onClick={(e) => e.stopPropagation()}
            style={{ animation: 'fadeIn 0.2s ease-out' }}
          >
            {/* Close button */}
            <button
              aria-label={tCommon('close')}
              onClick={goToMenu}
              className="absolute right-4 top-4 flex size-6 items-center justify-center rounded-full bg-neutral-100 text-neutral-600 transition-colors hover:bg-neutral-200"
            >
              <X className="size-4" />
            </button>

            {/* Success icon — animated ring + scale-in circle + drawn checkmark
                (same animation as the dine-in payment success view). */}
            <div className="relative mx-auto mb-8 size-20">
              {/* Animated SVG ring drawing around the circle */}
              <svg
                width="80"
                height="80"
                viewBox="0 0 80 80"
                style={{ position: 'absolute', top: '50%', left: '50%', transform: 'translate(-50%, -50%) rotate(-90deg)' }}
              >
                <circle
                  cx="40"
                  cy="40"
                  r="36"
                  fill="none"
                  stroke="#299236"
                  strokeWidth="4"
                  strokeLinecap="round"
                  style={{
                    strokeDasharray: 226,
                    strokeDashoffset: 226,
                    animation: 'draw-circle 0.8s ease-out forwards',
                  }}
                />
              </svg>

              {/* Success circle background — scale-in then gentle pulse */}
              <div
                className="size-20 rounded-full flex items-center justify-center"
                style={{
                  backgroundColor: '#299236',
                  boxShadow: '0px 4px 6px -4px #5EE83066, 0px 10px 15px -3px #5EE83066, 0 0 0 0 rgba(41, 146, 54, 0.4)',
                  animation: 'scale-in 0.4s 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275) backwards, pulse-success 2s 1.5s ease-in-out infinite',
                }}
              >
                {/* Animated checkmark — strokes drawn after the circle appears */}
                <svg width="40" height="40" viewBox="0 0 40 40" fill="none" style={{ position: 'relative', zIndex: 1 }}>
                  <path
                    d="M10 20 L18 28 L30 12"
                    stroke="white"
                    strokeWidth="4"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    style={{
                      strokeDasharray: 30,
                      strokeDashoffset: 30,
                      animation: 'draw-check 0.5s 0.8s ease-out forwards',
                    }}
                  />
                </svg>
              </div>

              {/* Decoration dots - vàng (top-right), hồng (right-middle), xanh (left-middle) */}
              <div className="absolute -top-1 right-2.5 size-3 rounded-full bg-amber-400" />
              <div className="absolute top-1/2 -right-2 size-2.5 rounded-full bg-pink-400" />
              <div className="absolute top-1/2 -left-2 size-2.5 rounded-full bg-blue-400" />
            </div>

            <h1
              className="mb-2 text-center text-neutral-900"
              style={{
                fontSize: '20px',
                fontWeight: 700,
                lineHeight: '28px',
              }}
            >
              {t('orderSuccess')}
            </h1>

            {/* Vé bếp được gửi đi khi đặt món (feature print phía cửa hàng). */}
            <p className="mb-8 text-center text-sm text-neutral-500">
              {t('sentToKitchen')}
            </p>

            <button
              onClick={goToMenu}
              className="w-full max-w-[274px] mx-auto rounded-xl border border-neutral-300 text-sm font-medium transition-colors hover:bg-neutral-50 flex items-center justify-center h-[52px]"
            >
              {t('orderMore')}
            </button>

            {/* Auto-return countdown */}
            <p className="mt-3 text-center text-xs text-neutral-400">
              {t('orderSuccessAutoReturn', { seconds: autoReturnSeconds })}
            </p>
          </div>
        </div>
      )}
      </div>

      <RemoveItemConfirmDialog
        open={removeTarget !== null}
        onOpenChange={(o) => !o && setRemoveTarget(null)}
        onConfirm={() => {
          if (removeTarget) removeFromCart(removeTarget);
          setRemoveTarget(null);
        }}
      />
    </>
  );
}
