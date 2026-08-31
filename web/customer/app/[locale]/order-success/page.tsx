"use client";

import { useState, useEffect, useRef } from "react";
import { useSearchParams } from "next/navigation";
import { Suspense } from "react";
import { Link, useRouter, usePathname } from "@/i18n/routing";
import { useTranslations } from "next-intl";
import { QRCodeSVG } from "qrcode.react";
import { X } from "lucide-react";
import { PageSpinner } from "@/components/ui/page-spinner";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent } from "@/components/ui/dialog";
import OrderReviewSheet from "@/components/order-review-sheet";
import Header from "@/components/Header";
import TakeawayCountdownTimer from "@/components/takeaway-countdown-timer";
import PaymentWarningBanner from "@/components/payment-warning-banner";
import { apiFetch } from "@/lib/api";
import { FEATURES } from "@/lib/feature-flags";
import { shouldShowSplitBillCard } from "@/lib/split-bill-visibility";
import { useOrderSettlement } from "@/hooks/use-order-settlement";
import { usePayPayAvailability } from "@/hooks/use-paypay-availability";
import { shouldShowCounterPayQr } from "@/lib/counter-pay";
import SplitBillSheet, {
  type SplitMode,
} from "./components/split-bill-sheet";
import { saveGuestOrder, removeGuestOrder } from "@/lib/guest-orders";
import { isServerConfirmedExpired } from "@/lib/order-expiry";
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
import { applyPromotionPercent } from "@/components/happy-hour";
function OrderSuccessContent() {
  // Hooks must be called in consistent order
  const searchParams = useSearchParams();
  const router = useRouter();
  // next-intl's `usePathname` trả về path KHÔNG có locale prefix
  // (vd "/order-success"). Dùng cái này thay vì `window.location.pathname`
  // ("/vi/order-success") để khi login form gọi `router.push(redirect)`,
  // next-intl chỉ prepend locale 1 lần. Nếu dùng window.location.pathname
  // thì locale bị prepend 2 lần → "/vi/vi/order-success" → 404.
  const pathname = usePathname();
  const t = useTranslations("orderSuccess");
  // plan-039 — share-bill copy lives under its own namespace
  const tSplit = useTranslations("splitBill");
	  const { clearCart, addToCart, setOrderType } = useCart();
	  const { switchBranch } = useBrand();

  const orderId = searchParams.get("id");
  const orderCode = searchParams.get("code");
  const type = searchParams.get("type");
  const shop = searchParams.get("shop");

  // #2806 — does this shop still show the kiosk QR on the counter-pay screen?
  //
  // `shop` may legitimately be absent (an older link, or a flow that never put
  // it in the URL). The hook reads that as "no branch to ask about" and hands
  // back the defaults, i.e. the QR keeps rendering — the same answer this
  // screen gave before the switch existed.
  const { counter: counterPaySettings } = usePayPayAvailability(shop);
  const counterQrShown = shouldShowCounterPayQr(counterPaySettings);

  // `stripe_return=1` là GỢI Ý từ trang trước ("vừa trả xong"), không phải bằng
  // chứng. Nó chỉ được dùng trong lúc chưa fetch xong đơn, để người vừa trả tiền
  // không phải nhìn thấy màn "đang chờ thanh toán" nhấp nháy một nhịp.
  //
  // #1773 — trước đây nó là điều kiện ĐỦ: `isFullyPaidUI || isClosed`. Nghĩa là chỉ cần
  // cờ này trên URL là màn hình báo "Thanh toán thành công", server nói gì cũng
  // không bác lại được. Đo trên đơn thật ORD-2026-0019 (`status=open`,
  // `paid 0/1900` — chưa trả đồng nào): thêm `stripe_return=1` vào URL thì trang
  // báo đã thanh toán xong và mời khách mang mã ra quầy nhận món. Cờ sống trên
  // URL nên nó theo cả history, bookmark, link chia sẻ.
  const paidHint = searchParams.get("stripe_return") === "1";
  // Issue #366 — when the shop's effective policy is `prep_before_payment=false`
  // the BE creates the takeaway order with status=open (kitchen starts
  // cooking right away, no payment-due-at countdown). The success modal
  // must NOT show the "Đang chờ thanh toán" + countdown UI — the order
  // isn't expirable. Branch off `orderData.status` once it loads;
  // before that, fall back to the pending UI (safer default during
  // the brief fetch window).

  const [loginPromptOpen, setLoginPromptOpen] = useState(false);
  const [notClosedPromptOpen, setNotClosedPromptOpen] = useState(false);
  // `reviewOpen` giữ lại vì OrderReviewSheet còn mount ở cuối file —
  // mặc dù nút "Đánh giá ngay" đã được thay bằng "Xem đơn hàng" cho
  // case paid. Trigger qua login prompt sau khi đăng nhập (nếu user
  // đến từ flow review/cũ qua URL).
  const [reviewOpen, setReviewOpen] = useState(false);

  // plan-031 — fetch order data to get payment_due_at for countdown
		  const [orderData, setOrderData] = useState<any>(null);
		  const [loadingOrder, setLoadingOrder] = useState(false);
  // Issue #366 — true when the order was created with status=open (shop
  // policy `prep_before_payment=false`). Drives the modal copy + icon
  // colour + suppresses the countdown banner. Defaults to false while
  // the order is loading so the pending UI is the safer first paint
  // for the standard pay-first flow.
  const orderStatus = typeof orderData?.status === "string" ? orderData.status : null;
  // Khi BE close order (paid_amount >= total_amount → status=closed)
  // sau kiosk pay, page swap sang màn success ngay.
  const isClosed = orderStatus === "closed";

  /**
   * #1773 — sự thật về việc đã trả tiền hay chưa đến từ SERVER, không phải URL.
   *
   * `is_fully_paid` do BE tính từ `paid_amount >= total_amount`; `closed` là
   * trạng thái đơn sau khi thu đủ. Khi đã fetch được đơn thì hai thứ đó quyết
   * định, và cờ trên URL không có quyền bác lại.
   *
   * Chỉ khi CHƯA có dữ liệu đơn (đang tải, hoặc fetch hỏng) mới rơi về gợi ý
   * `paidHint` — giữ nguyên trải nghiệm cũ cho người vừa trả tiền xong, nhưng
   * không còn để một tham số URL tự nhận là đã thu tiền.
   */
  const serverSaysPaid = orderData?.is_fully_paid === true || isClosed;
  const isFullyPaidUI = orderData ? serverSaysPaid : paidHint;

  // Issue #366 — đơn `open` của shop `prep_before_payment=false`: bếp nấu ngay,
  // chưa thu tiền. Điều kiện bám `isFullyPaidUI` (đã qua guard) thay vì bám cờ
  // URL, nếu không thì chính cờ đó lật màn hình từ "Đặt hàng thành công" sang
  // "Thanh toán thành công" cho một đơn chưa trả.
  const isOpenNoWait = !isFullyPaidUI && orderStatus === "open";
  // Visual: icon xanh + style success. Cho cả 3: paid, open-no-wait, closed.
  const showPaidView = isFullyPaidUI || isOpenNoWait;
		  const [isExpired, setIsExpired] = useState(false);
		  const [isReordering, setIsReordering] = useState(false);
	  // plan-031 — in-flight guard so the client-clock expiry event reconciles
	  // with the server at most once at a time (prevents a refetch loop when a
	  // still-payable order keeps re-arming the banner near 0).
	  const reconcilingExpiryRef = useRef(false);
  // plan-039 — share-bill propagation (#377). Customer's choice persists
  // on the server via POST /api/v1/customer/orders/{id}/split-mode and the
  // kiosk reads `KioskOrderResource.split_mode` to skip its chooser. The
  // card + sheet only render for counter-pay orders (kiosk QR is shown);
  // visibility is gated on `orderData.is_counter_pay` from the BE.
  const [splitBillOpen, setSplitBillOpen] = useState(false);
  const [splitMode, setSplitMode] = useState<SplitMode | null>(null);

  // plan-031: Đảm bảo guest-order pointer luôn tồn tại cho đơn TAKEAWAY
  // (cả counter lẫn card-paid), để /orders + /orders/{id} đọc lại được
  // ngay cả khi pointer trước đó bị xoá hoặc user vào thẳng từ link.
  // SCOPE: Takeaway ONLY (per plan-031). Card-paid (isFullyPaidUI=true) vẫn
  // cần pointer vì /orders/{id} dùng localStorage làm guest auth guard.
  useEffect(() => {
    if (!orderId || !orderCode) return;
    if (type !== "takeaway") return; // plan-031: TAKEAWAY ONLY
    // Nếu URL thiếu ?shop= (có thể do navigate từ flow cũ), fallback về
    // một giá trị placeholder. Trang /orders chỉ cần shop để build link
    // "Xem thực đơn", không ảnh hưởng việc fetch order detail.
    const shopSlug = shop || "default";

    saveGuestOrder({ id: orderId, code: orderCode, shop: shopSlug });
  }, [orderId, orderCode, shop, type]);

  // plan-031: Fetch order data để lấy payment_due_at cho countdown.
  // SCOPE: Takeaway ONLY (per plan-031).
  useEffect(() => {
    // #1773 — trước đây `isFullyPaidUI` (cờ URL) short-circuit ngay ở đây, nên trên
    // đường "đã trả" trang KHÔNG BAO GIỜ hỏi server. Cờ vừa tự nhận là đã thu
    // tiền, vừa chặn luôn đường xác minh. Giờ luôn fetch: một request để màn
    // hình nói đúng sự thật là rẻ.
    if (!orderId) return;
    if (type !== "takeaway") return; // plan-031: TAKEAWAY ONLY

    const fetchOrder = async () => {
      setLoadingOrder(true);
      try {
        // `silent401` — luồng guest: 401 trần sẽ hard-navigate về `/` và trả
        // promise không bao giờ settle (lib/api.ts), tức là hất khách khỏi màn
        // xác nhận đơn rồi treo.
        const response = await apiFetch<{ data: any }>(
          `/api/v1/customer/orders/${orderId}`,
          { silent401: true },
        );
        // plan-037 — defensive redirect. If checkout-page failed to route to
        // /order-confirm (stale JS chunk, race, etc.) the user lands here
        // with an order that still needs commit. Bounce them to the
        // confirmation flow so they don't lose the cancel window.
        if (response.data?.status === "awaiting_confirmation") {
          console.warn("[Order Success] order still awaiting_confirmation — checkout failed to route; redirecting to /order-confirm");
          router.replace(`/order-confirm/${orderId}`);
          return;
        }
        setOrderData(response.data);
        // plan-039 — sync local split_mode state from server so the card
        // re-renders with the persisted choice (badge "Đã chọn: …") after
        // refetch or reload.
        if (
          response.data?.split_mode === "even" ||
          response.data?.split_mode === "by_items"
        ) {
          setSplitMode(response.data.split_mode);
        }
      } catch (error) {
        console.error("Failed to fetch order:", error);
      } finally {
        setLoadingOrder(false);
      }
    };

    fetchOrder();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [orderId]);

  // plan-050 — theo dõi tới khi kiosk thu xong tiền → refetch → status=closed
  // → showPaidView=true → swap sang màn "Thanh toán thành công" mà khách không
  // phải reload.
  //
  // Lưu ý màn này CHỈ chấp nhận `status === 'closed'`, nó không đọc
  // `is_fully_paid`. BE có thể ghi nhận đủ tiền trước rồi mới đóng đơn ở bước
  // sau, nên `enabled` còn true thêm một nhịp — đó là chủ đích: hook sẽ bắn lại
  // sau 30s cho tới khi màn hình thật sự đổi.
  useOrderSettlement(orderId, {
    // Cố tình KHÔNG gộp `!isExpired`: `isExpired` đến từ
    // `isServerConfirmedExpired`, mà hàm đó coi `is_payment_overdue` là hết
    // hạn. Đơn quá hạn VẪN trả được ở quầy (orders/[id] và orders/[id]/pay đều
    // đã bỏ gate hạn chót), nên tắt theo dõi theo nó = khách xếp hàng lâu, thu
    // ngân thu tiền, màn hình không bao giờ đổi.
    enabled: !!orderId && !isFullyPaidUI && !isClosed,
    onPaid: async () => {
      try {
        const response = await apiFetch<{ data: any }>(
          `/api/v1/customer/orders/${orderId}`,
          { silent401: true },
        );
        setOrderData(response.data);
        // plan-039 — sync local split_mode state from server so the card
        // re-renders with the persisted choice (badge "Đã chọn: …") after
        // refetch or reload.
        if (
          response.data?.split_mode === "even" ||
          response.data?.split_mode === "by_items"
        ) {
          setSplitMode(response.data.split_mode);
        }
      } catch (error) {
        console.warn("[Order Success] refetch after paid failed:", error);
      }
    },
  });

  // Nút "Đánh giá ngay" + `handleReviewClick` đã được gỡ — paid case
  // dùng "Xem đơn hàng" link tới /orders/{id}, counter case không có
  // nút review (chưa thanh toán, chưa thể đánh giá). `setReviewOpen`
  // được dùng ở Login modal "Đánh giá sau" giữ tham chiếu chỗ user
  // dismiss review flow.

  const handleLoginNow = () => {
    // `pathname` = "/order-success" (locale-less, từ next-intl). Phải dùng
    // cái này chứ không phải `window.location.pathname` ("/vi/order-success")
    // để tránh duplicate locale khi login-form gọi `router.push(redirect)`
    // sau khi đăng nhập (next-intl prepend locale → "/vi/vi/order-success"
    // → 404). `window.location.search` vẫn giữ vì query string không bị
    // locale ảnh hưởng.
    router.push(`/login?redirect=${encodeURIComponent(pathname + window.location.search)}`);
  };

  const handleReviewLater = () => {
    setLoginPromptOpen(false);
  };

  // Đích nhấn (X) + "Về trang chủ": quay lại trang menu takeaway của cùng
  // branch nếu URL có shop, fallback về home. User thường muốn đặt thêm
  // nên đưa về menu hơn là về landing /.
  const backHref = shop ? `/takeaway/${shop}` : "/";

	  // plan-031 — Reorder: xây lại giỏ hàng từ đơn đã hết hạn, rồi đưa user
	  // sang trang checkout. Logic bám theo ExpiredOrderActions bên /orders.
	  const handleReorderExpiredOrder = async () => {
	    if (!orderData) return;
	    if (!shop) {
	      console.warn("[Order Success] reorder: missing shop slug in URL");
	      return;
	    }
	    if (isReordering) return;
	    setIsReordering(true);

	    try {
	      // 1) Đặt lại context: chế độ takeaway + đúng chi nhánh
	      setOrderType("takeaway");
	      switchBranch(shop);
	      clearCart();

	      // 2) Lấy menu hiện tại của chi nhánh để map SKU → MenuItem
	      const { data } = await apiFetch<{
	        data: {
	          menu_id: string;
	          menu_name: string;
	          schedule_start_time: string | null;
	          schedule_end_time: string | null;
	          cart_timeout_minutes: number;
	          cart_deadline_iso: string | null;
	          categories: MenuCategory[];
	        };
	      }>(`/api/v1/customer/branches/${shop}/menu`, {
	        silent401: true,
	      });

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
	        const deadlineMs =
	          menuEndMs + data.cart_timeout_minutes * 60 * 1000;
	        addedAtIso = now.toISOString();
	        itemDeadlineIso = new Date(deadlineMs).toISOString();
	      }

	      // 3) Duyệt từng món trong order cũ → build CartItem giống logic ProductModal
	      const items = Array.isArray(orderData.items) ? orderData.items : [];
	      for (const line of items) {
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
	                : opt.variants
	                    .filter((v) => v.default)
	                    .map((v) => v.id);
	            if (defaults.length > 0) {
	              selections[opt.id] = defaults;
	            }
	          }
	        }

	        // Map toppings từ order.options sang CartItem.toppingQuantities / toppingItemVariants
	        let toppingQuantities: CartToppingQuantities | undefined;
	        let toppingItemVariants:
	          | ToppingItemVariantSelections
	          | undefined;

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

	        await addToCart(cartPayload);
	      }

	      router.push("/checkout");
	    } catch (err) {
	      console.error("[Order Success] reorder failed", err);
	    } finally {
	      setIsReordering(false);
	    }
	  };

	  return (
	    // Page bg theo design system flow Dine-in/Takeaway — #FAFAFA. Card kết
	    // quả + modal bên trong giữ bg-white/bg-card riêng. Header global hiển
	    // thị trên mọi breakpoint để user luôn có logo + entry point khác.
	    <div className="flex min-h-screen flex-col bg-[#FAFAFA]">
	      <Header hideSwitcher showLogo hideOrderCta hideShadow hideRegister />

      <style jsx global>{`
        @keyframes draw-circle {
          to { stroke-dashoffset: 0; }
        }
        @keyframes scale-in {
          from { transform: scale(0); }
          to { transform: scale(1); }
        }
        @keyframes draw-check {
          to { stroke-dashoffset: 0; }
        }
        @keyframes pulse-success {
          0%, 100% { box-shadow: 0px 4px 6px -4px #5EE83066, 0px 10px 15px -3px #5EE83066, 0 0 0 0 rgba(41, 146, 54, 0.4); }
          50% { box-shadow: 0px 4px 6px -4px #5EE83066, 0px 10px 15px -3px #5EE83066, 0 0 0 10px rgba(41, 146, 54, 0); }
        }
        @keyframes pulse-pending {
          0%, 100% { box-shadow: 0px 4px 6px -4px #FACC1566, 0px 10px 15px -3px #FACC1566, 0 0 0 0 rgba(250, 204, 21, 0.4); }
          50% { box-shadow: 0px 4px 6px -4px #FACC1566, 0px 10px 15px -3px #FACC1566, 0 0 0 10px rgba(250, 204, 21, 0); }
        }
      `}</style>

      {/* Main Success Content */}
      <div className="flex flex-1 flex-col items-center justify-center bg-neutral-50 px-4 py-12">
        <div className="relative w-full max-w-md rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm">
          {/* Close (X) — top-right góc card. Click navigate về trang menu
              takeaway cùng shop (hoặc home nếu không có shop param). User
              có thể đặt thêm món sau khi đóng modal này. */}
          <Link
            href={backHref}
            aria-label={t('backHome')}
            className="absolute right-4 top-4 z-10 flex size-8 items-center justify-center rounded-full text-neutral-500 transition-colors hover:bg-neutral-100"
          >
            <X className="size-5" />
          </Link>
          <div className="relative">

            {/* Success/Pending Icon — icon thay đổi theo trạng thái payment:
                - Paid: green checkmark (success)
                - Counter: yellow shopping bag + clock (pending)
                Wrap container rộng hơn để chứa các confetti dots quanh icon. */}
            <div className="relative mx-auto mb-6 flex h-24 w-32 items-center justify-center">
              {/* Confetti dots — 3 chấm tròn: Vàng (top-right), Hồng (middle-right), Xanh dương (bottom-left)
                  - Giống nhau cho cả pending và paid */}
              <div className="pointer-events-none absolute inset-0">
                {/* Vàng - top right */}
                <span className="absolute size-2.5 rounded-full" style={{ top: '8%', right: '8%', backgroundColor: '#FACC15' }} />
                {/* Hồng - middle right */}
                <span className="absolute size-2 rounded-full" style={{ top: '50%', right: '2%', backgroundColor: '#EC4899' }} />
                {/* Xanh dương - bottom left */}
                <span className="absolute size-2.5 rounded-full" style={{ bottom: '15%', left: '2%', backgroundColor: '#3B82F6' }} />
              </div>
              {/* Animated ring */}
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
                  stroke={showPaidView ? '#299236' : '#FACC15'}
                  strokeWidth="4"
                  strokeLinecap="round"
                  style={{
                    strokeDasharray: 226,
                    strokeDashoffset: 226,
                    animation: 'draw-circle 0.8s ease-out forwards',
                  }}
                />
              </svg>

              {/* Icon circle — green checkmark (paid) hoặc yellow clipboard (pending)
                  Size: 83px x 83px per Figma */}
              <div
                className="rounded-full flex items-center justify-center"
                style={{
                  width: '83px',
                  height: '83px',
                  backgroundColor: showPaidView ? '#299236' : '#FACC15',
                  boxShadow: showPaidView ? '0px 4px 6px -4px #5EE83066, 0px 10px 15px -3px #5EE83066' : '0px 4px 6px -4px #FACC1566, 0px 10px 15px -3px #FACC1566',
                  animation: showPaidView
                    ? 'scale-in 0.4s 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275) backwards, pulse-success 2s 1.5s ease-in-out infinite'
                    : 'scale-in 0.4s 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275) backwards, pulse-pending 2s 1.5s ease-in-out infinite',
                }}
              >
                {showPaidView ? (
                  // Checkmark cho paid - strokeDashoffset về 0 để dấu tick luôn hiển thị sau animation
                  <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                    <path
                      d="M10 20 L18 28 L30 12"
                      stroke="white"
                      strokeWidth="4"
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      style={{
                        strokeDasharray: 30,
                        strokeDashoffset: 0,
                        animation: 'draw-check 0.5s 0.8s ease-out forwards',
                      }}
                    />
                  </svg>
                ) : (
                  // Clipboard + clock cho pending - scaled to fit 40x40 viewBox
                  <svg width="40" height="40" viewBox="0 0 26 34" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M18.6667 33.5C16.8222 33.5 15.25 32.85 13.95 31.55C12.65 30.25 12 28.6778 12 26.8333C12 24.9889 12.65 23.4167 13.95 22.1167C15.25 20.8167 16.8222 20.1667 18.6667 20.1667C20.5111 20.1667 22.0833 20.8167 23.3833 22.1167C24.6833 23.4167 25.3333 24.9889 25.3333 26.8333C25.3333 28.6778 24.6833 30.25 23.3833 31.55C22.0833 32.85 20.5111 33.5 18.6667 33.5ZM20.9 30L21.8333 29.0667L19.3333 26.5667V22.8333H18V27.1L20.9 30ZM2.66667 31.6667C1.93333 31.6667 1.30556 31.4056 0.783333 30.8833C0.261111 30.3611 0 29.7333 0 29V5.33333C0 4.6 0.261111 3.97222 0.783333 3.45C1.30556 2.92778 1.93333 2.66667 2.66667 2.66667H8.23333C8.47778 1.88889 8.95556 1.25 9.66667 0.75C10.3778 0.25 11.1556 0 12 0C12.8889 0 13.6833 0.25 14.3833 0.75C15.0833 1.25 15.5556 1.88889 15.8 2.66667H21.3333C22.0667 2.66667 22.6944 2.92778 23.2167 3.45C23.7389 3.97222 24 4.6 24 5.33333V19.1667C23.6 18.8778 23.1778 18.6333 22.7333 18.4333C22.2889 18.2333 21.8222 18.0556 21.3333 17.9V5.33333H18.6667V9.33333H5.33333V5.33333H2.66667V29H9.73333C9.88889 29.4889 10.0667 29.9556 10.2667 30.4C10.4667 30.8444 10.7111 31.2667 11 31.6667H2.66667ZM12 5.33333C12.3778 5.33333 12.6944 5.20556 12.95 4.95C13.2056 4.69444 13.3333 4.37778 13.3333 4C13.3333 3.62222 13.2056 3.30556 12.95 3.05C12.6944 2.79444 12.3778 2.66667 12 2.66667C11.6222 2.66667 11.3056 2.79444 11.05 3.05C10.7944 3.30556 10.6667 3.62222 10.6667 4C10.6667 4.37778 10.7944 4.69444 11.05 4.95C11.3056 5.20556 11.6222 5.33333 12 5.33333Z" fill="white" />
                  </svg>
                )}
              </div>
            </div>

            {/* Title — đổi theo trạng thái payment. Counter-payment chưa
                thu tiền → "Đang chờ thanh toán"; Stripe card đã chốt →
                "Thanh toán thành công". Spec Figma: 20px / 700 / #1B1C1C. */}
            <h2
              className="mb-4 text-center"
              style={{ fontSize: '20px', fontWeight: 700, lineHeight: '28px', color: '#1B1C1C' }}
            >
              {t(isFullyPaidUI ? 'titlePaid' : isOpenNoWait ? 'titleOpen' : 'titlePending')}
            </h2>

            {/* Info/Warning box. Three states:
                  - isFullyPaidUI → "đã thanh toán, đang chuẩn bị" (yellow info)
                  - isOpenNoWait → "đơn đã được tiếp nhận, mang QR ra quầy"
                    (green success). Prep-first (Mode B) orders land here with no
                    payment deadline, so this green box IS their only message —
                    it must NOT be suppressed by the takeaway guard below.
                  - default !isFullyPaidUI && takeaway pending → suppressed here,
                    rendered as the red PaymentWarningBanner below. */}
            {showPaidView && !(!isFullyPaidUI && !isOpenNoWait && type === 'takeaway') && (
                <div
                  className="mb-4 rounded-lg border p-3 flex gap-2.5"
                  style={{
                    backgroundColor: isOpenNoWait ? '#F0FDF4' : isFullyPaidUI ? '#FEF9E6' : '#FEF2F2',
                    borderColor: isOpenNoWait ? '#BBF7D0' : isFullyPaidUI ? '#FEF3C7' : '#FEE2E2',
                  }}
                >
                  <svg
                    className="shrink-0 mt-0.5"
                    width="20"
                    height="20"
                    viewBox="0 0 20 20"
                    fill="none"
                    xmlns="http://www.w3.org/2000/svg"
                  >
                    {isOpenNoWait ? (
                      // Check icon (✓) cho open/no-wait - màu xanh
                      <>
                        <circle cx="10" cy="10" r="9" stroke="#16A34A" strokeWidth="1.5" />
                        <path d="M6 10L9 13L14 7" stroke="#16A34A" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                      </>
                    ) : isFullyPaidUI ? (
                      // Info icon (i) cho paid - màu nâu/vàng đậm
                      <>
                        <circle cx="10" cy="10" r="9" stroke="#92400E" strokeWidth="1.5" />
                        <circle cx="10" cy="6" r="0.75" fill="#92400E" />
                        <path d="M10 9V14" stroke="#92400E" strokeWidth="1.5" strokeLinecap="round" />
                      </>
                    ) : (
                      // Warning icon (!) cho pending - màu đỏ
                      <>
                        <circle cx="10" cy="10" r="9" stroke="#DC2626" strokeWidth="1.5" />
                        <path d="M10 6V11" stroke="#DC2626" strokeWidth="1.5" strokeLinecap="round" />
                        <circle cx="10" cy="14" r="0.75" fill="#DC2626" />
                      </>
                    )}
                  </svg>
                  <p
                    className="flex-1 text-left leading-relaxed"
                    style={{
                      color: isOpenNoWait ? '#14532D' : isFullyPaidUI ? '#78350F' : '#991B1B',
                      fontSize: '12px',
                      fontWeight: 400,
                      lineHeight: '18px'
                    }}
                  >
                    {t(isFullyPaidUI ? 'takeawayPaidWarning' : isOpenNoWait ? 'takeawayOpenSuccess' : 'takeawayCounterWarning')}
                  </p>
                </div>
              )}

	            {/* plan-031 — Takeaway countdown: ONLY cho đơn takeaway counter.
	                Countdown neo vào server `seconds_until_due` (skew-immune).
	                Khi về 0 KHÔNG ẩn QR / xoá pointer theo đồng hồ client — mà
	                RECONCILE với server: re-fetch rồi để status/is_payment_overdue
	                quyết định. Đồng hồ chạy nhanh không được làm mất đơn còn trả
	                được (localStorage là handle duy nhất của guest tới đơn). */}
	            {!isFullyPaidUI && orderData?.payment_due_at && type === 'takeaway' && (
	              <div className="mb-4">
	                <PaymentWarningBanner
	                  paymentDueAt={orderData.payment_due_at}
	                  secondsUntilDue={orderData.seconds_until_due}
	                  onExpired={async () => {
	                    if (!orderId || reconcilingExpiryRef.current) return;
	                    reconcilingExpiryRef.current = true;
	                    try {
	                      // Reconcile with the server before hiding the QR /
	                      // dropping the pointer. Server is the source of truth.
	                      const response = await apiFetch<{
	                        data: {
	                          status?: string | null;
	                          is_payment_overdue?: boolean | null;
	                          seconds_until_due?: number | null;
	                          payment_due_at?: string | null;
	                          [key: string]: unknown;
	                        };
	                      }>(`/api/v1/customer/orders/${orderId}`, { silent401: true });
	                      setOrderData(response.data);
	                      if (isServerConfirmedExpired(response.data)) {
	                        // Genuinely gone → now it's safe to hide + drop pointer.
	                        setIsExpired(true);
	                        try {
	                          removeGuestOrder(orderId);
	                        } catch (err) {
	                          console.error('[Order Success] failed to remove guest order on expiry', err);
	                        }
	                      }
	                      // else: server says still payable (client clock was fast)
	                      // → keep QR + pointer; fresh seconds_until_due re-arms the
	                      // banner correctly.
	                    } catch (err) {
	                      // Never hide/remove on a failed reconcile — keep the handle.
	                      console.error('[Order Success] expiry reconcile failed', err);
	                    } finally {
	                      reconcilingExpiryRef.current = false;
	                    }
	                  }}
	                />
	              </div>
	            )}

	            {/* Mã đơn hàng - ẩn khi countdown đã hết hạn (isExpired). */}
	            {orderCode && !isExpired && (
              <div className="mb-4 text-center">
                <p
                  className="mb-1"
                  style={{ fontSize: '14px', fontWeight: 500, color: '#222222', lineHeight: '20px', textTransform: 'uppercase' }}
                >
                  {t('orderCode')}
                </p>
                <p
                  className="tabular-nums"
                  style={{
                    fontWeight: 700,
                    fontSize: '24px',
                    lineHeight: '32px',
                    color: '#008C4A',
                  }}
                >
                  #{orderCode.slice(-4)}
                </p>
              </div>
            )}

	            {/* QR Code - CHỈ hiển thị cho counter-payment, chỉ khi chưa hết
	                hạn, và chỉ khi quán còn bật nó (#2806). Tắt QR thì mã đơn
	                `#xxxx` ngay TRÊN khối này là handle duy nhất còn lại — đừng
	                ẩn hay rút gọn nó. */}
	            {counterQrShown && !isFullyPaidUI && orderId && orderCode && !isExpired && (
              <div className="mb-6 flex justify-center">
                <QRCodeSVG
                  value={JSON.stringify({ orderId, orderCode, type })}
                  size={200}
                  level="M"
                />
              </div>
            )}

            {/* plan-039 — Split-bill card. Renders ONLY for counter-pay
                orders (kiosk QR is being shown) before the cashier starts
                paying. Tap → bottom sheet → POST /split-mode → kiosk skips
                its /split-options chooser when scanning. ADR-1 in plan-039
                DESIGN.md restricts to even / by_items (no custom).
                Once kiosk pays a first portion (split_mode_locked=true)
                the card switches to disabled + "Đã khoá tại quầy" copy.

                DINE-IN ONLY: share-bill has no meaning for takeaway
                counter-pay orders (no table / no per-seat split). The
                predicate excludes anything that isn't `order_type === "dine_in"`
                so takeaway orders never render the card nor persist a
                split_mode on the wrong row. See lib/split-bill-visibility.ts. */}
            {shouldShowSplitBillCard({
              order: orderData,
              orderId,
              isFullyPaidUI,
              isExpired,
            }) && (
                <div
                  data-slot="split-bill-card"
                  className="mb-6 rounded-2xl border border-neutral-200 bg-white p-4"
                >
                  <div className="flex items-start gap-3">
                    <div className="flex-1">
                      <h3 className="text-base font-semibold text-neutral-900">
                        {tSplit("cardTitle")}
                      </h3>
                      <p className="mt-1 text-xs text-neutral-600">
                        {orderData?.split_mode_locked
                          ? tSplit("lockedAtKiosk")
                          : tSplit("cardHint")}
                      </p>
                    </div>
                    <Button
                      variant={splitMode ? "outline" : "default"}
                      size="sm"
                      disabled={orderData?.split_mode_locked === true}
                      onClick={() => setSplitBillOpen(true)}
                      className="shrink-0"
                    >
                      {splitMode
                        ? tSplit(`chosen.${splitMode}`)
                        : tSplit("choose")}
                    </Button>
                  </div>
                </div>
              )}

	            {/* Action Buttons — "Về trang chủ" outline luôn hiển thị.
	                Khi đơn đã hết hạn (isExpired) thì hiện thêm nút "Đặt lại"
	                chuyển toàn bộ món trong order này sang giỏ hàng và đưa
	                user tới trang checkout. "Đánh giá ngay" chỉ dùng cho
	                case đã thanh toán bằng thẻ (isFullyPaidUI). */}
	            <div className="flex flex-col gap-3">
	              <Link href={backHref} className="block order-2 md:order-1">
                <Button
                  variant="outline"
                  className="w-full border-neutral-300"
                  style={{
                    height: '56px',
                    fontSize: '16px',
                    fontWeight: 500,
                  }}
	                >
	                  {t('backHome')}
	                </Button>
	              </Link>

	              {/* Nút "Đặt lại đơn" chỉ hiển thị khi đơn đã hết hạn và có orderData.
	                  Bấm 
\t                  → copy toàn bộ món trong đơn cũ sang giỏ hàng takeaway
\t                  → đưa user sang trang checkout. */}
	              {!isFullyPaidUI && isExpired && orderData && (
	                <Button
	                  className="w-full bg-[#16A34A] text-white order-1 md:order-2"
	                  style={{ height: '56px', fontSize: '16px', fontWeight: 500 }}
	                  onClick={handleReorderExpiredOrder}
	                  disabled={isReordering}
	                >
	                  {t('reorderTakeaway')}
	                </Button>
	              )}

              {/* Card-paid: nút "Xem trạng thái đơn hàng" link `/orders/{id}` */}
              {isFullyPaidUI && orderId && (
                <Link href={`/orders/${orderId}`} className="block">
                  <Button
                    className="w-full"
                    style={{
                      height: '56px',
                      backgroundColor: '#2D8336',
                      color: '#FFFFFF',
                      fontSize: '16px',
                      fontWeight: 500,
                    }}
                  >
                    {t('viewOrderStatus')}
                  </Button>
                </Link>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* QR Modal cũ đã được gỡ — QRCodeSVG giờ render inline trong card
          success (xem trên), không cần modal trung gian. */}

      {/* Login Prompt Modal — chỉ khi FEATURES.auth on (#47) VÀ còn mời đăng
          nhập (`authEntryPoints`).

          Lưu ý cho người đọc sau: `loginPromptOpen` hiện KHÔNG chỗ nào set
          `true` (chỉ khởi tạo `false` và đóng ở một chỗ), nên modal này đã
          không mở từ trước thay đổi này. Cổng thêm vào đây là để nếu ai đó nối
          lại chỗ mở thì nó không lách qua được lệnh tắt lời mời. */}
      <Dialog
        open={FEATURES.auth && FEATURES.authEntryPoints && loginPromptOpen}
        onOpenChange={setLoginPromptOpen}
      >
        <DialogContent className="max-w-sm p-0">
          <div className="relative p-6 pt-8">
            {/* Title */}
            <h2 className="mb-6 text-center text-lg font-semibold text-neutral-900">
              {t('loginToReview')}
            </h2>

            {/* User Icon */}
            <div className="mb-6 flex justify-center">
              <div
                className="flex items-center justify-center rounded-full"
                style={{
                  width: '80px',
                  height: '80px',
                  backgroundColor: '#E8F5E9'
                }}
              >
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#27A14F" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                  <circle cx="12" cy="7" r="4"></circle>
                </svg>
              </div>
            </div>

            {/* Description */}
            <p className="mb-6 text-center text-sm text-neutral-600 leading-relaxed px-2">
              {t('loginPromptDesc')}
            </p>

            {/* Action Buttons */}
            <div className="space-y-3">
              <Button
                onClick={handleLoginNow}
                className="w-full h-12 text-base font-semibold"
                style={{ backgroundColor: '#27A14F' }}
              >
                {t('loginNow')}
              </Button>

              <Button
                onClick={handleReviewLater}
                variant="outline"
                className="w-full h-12 text-base font-semibold"
              >
                {t('later')}
              </Button>
            </div>

            {/* Footer hint — message chứa <strong>...</strong>; next-intl
                FORMATTING_ERROR nếu dùng `t()` thường, phải dùng `t.rich()`
                và truyền tag handler cho `strong`. Bỏ luôn dangerouslySetInnerHTML
                vì giờ render trực tiếp JSX. */}
            <p className="mt-4 text-center text-xs text-muted-foreground">
              {t.rich('canReviewLaterHint', {
                strong: (chunks) => <strong className="font-semibold">{chunks}</strong>,
              })}
            </p>
          </div>
        </DialogContent>
      </Dialog>

      {/* Order Not Closed Prompt Modal */}
      <Dialog open={notClosedPromptOpen} onOpenChange={setNotClosedPromptOpen}>
        <DialogContent className="max-w-sm p-0">
          <div className="relative p-6 pt-8">
            {/* Warning Icon */}
            <div className="mb-6 flex justify-center">
              <div
                className="flex items-center justify-center rounded-full"
                style={{
                  width: '80px',
                  height: '80px',
                  backgroundColor: '#FEF3C7'
                }}
              >
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#F59E0B" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                  <line x1="12" y1="9" x2="12" y2="13"></line>
                  <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
              </div>
            </div>

            {/* Title */}
            <h2 className="mb-6 text-center text-lg font-semibold text-neutral-900">
              {t('orderNotCompleted')}
            </h2>

            {/* Description */}
            <p className="mb-6 text-center text-sm text-neutral-600 leading-relaxed px-2">
              {t('orderNotCompletedDesc')}
            </p>

            {/* Action Button */}
            <div className="space-y-3">
              <Button
                onClick={() => setNotClosedPromptOpen(false)}
                className="w-full h-12 text-base font-semibold"
                style={{ backgroundColor: '#27A14F' }}
              >
                {t('understood')}
              </Button>
            </div>

            {/* Footer hint */}
            <p className="mt-4 text-center text-xs text-muted-foreground">
              {t('canReviewAfterComplete')}
            </p>
          </div>
        </DialogContent>
      </Dialog>

      {/* Review Sheet */}
      {orderId && (
        <OrderReviewSheet
          orderId={orderId}
          open={reviewOpen}
          onOpenChange={setReviewOpen}
        />
      )}

      {/* plan-039 — share-bill mode selector (counter-pay only). */}
      {orderId && (
        <SplitBillSheet
          orderId={orderId}
          initialMode={splitMode}
          locked={orderData?.split_mode_locked === true}
          open={splitBillOpen}
          onOpenChange={setSplitBillOpen}
          onSaved={(mode) => setSplitMode(mode)}
        />
      )}

    </div>
  );
}

export default function OrderSuccessPage() {
  return (
    <Suspense fallback={<PageSpinner message="Đang xử lý đơn hàng..." />}>
      <OrderSuccessContent />
    </Suspense>
  );
}
