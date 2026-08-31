"use client";

import { useRef, useState, useEffect } from "react";
import { useParams } from "next/navigation";
import { useTranslations, useLocale } from 'next-intl';
import { ArrowLeft, MapPin, Minus, Phone, Plus, Store, CreditCard, Smartphone, SplitSquareHorizontal, Loader2 } from "lucide-react";
import { QRCodeSVG } from "qrcode.react";
import { toast } from "sonner";
import type { ActiveOrder } from "@/data/orders";
import { useBrand } from "@/context/brand-context";
import { useCurrency, getRoundingStep, roundUpToStep, type SplitBillRoundingMode } from "@/lib/currency";
import { apiFetch, ApiError } from "@/lib/api";
import { cn, shortOrderCode } from "@/lib/utils";
import { useOrderSettlement } from "@/hooks/use-order-settlement";
import { StripeCardSection, type StripeCardSectionHandle } from "@/components/stripe-card-section";
import { useStripeConfig } from "@/lib/stripe-config";
import { shouldOfferCounterPay, shouldShowCounterPayQr } from "@/lib/counter-pay";
import { PayPayQrPanel } from "@/components/paypay-qr-panel";
import { usePayPayAvailability } from "@/hooks/use-paypay-availability";
import { usePayPayOrphanWatch } from "@/hooks/use-paypay-orphan-watch";
import {
  dineInOnlineSurface,
  payPaySplitPayload,
  type DineInOnlineGateway,
} from "@/lib/paypay-qr";
import { paymentPolicyEcho, primePaymentPolicyContext } from "@/lib/payment-policy-context";
import { useAsyncPaymentMethods } from "@/hooks/use-async-payment-methods";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import {
  canSelectPaymentOption,
  isPaymentChoiceLocked,
  paymentOptionFrom,
  paymentOptionLabelId,
  paymentStateFor,
  payPayRowShown,
  type PaymentOption,
} from "@/lib/payment-method-choice";
import { PayPayBrandIcon } from "@/components/payment-brand-icons";
import { TaxBreakdownLines } from "@/components/tax-breakdown-lines";
import { computeSelectionTotal, roundStep } from "@/lib/tax";
import type { TableInfo } from "../page";
import { formatGuestDate, formatGuestTime } from '@/lib/date-format'

/**
 * WHERE the money is taken. PayPay is deliberately not a member (#1303): it is
 * a way of paying online, not a third channel beside "pay at the counter", and
 * flattening it here made `method === "online"` mean two things at once — "the
 * guest chose to pay online" and "the guest chose the card form".
 */
type PaymentMethod = "online" | "counter";

/** WHICH online gateway, once `method` is `online`. The second level (#1303). */
type OnlineGateway = DineInOnlineGateway;
type PaymentMode = "full" | "split";
type SplitType = "even" | "by_items" | "by_amount";


interface PaymentViewProps {
  table: TableInfo;
  order: ActiveOrder;
  onBack: () => void;
  onConfirmed: (order: ActiveOrder) => void;
  onPartialPaid?: (order: ActiveOrder) => void;
}

export default function PaymentView({ table, order, onBack, onConfirmed, onPartialPaid }: PaymentViewProps) {
  const t = useTranslations('dineInPayment');
  const locale = useLocale();
  const tCheckout = useTranslations('checkout');
  // godx-tempo#1737 — the abandoned-code notice lives under `paypay` so both
  // surfaces say the same true thing about the same situation.
  const tPayPay = useTranslations('paypay');
  // godx-tempo#1719 — icon-only controls on this screen had no accessible name
  // at all (back arrow + every ±). The menu screen was swept clean; this one is
  // where the guest decides how much money to hand over, so it matters more.
  const tCommon = useTranslations('common');
  // plan-048 T2.5 — the [shop] route param IS the branch slug; prime the
  // policy identity on mount and echo it on the intent bodies below.
  const { shop: branchSlug } = useParams<{ shop: string }>();
  useEffect(() => {
    primePaymentPolicyContext(branchSlug);
  }, [branchSlug]);
  const { format: fmt } = useCurrency();
  const remaining = order.remaining ?? Math.max(0, order.total - (order.paid ?? 0));

  // Đơn 0đ (món được comp / coupon -100%): không còn gì để thu → Stripe từ
  // chối PaymentIntent 0đ. Ta short-circuit toàn bộ UI thanh toán và cho khách
  // bấm "Hoàn tất" để đóng đơn qua endpoint settle-zero (không charge thẻ).
  // Trang cha chỉ route vào PaymentView khi đơn CHƯA đóng, nên remaining<=0 ở
  // đây luôn nghĩa là đơn 0đ chưa thanh toán (đơn đã trả xong đi thẳng PaidView).
  const isFreeOrder = remaining <= 0;

  // #2545 — lựa chọn THÔ của khách; `method` dẫn xuất bên dưới (sau khi biết
  // chi nhánh có cổng online nào không) mới là cái cả màn này đọc.
  const [methodChoice, setMethod] = useState<PaymentMethod>("online");
  // #3127 — `full` là tab khách LUÔN tiếp đất, và nay đúng là như vậy: chỉ một
  // cú bấm của khách mới đổi được nó. `fetchSplitStatus()` bên dưới từng ghi đè
  // giá trị này vài trăm ms sau khi mount ở bốn nhánh, nên mặc định đúng ở đây
  // vẫn ra màn hình sai. Restore dữ liệu chia bill thì được, chọn tab hộ khách
  // thì không.
  const [paymentMode, setPaymentMode] = useState<PaymentMode>("full");
  const [splitType, setSplitType] = useState<SplitType>("even");
  const [splitCount, setSplitCount] = useState("");
  const [splitPaidCount, setSplitPaidCount] = useState(0);
  const [customAmount, setCustomAmount] = useState(String(remaining));
  const [selectedItems, setSelectedItems] = useState<Map<string, number>>(new Map());

  // Keep selectedItems in sync with what's still actually payable. When a
  // counter-pay (Kiosk) payment lands, the realtime hook refetches the order
  // and each item's `paid_quantity` jumps. An item the customer had selected
  // (and whose amount the counter-pay QR encodes) may now be fully — or
  // partially — paid. Without pruning, `splitByItemsRawAmount` keeps counting
  // that already-settled item, so "Tổng cộng" + the QR still show its amount
  // and selecting a second dish ADDS on top of it (the bug in the screenshot).
  // Drop fully-paid items, clamp partial ones to the remaining unpaid qty.
  const paidSignature = order.items
    .map((it) => `${it.id}:${it.paid_quantity ?? 0}:${it.qty}`)
    .join("|");
  useEffect(() => {
    setSelectedItems((prev) => {
      if (prev.size === 0) return prev;
      let changed = false;
      const next = new Map<string, number>();
      for (const [id, qty] of prev) {
        const item = order.items.find((it) => it.id === id);
        const remainingQty = item
          ? Math.max(0, item.qty - (item.paid_quantity ?? 0))
          : 0;
        const clamped = Math.min(qty, remainingQty);
        if (clamped > 0) next.set(id, clamped);
        if (clamped !== qty) changed = true;
      }
      return changed ? next : prev;
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [paidSignature]);

  const [splitStatusLoading, setSplitStatusLoading] = useState(false);
  const [splitLocked, setSplitLocked] = useState(false); // true nếu đã có người thanh toán split trước
  // #406 — true CHỈ khi hard lock được nạp từ BE lúc mount (refresh/reopen),
  // KHÔNG set khi payment vừa commit in-session. Dùng để phân biệt "người 1
  // reload sau khi đã trả" (indicator = paid_count) với "device dùng chung,
  // người 1 vừa trả xong đưa máy cho người 2 tiếp tục" (indicator =
  // paid_count + 1). Xem `splitGuestCurrent` bên dưới.
  const [restoredHardLock, setRestoredHardLock] = useState(false);
  // #407 — Mutex giữa "Chia đều" (even) và "Chia theo món" (by_items).
  // `confirmedSplitMode` đến từ payment metadata (hard lock, từ #406's
  // /split-status response.split_count + split_mode). `tentativeSplitMode`
  // đến từ customer_orders.split_mode (soft lock — first payer đã chốt
  // mode nhưng chưa pay). "Tùy chọn" (custom) không bao giờ stamp split_mode
  // nên không lock gì.
  const [confirmedSplitMode, setConfirmedSplitMode] = useState<"even" | "by_items" | null>(null);
  const [tentativeSplitMode, setTentativeSplitMode] = useState<"even" | "by_items" | null>(null);
  const [lockedAmountPerPerson, setLockedAmountPerPerson] = useState<number | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [payError, setPayError] = useState<string | null>(null);

  // #1296 — the PayPay QR the guest is currently looking at, with the share it
  // was minted for FROZEN at the moment they pressed pay.
  //
  // Frozen deliberately. A live code promises to collect one specific sum, so it
  // must not silently follow a headcount spinner or a dish checkbox the guest
  // nudges afterwards — the code at PayPay would still be for the old amount.
  // Holding the mint inputs here (rather than reading the live state) also keeps
  // the panel's mint effect from re-running on a parent render, which would void
  // the code being scanned and restart the countdown.
  //
  // `null` = no code yet; the guest is still composing their share.
  const [paypayMint, setPaypayMint] = useState<{
    amount: number;
    split: Record<string, unknown>;
  } | null>(null);

  // godx-tempo#1737 — a code the guest walked away from is still scannable at
  // PayPay for the rest of its ~5 minutes, and the panel's own poll died with
  // it. Keep asking the status endpoint (which BOOKS the money) so a scan on
  // the abandoned code settles on the next tick instead of waiting for the
  // fifteen-minute sweeper.
  const [paypayOrphanedAtMs, setPaypayOrphanedAtMs] = useState<number | null>(null);

  // plan-054 / #1296 — is this branch wired for PayPay dynamic QR? Unlike
  // checkout, where `qr_pay` keeps its "staff settle it at the till" meaning and
  // PayPay merely upgrades it, dine-in has no fallback meaning to fall back to:
  // an option that cannot mint a code is a dead button at an occupied table. So
  // the option is hidden unless the server said yes. The hook reads "could not
  // determine" as "not yet" and keeps asking, never as "no".
  const {
    paypayEnabled: paypayQrEnabled,
    loading: paypayProbeLoading,
    counter: counterPaySettings,
  } = usePayPayAvailability(branchSlug);

  // #1125 option B — same primed context, but this one is not cosmetic: it
  // decides the Elements payment-method configuration, which must match how
  // the backend created the PaymentIntent or Stripe refuses the confirm.
  const asyncMethodsEnabled = useAsyncPaymentMethods(branchSlug);

  /**
   * #1303 — which online gateway the guest picked, or `null` for "has not
   * picked".
   *
   * `null` rather than a concrete default, because the default depends on an
   * ANSWER THAT ARRIVES LATE: `usePayPayAvailability` resolves a few hundred ms
   * after mount. Writing the default from an effect once it resolves would mount
   * `StripeCardSection` and then tear it down — and on a slow connection, where
   * the probe lands after the guest has started typing, it would tear down a card
   * form with their number already in it.
   *
   * So nothing is written on arrival. The effective value is derived at render
   * (`activeGateway`), and a tap pins it for good.
   */
  const [onlineGateway, setOnlineGateway] = useState<OnlineGateway | null>(null);

  // #1703 — see checkout-page.tsx: without `loading` this flashes a red
  // "card payment unavailable" while the config request is still in flight.
  // Sống ở đây (chứ không cạnh `stripeCardRef` bên dưới) vì `counterPayOffered`
  // ngay dưới cần nó, mà `method` lại phải có trước `onlineSurface`.
  const { config: stripeConfig, loading: stripeConfigLoading } = useStripeConfig();
  const stripePublishableKey = stripeConfig?.publishable_key ?? "";

  // #2806 — "thanh toán tại quầy" là CỜ CỦA CHI NHÁNH, không còn suy ra từ
  // trạng thái cổng (#2545). Xem `lib/counter-pay.ts`.
  const counterPayOffered = shouldOfferCounterPay(counterPaySettings);

  // Nút "tại quầy" biến mất khi cổng online hiện ra; kênh đang chọn phải đi
  // theo, nếu không màn hình kẹt ở nhánh counter mà không còn nút nào để thoát.
  // Dẫn xuất chứ không phải effect ghi lại state: cấu hình cổng về sau khi
  // mount, và `react-hooks/set-state-in-effect` chặn cách kia.
  const method: PaymentMethod = counterPayOffered ? methodChoice : "online";

  // Declared here rather than beside the render gates because `handleConfirm`
  // reads it too, and a second copy of this reasoning is how the encryption
  // notice ended up beside a PayPay QR.
  const onlineSurface = dineInOnlineSurface({
    payingOnline: method === "online",
    picked: onlineGateway,
    paypayEnabled: paypayQrEnabled,
    paypayProbeLoading: paypayProbeLoading,
    hasLiveCode: paypayMint !== null,
  });
  const activeGateway = onlineSurface.gateway;

  // plan-050 — theo dõi đơn trong lúc khách cầm QR ra quầy:
  //   - trả đủ → refetch rồi transition sang paid-view.
  //   - partial (split-bill: người kế vừa trả phần mình) → refetch rồi
  //     propagate qua onPartialPaid để panel by-items disable món vừa trả.
  //
  // Đặt SAU khối useState vì `paused` đọc `confirming` — để trên sẽ là dùng
  // biến trước khai báo và `tsc --noEmit` (cổng CI cứng) fail.
  //
  // `paused: confirming` là bắt buộc, không phải tối ưu: `handleConfirm` đã có
  // vòng poll 500ms của riêng nó sau khi Stripe confirm. Hai vòng cùng ghi
  // `setOrder` sẽ đá nhau — request đi trước về sau sẽ ghi đè snapshot
  // trước-thanh-toán lên cái sau-thanh-toán, `paid_quantity` tụt về, panel
  // by-items mở lại món đã trả, và QR mã hoá số tiền tính trùng.
  useOrderSettlement(order.id, {
    enabled: remaining > 0,
    paused: confirming,
    onPaid: async () => {
      try {
        const res = await apiFetch<{ data: ActiveOrder }>(
          `/api/v1/customer/orders/${order.id}`,
          { silent401: true },
        );
        onConfirmed(res.data);
      } catch (err) {
        console.warn("[dine-in PaymentView] refetch after paid failed:", err);
      }
    },
    onPaymentRecorded: async () => {
      try {
        const res = await apiFetch<{ data: ActiveOrder }>(
          `/api/v1/customer/orders/${order.id}`,
          { silent401: true },
        );
        const updated = res.data;
        // Đơn có thể đã đóng hẳn giữa hai request. Nếu cứ đẩy qua
        // `onPartialPaid` thì parent chỉ `setOrder` mà không đổi view:
        // `remaining` về 0 → `enabled` tắt (poll chết) và `isFreeOrder` bật →
        // khách vừa trả đủ lại thấy màn ¥0 "Hoàn tất", không có đường thoát.
        if (updated.is_fully_paid || (updated.remaining ?? 0) <= 0) {
          onConfirmed(updated);
          return;
        }
        onPartialPaid?.(updated);
      } catch (err) {
        console.warn("[dine-in PaymentView] refetch after partial pay failed:", err);
      }
    },
  });

  // #1296 — the PayPay panel's own code poll can see the money land before the
  // settlement watcher above does (it asks PayPay directly, the watcher asks us).
  // Same handling either way, because a dine-in bill may be only partly settled:
  // full → advance to the paid view, partial → propagate so the by-items panel
  // disables the dishes this payer just covered.
  // godx-tempo#1737 — watches whatever code the panel handed over on its way
  // out. Settling goes through the SAME path as a code paid on screen, so a
  // partial share still disables the dishes it covered.
  usePayPayOrphanWatch({
    orderId: order.id,
    orphanedAtMs: paypayOrphanedAtMs,
    onResolved: () => setPaypayOrphanedAtMs(null),
    onPaid: () => {
      setPaypayOrphanedAtMs(null);
      void syncOrderAfterPayPay();
    },
  });

  async function syncOrderAfterPayPay(): Promise<void> {
    try {
      const res = await apiFetch<{ data: ActiveOrder }>(
        `/api/v1/customer/orders/${order.id}`,
        { silent401: true },
      );
      const updated = res.data;
      if (updated.is_fully_paid || (updated.remaining ?? 0) <= 0) {
        onConfirmed(updated);
        return;
      }
      // The code that was just paid is spent — clear it so the next share starts
      // from a fresh mint rather than a QR PayPay has already collected on.
      setPaypayMint(null);
      onPartialPaid?.(updated);
    } catch (err) {
      console.warn("[dine-in PaymentView] refetch after PayPay paid failed:", err);
    }
  }

  // #555 M10 — per-attempt idempotency key for split-payment-intent. Reused
  // verbatim on a retry after an error/poll-timeout (same amount + mode), so
  // the backend hands Stripe the SAME key and we get the SAME PaymentIntent
  // back instead of minting a second real charge. Regenerated when the
  // attempt's parameters change or a payment definitively succeeds.
  const splitAttemptRef = useRef<{ key: string; amount: number } | null>(null);
  const splitAttemptKey = (amount: number): string => {
    if (!splitAttemptRef.current || splitAttemptRef.current.amount !== amount) {
      splitAttemptRef.current = { key: crypto.randomUUID(), amount };
    }
    return splitAttemptRef.current.key;
  };

  // Coupon state
  const [couponCode, setCouponCode] = useState("");
  const [couponDebounced, setCouponDebounced] = useState("");
  const [couponPreview, setCouponPreview] = useState<{
    data: {
      is_valid: boolean;
      discount_applied_amount?: number;
      error_code?: string;
      meta?: Record<string, unknown>;
    };
  } | null>(null);
  const [couponPending, setCouponPending] = useState(false);

  const stripeCardRef = useRef<StripeCardSectionHandle>(null);

  const { currentBranch, branches } = useBrand();
  const branch =
    branches.find((b) => b.slug === currentBranch.slug) ?? currentBranch;

  const hasPriorPayments = (order.paid ?? 0) > 0;

  // Fetch split status khi mount để check xem đã có người thanh toán split chưa.
  // Đồng thời restore lựa chọn "chia đều X người" từ localStorage cho user
  // FIRST PAYER lúc F5 mid-flow (trước khi confirm Stripe) — tránh phải chọn lại.
  // Key: dine_in_split_choice_{orderId}. Clear khi BE đã lock (đã có người trả)
  // hoặc khi đơn full-paid.
  useEffect(() => {
    const STORAGE_KEY = `dine_in_split_choice_${order.id}`;

    async function fetchSplitStatus() {
      if (remaining <= 0) {
        // Đơn full-paid → clear localStorage (no longer needed)
        if (typeof window !== "undefined") {
          window.localStorage.removeItem(STORAGE_KEY);
        }
        return;
      }
      setSplitStatusLoading(true);
      try {
        const res = await apiFetch<{
          data: {
            split_count: number | null;
            amount_per_person: number | null;
            tentative_split_count: number | null;
            paid_count: number;
            is_first_payment: boolean;
            split_mode: string | null;
          };
        }>(`/api/v1/customer/orders/${order.id}/split-status`);
        const {
          split_count,
          amount_per_person,
          tentative_split_count,
          paid_count,
          is_first_payment,
          split_mode,
        } = res.data;

        // #407 — track committed/tentative split_mode for tab mutex.
        // Confirmed: payment exists with split metadata → hard lock both
        // /split-mode endpoint (BE: paid_amount > 0 → 409) AND the OTHER tab
        // in this UI. Tentative: BE has customer_orders.split_mode but no
        // payment yet → soft lock the OTHER tab for OTHER guests (this
        // device may still swap because it's the owner; see ownership
        // check below).
        if (split_mode === "even" || split_mode === "by_items") {
          if (split_count !== null && split_count > 0) {
            setConfirmedSplitMode(split_mode);
          } else {
            setTentativeSplitMode(split_mode);
          }
        }

        // CASE 1: BE đã hard lock (đã có người trả split-people thành công).
        // Payment metadata stamp split_count + amount_per_person → mọi tab
        // refresh đều thấy cùng giá trị, không tự tính lại theo remaining
        // (cái này sai vì remaining đã giảm sau mỗi lần trả).
        if (!is_first_payment && split_count && paid_count > 0) {
          setSplitLocked(true);
          setRestoredHardLock(true);
          setSplitCount(String(split_count));
          setSplitPaidCount(paid_count);
          if (typeof amount_per_person === "number" && amount_per_person > 0) {
            setLockedAmountPerPerson(amount_per_person);
          }
          // #3127 — KHÔNG `setPaymentMode("split")`. Khách luôn tiếp đất ở tab
          // hoá đơn đầy đủ; `splitType` vẫn restore để cú bấm sang tab chia
          // trúng ngay chế độ đã chốt chứ không phải chế độ đang bị khoá.
          setSplitType((split_mode === "by_items" ? "by_items" : "even") as SplitType);
          // BE đã có authoritative data → clear localStorage cũ (nếu có).
          // QUAN TRỌNG (#407 clarification): KHÔNG xoá owner flag — sau khi
          // hard lock, tab disable không phụ thuộc owner nữa (mọi guest đều
          // bị disable cái tab kia, kể cả người đã pay). Owner flag giữ lại
          // CHỈ để banner phân biệt copy "Bạn đã thanh toán Chia đều — không
          // đổi được" (cho người paid) vs "Khách khác đã chọn Chia đều"
          // (cho guest 2, 3, ...).
          if (typeof window !== "undefined") {
            window.localStorage.removeItem(STORAGE_KEY);
          }
          return;
        }

        // CASE 2: BE có soft lock (#406) — first payer chốt mode qua
        // POST /split-mode (customer_orders.split_mode + split_people_count)
        // nhưng chưa pay xong. Tab khác mở cùng order → preview lock UI
        // theo mode đã chốt, nhưng KHÔNG hard-lock (split_count vẫn null
        // → first payer còn quyền đổi ý cho tới khi commit payment).
        //
        // Side-effect: first payer F5 cũng land vào case này → restore từ BE
        // thay vì localStorage. BE wins vì authoritative across devices.
        if (split_mode === "even" && tentative_split_count !== null && tentative_split_count >= 2) {
          setSplitCount(String(tentative_split_count));
          setSplitType("even");
          if (typeof window !== "undefined") {
            window.localStorage.removeItem(STORAGE_KEY);
          }
          return;
        }
        // #407 — by_items branch: restore CHẾ ĐỘ CHIA khi BE đã stamp
        // split_mode=by_items (nhưng chưa pay xong), để chế độ kia bị khoá.
        // #3127 — chỉ restore chế độ, KHÔNG mở tab chia hộ khách.
        if (split_mode === "by_items") {
          setSplitType("by_items");
          if (typeof window !== "undefined") {
            window.localStorage.removeItem(STORAGE_KEY);
          }
          return;
        }

        // CASE 3: BE chưa có gì → restore lựa chọn local nếu user
        // đã chọn rồi F5 (first payer mid-flow, before /split-mode POST).
        if (typeof window !== "undefined") {
          const stored = window.localStorage.getItem(STORAGE_KEY);
          if (stored) {
            const parsed = parseInt(stored, 10);
            if (Number.isFinite(parsed) && parsed >= 2) {
              setSplitCount(String(parsed));
              setSplitType("even");
            }
          }
        }
      } catch (err) {
        console.warn("Failed to fetch split status:", err);
      } finally {
        setSplitStatusLoading(false);
      }
    }
    void fetchSplitStatus();
  }, [order.id, remaining]);

  // plan-039 — Counter-pay flow chỉ hiển thị QR rồi customer mang ra
  // kiosk; KHÔNG đi qua `handleConfirm()` (handler đó là cho Stripe
  // online-pay). Hệ quả: trước fix này, customer chọn "Chia đều" +
  // "Thanh toán tại quầy" nhưng BE không hề nhận POST /split-mode, nên
  // `customer_orders.split_mode` vẫn null khi kiosk scan QR → kiosk
  // fallback về full-price view thay vì route /split/people.
  //
  // Sync split-mode lên BE ngay khi user chốt valid splitType, dùng ref
  // để khỏi spam request mỗi lần state thay đổi. Khi user đổi sang
  // paymentMode=full, để nguyên field BE vì setSplitMode endpoint không
  // nhận null — kiosk sẽ tự kiểm tra stripe_intent_id để phân biệt.
  //
  // Áp dụng cho CẢ counter-pay VÀ online-pay (#402 follow-up): chừng nào
  // user còn chưa pay xong, mỗi tab khác nhìn vào cùng order phải thấy
  // split_mode + split_count thống nhất. handleConfirm cũng fire thêm 1
  // POST nữa nhưng đó chỉ là backup phòng race khi user click confirm
  // trước khi useEffect kịp chạy.
  const lastSyncedSplit = useRef<string | null>(null);
  useEffect(() => {
    if (paymentMode !== "split") return;
    if (splitType !== "even" && splitType !== "by_items") return;
    if (splitLocked) return;

    // even needs a valid count (≥2) before BE will accept the
    // count; for by_items we POST with mode only since count is N/A.
    const numPeople = parseInt(splitCount, 10);
    const countToSend = splitType === "even" && Number.isFinite(numPeople) && numPeople >= 2
      ? numPeople
      : null;

    // Skip POST if mode is even but count not yet entered — wait
    // until the user picks a count so the kiosk sees a usable payload
    // in one round-trip rather than two.
    if (splitType === "even" && countToSend === null) return;

    // Cache key includes count so a count bump (2 → 3) re-syncs.
    const cacheKey = `${splitType}:${countToSend ?? ""}`;
    if (lastSyncedSplit.current === cacheKey) return;
    lastSyncedSplit.current = cacheKey;

    apiFetch<unknown>(`/api/v1/customer/orders/${order.id}/split-mode`, {
      method: "POST",
      body: JSON.stringify({
        split_mode: splitType,
        ...(countToSend !== null ? { split_count: countToSend } : {}),
      }),
      silent401: true,
    })
      .then(() => {
        // #407 — Stamp this device as the split-mode OWNER so the tab
        // mutex below lets the same device swap even ↔ by_items
        // freely (other devices stay locked to whichever mode this device
        // committed last). Cleared when payment commits or a fresh device
        // never wrote the flag.
        if (typeof window !== "undefined") {
          window.localStorage.setItem(`dine_in_split_owner_${order.id}`, "1");
        }
      })
      .catch(() => {
        lastSyncedSplit.current = null;
      });
  }, [paymentMode, splitType, splitCount, splitLocked, order.id]);

  // Persist splitCount vào localStorage mỗi khi user đổi (chỉ khi đang ở
  // split-by-people mode + chưa locked). Khi locked → BE đã giữ; khi đổi
  // tab khác → clear.
  useEffect(() => {
    if (typeof window === "undefined") return;
    const STORAGE_KEY = `dine_in_split_choice_${order.id}`;
    const n = parseInt(splitCount, 10);
    if (
      paymentMode === "split" &&
      splitType === "even" &&
      !splitLocked &&
      Number.isFinite(n) &&
      n >= 2
    ) {
      window.localStorage.setItem(STORAGE_KEY, String(n));
    } else if (paymentMode !== "split" || splitType !== "even") {
      // User chuyển sang Tùy chọn / Chia theo món → quên lựa chọn cũ
      window.localStorage.removeItem(STORAGE_KEY);
    }
  }, [splitCount, paymentMode, splitType, splitLocked, order.id]);

  // Coupon preview API call
  useEffect(() => {
    if (!couponDebounced) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setCouponPreview(null);
      return;
    }
    if (!currentBranch.id || !currentBranch.brand?.id) return;
    let cancelled = false;
    setCouponPending(true);
    apiFetch<{
      data: {
        is_valid: boolean;
        discount_applied_amount?: number;
        error_code?: string;
        meta?: Record<string, unknown>;
      };
    }>("/api/v1/customer/coupons/preview", {
      method: "POST",
      body: JSON.stringify({
        code: couponDebounced,
        brand_id: currentBranch.brand.id,
        branch_id: currentBranch.id,
        subtotal: remaining,
      }),
      silent401: true,
    })
      .then((res) => {
        if (!cancelled) {
          setCouponPreview(res);
        }
      })
      .catch((err) => {
        if (cancelled) return;
        if (err instanceof ApiError) {
          const body = err.body as {
            error_code?: string;
            meta?: Record<string, unknown>;
          };
          setCouponPreview({
            data: {
              is_valid: false,
              error_code: body.error_code ?? "generic",
              meta: body.meta,
            },
          });
        } else {
          setCouponPreview({
            data: { is_valid: false, error_code: "generic" },
          });
        }
      })
      .finally(() => {
        if (!cancelled) setCouponPending(false);
      });
    return () => {
      cancelled = true;
    };
  }, [couponDebounced, currentBranch.id, currentBranch.brand?.id, remaining]);

  // BE enforces one coupon per order — split-bill flow means only the first
  // payer's coupon counts. Reading discount_amount + snapshot lets us hide the
  // input for later payers instead of letting them enter a code that 422s.
  const orderAppliedCouponCode = order.coupon_code_snapshot ?? null;
  const orderAppliedDiscount = Math.round(order.discount_amount ?? 0);
  const orderHasCoupon = orderAppliedDiscount > 0 || !!orderAppliedCouponCode;

  // In by_items mode the coupon can only apply once the user picks something to
  // pay for — otherwise the discount has no payable line to attach to and the
  // user is just browsing.
  const couponBlockedByEmptyItems =
    paymentMode === "split" && splitType === "by_items" && selectedItems.size === 0;

  // Coupon discount from preview — round to whole yen (JPY has no fractional
  // units, BE may return e.g. 534.7 for a 10%-off coupon on ¥5,347). Suppress
  // the preview-side discount when the order already locked in a coupon;
  // remaining already reflects that discount so we'd double-count otherwise.
  const rawCouponDiscount = !orderHasCoupon && couponPreview?.data?.is_valid && couponPreview.data.discount_applied_amount
    ? Math.round(couponPreview.data.discount_applied_amount)
    : 0;
  const couponDiscount = couponBlockedByEmptyItems ? 0 : rawCouponDiscount;

	  // Split-bill rounding configuration per branch (Plan 029
	  // BR-SOS07).
	  const splitRoundingMode: SplitBillRoundingMode = (branch.split_bill_rounding_mode ?? "auto") as SplitBillRoundingMode;
	  const splitRoundingStep = getRoundingStep(branch.currency_code ?? "JPY", splitRoundingMode);

	  // Natural minor-unit step for the branch currency (JPY=1, USD=0.01,
	  // BHD=0.001). Plan-029 + bug 2026-06-12: the customAmount input was
	  // showing raw `String(finalRemaining)` like `2688.13` for JPY because
	  // BE-side coupon math + tax aggregation leaked a fractional cent. The
	  // BE accepts only integer JPY at Stripe (minor units), so we MUST
	  // round here before exposing the value to the input + payment intent.
	  const currencyStep = getRoundingStep(branch.currency_code ?? "JPY", "auto");

	  // Final remaining after coupon discount (base for all split calculations).
	  // Snap UP to the next minor-unit boundary so a 2688.13 JPY remaining
	  // is displayed (and charged) as 2689 — never under-charge from float drift.
	  const finalRemaining = roundUpToStep(
	    Math.max(0, remaining - couponDiscount),
	    currencyStep,
	  );

	  // Split bill: calculate per-person amount (AFTER coupon discount + prior payments)
  const numPeople = Math.max(0, Math.floor(Number(splitCount) || 0));
  // Khi splitLocked (người 2,3,...) → dùng amount_per_person BE lưu lúc người
  // đầu trả, không tự tính lại (vì remaining đã giảm sau mỗi lần trả).
  // Khi first payer → chia đều `finalRemaining` (post-paid + post-coupon) cho
  // numPeople. Dùng remaining thay vì order.total để khi đã có một lần thanh
  // toán partial (vd Tùy chọn) trước đó, share-bill lần kế tiếp chỉ chia số
  // tiền còn lại — không bắt khách trả lại phần đã trả.
	  const perPersonAmount = (() => {
	    if (numPeople < 2) return 0;
	    if (splitLocked && lockedAmountPerPerson && lockedAmountPerPerson > 0) {
	      return lockedAmountPerPerson;
	    }
	    const rawShare = finalRemaining / numPeople;
	    return roundUpToStep(rawShare, splitRoundingStep);
	  })();
  // Last person pays the remainder (may be less due to rounding)
  const isLastPerson = numPeople >= 2 && splitPaidCount === numPeople - 1;
  const splitByPeopleAmount = numPeople >= 2
    ? (isLastPerson ? finalRemaining : Math.min(perPersonAmount, finalRemaining))
    : 0;

  // #406 — "Khách N/N" indicator. The number shown is the guest position of
  // the person the screen is currently addressing.
  //  - Fresh subsequent guest (device khác / kiosk QR / người 2 mở mới, hoặc
  //    device dùng chung sau khi người trước vừa trả in-session): the NEXT
  //    payer = paid_count + 1.
  //  - Returning FIRST payer reloading after they already paid (this device
  //    is the split-mode owner AND the hard lock was restored from BE at
  //    mount, not from a payment that just committed this session): show their
  //    OWN position = paid_count so they don't see a misleading "Khách 2/3"
  //    inviting them to pay again as the next guest.
  // Clamp to numPeople so a shared device passed around after everyone paid
  // never overshoots (e.g. "Khách 4/3").
  const isSplitOwnerDevice = typeof window !== "undefined"
    && window.localStorage.getItem(`dine_in_split_owner_${order.id}`) === "1";
  const splitGuestCurrent = restoredHardLock && isSplitOwnerDevice && splitPaidCount > 0
    ? Math.min(splitPaidCount, numPeople)
    : Math.min(splitPaidCount + 1, numPeople);

  // #407 — mutex giữa "Chia đều" (`even`) và "Chia theo món" (`by_items`).
  //  - HARD: `confirmedSplitMode` (đã có payment chốt mode kia). BE cũng 409 nếu
  //    cố POST, nên đây chỉ là UX.
  //  - SOFT: `tentativeSplitMode` (first payer đã chốt mode nhưng chưa trả).
  //    Khoá với mọi guest TRỪ owner — device đã chính tay POST /split-mode gần
  //    nhất (cờ localStorage, đọc qua `isSplitOwnerDevice` ngay trên).
  //
  // #3121 — kéo lên đây từ trong JSX vì giờ CẢ HAI tầng tab đều cần: tầng 2 để
  // xám đúng lựa chọn bị khoá, tầng 1 để biết bấm "Chia hoá đơn" thì rơi vào
  // lựa chọn nào.
  const splitModeLockedByOther = (mode: "even" | "by_items"): boolean => {
    if (confirmedSplitMode && confirmedSplitMode !== mode) return true;
    if (tentativeSplitMode && tentativeSplitMode !== mode && !isSplitOwnerDevice) return true;
    return false;
  };

  // Tầng 2 chỉ biết hai giá trị. `by_amount` là thành viên thứ ba của
  // `SplitType` nhưng không tab nào đặt nó (nó thuộc `paymentMode="full"`), nên
  // quy về `even` thay vì để một tab nào cũng không sáng.
  const activeSplitType: "even" | "by_items" =
    splitType === "by_items" ? "by_items" : "even";

  /**
   * Vào tab "Chia hoá đơn" (#3121).
   *
   * KHÔNG mặc định `even` một cách máy móc: nếu mode đang giữ bị người khác
   * khoá, cú bấm sẽ đưa khách thẳng vào một tab xám không bấm được. Chọn cái
   * còn mở. Hai mode không bao giờ bị khoá cùng lúc — một lock chỉ vô hiệu hoá
   * mode CÒN LẠI — nên luôn có đường đi.
   */
  function enterSplitBillTab(): void {
    const target: "even" | "by_items" = splitModeLockedByOther(activeSplitType)
      ? (activeSplitType === "even" ? "by_items" : "even")
      : activeSplitType;

    setPaymentMode("split");
    if (target !== splitType) setSplitType(target);
    // Giữ nguyên hành vi cũ của tab "Chia đều": mặc định 2 người.
    if (target === "even" && !splitCount) setSplitCount("2");
  }

  // #3125 — tab "Toàn bộ hoá đơn": số tiền LUÔN là phần còn phải trả, khách
  // không gõ được nữa. Trước đây có ô nhập + checkbox "thanh toán toàn bộ", nên
  // giá trị này rẽ theo `isFullPayment`; state đó nay không thể false nên đã
  // xoá hẳn thay vì để lại một cờ luôn `true` (#2188).
  //
  // Vẫn là `finalRemaining` chứ KHÔNG phải `order.total`: "toàn bộ hoá đơn"
  // nghĩa là toàn bộ phần CÒN LẠI — sau các lần trả trước và sau coupon.
  const customPayAmount = finalRemaining;

  // Calculate discount, tax, service charge from order data (if available).
  // Backend `formatOrder`/`store` đã expose `tax_amount` + `service_charge`
  // sau khi recalculateTotals áp dụng ShopOrderSetting.
  const orderExt = order as ActiveOrder & {
    discount_amount?: number;
    tax_amount?: number;
    service_charge?: number;
  };
  const discount = orderExt.discount_amount ?? 0;
  const tax = orderExt.tax_amount ?? 0;
  const serviceCharge = orderExt.service_charge ?? 0;
  // plan-043 — per-rate breakdown (8%対象 / 10%対象) + tax mode from the order
  // payload; replaces the legacy single "Thuế (X%)" line. Falls back to the
  // single `tax` figure when a legacy payload lacks the breakdown.
  const taxBreakdown = order.tax_breakdown;
  const isTaxIncluded = order.is_tax_included ?? false;
  const serviceRate = branch.service_charge_rate ?? 0;

  // By items: the customer who applies the coupon absorbs the full discount;
  // whoever pays the rest pays at original price (BE order-level discount
  // already handles the math — total paid = remaining - couponDiscount).
  // Per-unit-with-options = item.subtotal / item.qty (subtotal snapshot already
  // includes toppings); fall back to item.unit_price when qty is 0 to avoid /0.
  const splitByItemsSelection = order.items
    .filter((item) => selectedItems.has(item.id))
    .map((item) => {
      // Clamp to the still-unpaid qty so an already-settled item (whose
      // paid_quantity just jumped from a counter-pay) never inflates the
      // total — guards the one render before the prune effect above runs.
      const remainingQty = Math.max(0, item.qty - (item.paid_quantity ?? 0));
      return {
        perUnitSubtotal: item.qty > 0 ? item.subtotal / item.qty : item.unit_price,
        units: Math.min(selectedItems.get(item.id) ?? 0, remainingQty),
        rate: item.tax_rate ?? null,
      };
    });
  // #32 — the picked items owe their own consumption tax + service charge, the
  // same way the order总 does. Summing raw line subtotals charged the 税抜
  // basis, so a guest claiming the whole bill paid ¥3,400 on a ¥3,740 order and
  // the kiosk QR (which encodes this amount) under-collected the tax.
  // In 総額表示 the prices already carry the tax → only the service charge is
  // added on top. `finalRemaining` still caps everything.
  const splitByItemsSelected = computeSelectionTotal(splitByItemsSelection, {
    isTaxIncluded,
    currencyCode: branch.currency_code,
    orderSubtotal: order.subtotal,
    orderTaxAmount: tax,
  });
  const splitByItemsService = serviceRate > 0
    ? roundStep(
      (splitByItemsSelected.subtotal * serviceRate) / 100,
      getRoundingStep(branch.currency_code ?? "JPY", "auto"),
    )
    : 0;
  const splitByItemsRawAmount = isTaxIncluded
    ? splitByItemsSelected.subtotal + splitByItemsService
    : splitByItemsSelected.subtotal + splitByItemsSelected.tax + splitByItemsService;
  const splitByItemsAmount = Math.min(
    Math.max(0, splitByItemsRawAmount - couponDiscount),
    finalRemaining,
  );

  // godx-tempo#1719 — SỐ LƯỢNG món đã chọn, không phải số dòng. `selectedItems`
  // là Map id → số lượng, và `splitByItemsAmount` cộng theo số lượng; dùng
  // `.size` sẽ in "Tổng cộng (2 món)" ngay cạnh số tiền của 3 phần khi khách
  // chọn 2 phở + 1 bún. Cùng lỗi với cart-drawer và màn xác nhận món.
  const selectedItemsQuantity = [...selectedItems.values()].reduce((sum, n) => sum + n, 0);

  const splitAmount =
    splitType === "even" ? splitByPeopleAmount
      : splitType === "by_items" ? splitByItemsAmount
        : 0;
  const amountToPay = paymentMode === "full" ? customPayAmount : splitAmount;

  // Đóng đơn 0đ mà không qua Stripe. settle-zero chạy đúng luồng close của đơn
  // đã trả (giải phóng bàn, đóng session, trừ kho, bắn OrderPaid) nhưng không
  // charge thẻ. Thành công → onConfirmed → PaidView.
  async function handleCompleteFree() {
    setPayError(null);
    setConfirming(true);
    try {
      const res = await apiFetch<{ data: ActiveOrder }>(
        `/api/v1/customer/orders/${order.id}/settle-zero`,
        { method: "POST", body: JSON.stringify({}) },
      );
      toast.success(t('paymentSuccess'));
      onConfirmed(res.data ?? order);
    } catch (err) {
      console.error("[dine-in/payment] free-complete failed:", err);
      setPayError(
        err instanceof ApiError
          ? t('apiError', { status: err.status, message: err.message })
          : t('paymentFailed'),
      );
      setConfirming(false);
    }
  }

  // Free-order (0đ) short-circuit — render một màn hoàn tất tối giản thay cho
  // toàn bộ UI thanh toán (Stripe/split/coupon đều vô nghĩa khi không thu tiền).
  // Đặt SAU mọi hook + derived value để không vi phạm rules-of-hooks.
  if (isFreeOrder) {
    return (
      <div className="flex flex-col min-h-dvh bg-white md:bg-neutral-50">
        <div className="py-3 shrink-0">
          <div className="mx-auto flex max-w-2xl items-center gap-2 px-4 md:gap-3 md:px-6">
            <button
              onClick={onBack}
              aria-label={tCommon('back')}
              className="size-7 flex items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground transition-colors shrink-0"
            >
              <ArrowLeft className="size-4" />
            </button>
            <span className="flex-1 min-w-0 truncate text-sm font-semibold text-neutral-800 md:text-base">
              {t('titleSimple')}
            </span>
          </div>
        </div>

        <main className="flex-1 pb-8">
          <div className="mx-auto w-full max-w-2xl px-4 md:px-6 space-y-4">
            {/* Table & order info */}
            <div className="mt-5 md:mt-0 rounded-xl border border-neutral-200 bg-white p-4">
              <div className="flex items-center justify-between">
                <h2 className="text-lg font-bold text-neutral-900 md:text-base">{table.name || table.code}</h2>
                <p className="text-xs text-muted-foreground">
                  {t('orderCode', { code: shortOrderCode(order.code) })}
                </p>
              </div>
            </div>

            {/* Free-order card */}
            <div className="rounded-xl border border-neutral-200 bg-white p-6 text-center">
              <div className="mx-auto mb-4 flex size-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                <CreditCard className="size-6" />
              </div>
              <h3 className="text-lg font-bold text-neutral-900">{t('freeOrderTitle')}</h3>
              <p className="mt-2 text-sm text-neutral-500 leading-relaxed">{t('freeOrderDescription')}</p>

              <div className="mt-4 flex items-center justify-between rounded-lg bg-neutral-50 px-4 py-3">
                <span className="text-sm font-semibold text-neutral-700">{t('totalDue')}</span>
                <span className="text-xl font-bold tabular-nums" style={{ color: '#006A34' }}>{fmt(order.total)}</span>
              </div>

              {payError && (
                <div className="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-xs text-red-600">
                  {payError}
                </div>
              )}

              <button
                onClick={handleCompleteFree}
                disabled={confirming}
                className="mt-5 w-full rounded-lg bg-[#2D8336] hover:bg-[#25692C] text-white flex items-center justify-center gap-2 disabled:opacity-60 transition-all"
                style={{ height: '56px', fontSize: '16px', fontWeight: 500, lineHeight: '24px' }}
              >
                {confirming ? (
                  <>
                    <span className="size-4 rounded-full border-2 border-white border-t-transparent animate-spin" />
                    <span className="truncate">{t('processing')}</span>
                  </>
                ) : (
                  <span className="truncate">{t('freeOrderComplete')}</span>
                )}
              </button>
            </div>
          </div>
        </main>
      </div>
    );
  }

  /**
   * The share the guest has composed is payable — same question for every online
   * gateway, so both the Stripe path and the PayPay path ask it here rather than
   * each keeping its own copy to drift.
   *
   * Returns the message to show, or null when the selection is fine.
   */
  function splitSelectionError(): string | null {
    if (paymentMode !== "split") return null;
    if (splitType === "even" && numPeople < 2) return t('splitCountRequired');
    if (splitType === "by_items" && selectedItems.size === 0) return t('selectItemsRequired');
    if (splitType === "by_amount" && (Number(customAmount) || 0) <= 0) return t('customAmountRequired');
    if (splitAmount <= 0) return t('splitAmountRequired');
    return null;
  }

  /**
   * #377 — record the split-bill mode the guest picked so the kiosk can skip its
   * own chooser on the next read. Fire-and-forget: the payment is the real
   * commit, this is metadata. A 409 (a payment already locked the mode) is
   * expected and swallowed.
   */
  function recordSplitModeChoice(): void {
    if (paymentMode !== "split") return;
    if (splitType !== "even" && splitType !== "by_items") return;

    apiFetch<unknown>(`/api/v1/customer/orders/${order.id}/split-mode`, {
      method: "POST",
      body: JSON.stringify({
        split_mode: splitType,
        ...(splitType === "even" && numPeople >= 2
          ? { split_count: numPeople }
          : {}),
      }),
      silent401: true,
    }).catch(() => {
      // Don't block the payment flow if metadata write fails.
    });
  }

  /**
   * Apply a validated coupon before the amount is committed to a gateway.
   *
   * Returns false when the caller should stop; the error text is already set.
   * Guard `!orderHasCoupon` chặn case gateway decline → user retry Pay: BE đã
   * lock coupon vào order (POST đầu thành công), retry sẽ 422 "Coupon already
   * applied to this order" → set payError → user kẹt, không bao giờ retry được
   * payment với cùng coupon. Discount đã được tính vào `remaining` qua
   * `orderAppliedDiscount` rồi.
   */
  async function applyCouponIfPending(): Promise<boolean> {
    if (!couponPreview?.data?.is_valid || !couponDebounced || orderHasCoupon) {
      return true;
    }

    try {
      await apiFetch(`/api/v1/customer/orders/${order.id}/apply-coupon`, {
        method: "POST",
        body: JSON.stringify({ code: couponDebounced }),
      });

      return true;
    } catch (err) {
      // Race-safe: nếu giữa snapshot `orderHasCoupon` và POST này có tab khác /
      // background refetch apply trước → BE trả 422 với message "Coupon already
      // applied to this order". Treat as success vì mục tiêu (coupon nằm trên
      // order) đã đạt.
      const apiErr = err instanceof ApiError ? err : null;
      const beMessage =
        apiErr?.status === 422 && apiErr.body && typeof apiErr.body === "object" && "message" in apiErr.body
          ? String((apiErr.body as { message: unknown }).message ?? "")
          : "";

      if (beMessage.toLowerCase().includes("already applied")) {
        return true;
      }

      console.error("[PaymentView] Failed to apply coupon:", err);
      setPayError(t("couponApplyError"));

      return false;
    }
  }

  /**
   * #1296 — mint a PayPay code for the share the guest just committed to.
   *
   * Order of operations matters and is the reverse of what reads naturally:
   * coupon first, split-mode second, mint LAST. A code minted before the coupon
   * lands promises to collect the undiscounted amount, and the service would then
   * refuse to resume it (its amount no longer matches what is outstanding) — so
   * the guest would watch the QR silently replace itself.
   *
   * No Stripe key check, no card element, no `confirming` spinner held across a
   * network round trip: the panel owns the mint, its retry and its failure
   * states. All this does is freeze the inputs and hand them over.
   */
  async function handlePayPayConfirm() {
    const selectionError = splitSelectionError();
    if (selectionError) {
      setPayError(selectionError);
      return;
    }

    setConfirming(true);
    try {
      if (!(await applyCouponIfPending())) return;

      recordSplitModeChoice();

      setPaypayMint({
        amount: Math.round(amountToPay),
        split: payPaySplitPayload({
          paymentMode,
          splitType,
          splitCount: numPeople,
          itemAllocations: Array.from(selectedItems.entries())
            .filter(([, qty]) => qty > 0)
            .map(([id, qty]) => ({ item_id: id, units: qty })),
        }),
      });
    } finally {
      setConfirming(false);
    }
  }

  async function handleConfirm() {
    setPayError(null);

    // Same predicate the button and the panel use — reached only from an onClick,
    // so `payingByPayPay` is long initialized by then. Setting the mint without
    // it would freeze a share that nothing on screen would then render.
    if (payingByPayPay) {
      await handlePayPayConfirm();
      return;
    }

    if (payingByCard) {
      if (!stripePublishableKey) {
        setPayError(t('stripeNotConfigured'));
        return;
      }

      const selectionError = splitSelectionError();
      if (selectionError) {
        setPayError(selectionError);
        return;
      }

      setConfirming(true);
      try {
        // Optional chaining on validate() is `undefined` when Elements is gone
        // (PayPay tab, async-methods remount) — that is NOT an error, so the
        // caller would mint a PaymentIntent and then fail at confirm() with a
        // leftover Incomplete PI (ORD-2026-0237 shape). Bail before the POST.
        if (!stripeCardRef.current) {
          setPayError(tCheckout('stripeNotReady'));
          setConfirming(false);
          return;
        }
        const v = await stripeCardRef.current.validate();
        if (v?.error) {
          setPayError(v.error);
          setConfirming(false);
          return;
        }

        // Apply coupon if user entered a valid code AND order chưa có coupon.
        if (!(await applyCouponIfPending())) {
          setConfirming(false);
          return;
        }

        // Include `split_count` for even so the second tab opening the
        // order sees the same per-person amount before the first payer's payment
        // commits. The proactive useEffect above already POSTs this, but a
        // confirm-time POST is a defensive backup for the race when the user
        // clicks confirm before the effect runs.
        recordSplitModeChoice();

        // Choose endpoint based on payment mode
        const endpoint = paymentMode === "split"
          ? `/api/v1/customer/orders/${order.id}/split-payment-intent`
          : `/api/v1/customer/orders/${order.id}/full-payment-intent`;

        // Chia theo món: gửi danh sách (item_id, units) khách đang trả để BE
        // stamp vào Stripe PaymentIntent metadata. Khi payment confirm xong,
        // OrderPayment.metadata sẽ có split_mode=by_items + item_allocations →
        // formatOrder() cộng dồn paid_quantity → món tự disable + badge "đã
        // thanh toán" trong bill. Không gửi cái này thì online by_items chỉ ghi
        // amount, paid_quantity = 0 mãi, món không bao giờ disable.
        const byItemsAllocations =
          splitType === "by_items"
            ? Array.from(selectedItems.entries())
                .filter(([, qty]) => qty > 0)
                .map(([id, qty]) => ({ item_id: id, units: qty }))
            : [];

        const body = paymentMode === "split"
          ? JSON.stringify({
            amount: splitAmount,
            // #555 M10 — retry-safe: same attempt → same key → same intent.
            idempotency_key: splitAttemptKey(splitAmount),
            // Gửi split_count + split_type để backend ghi metadata nếu đây là payment đầu tiên
            ...(splitType === "even" && numPeople >= 2 ? { split_count: numPeople, split_type: "even" } : {}),
            ...(splitType === "by_items" && byItemsAllocations.length > 0
              ? { split_type: "by_items", item_allocations: byItemsAllocations }
              : {}),
            ...paymentPolicyEcho(branchSlug),
          })
          : JSON.stringify(paymentPolicyEcho(branchSlug));

        const intentRes = await apiFetch<{
          data: { client_secret: string; payment_intent_id: string };
        }>(endpoint, { method: "POST", body });

        const returnUrl = `${window.location.origin}/order-success?${new URLSearchParams({
          id: order.id,
          code: order.code,
          type: "dine_in",
          stripe_return: "1",
        }).toString()}`;

        const confirmRes = await stripeCardRef.current?.confirm(
          intentRes.data.client_secret,
          returnUrl,
        );

        if (!confirmRes?.succeeded) {
          // #1125 option B — dine-in stays card-only (no async tabs), but a
          // card can still resolve `processing` briefly. Record the placeholder
          // server-side and tell the guest it is settling — never a raw error.
          if (confirmRes?.pending) {
            try {
              await apiFetch(`/api/v1/customer/orders/${order.id}/confirm-payment`, {
                method: "POST",
                body: JSON.stringify({ payment_intent_id: intentRes.data.payment_intent_id }),
              });
            } catch {
              /* webhook reconciles */
            }
            setPayError(t('paymentProcessing'));
            setConfirming(false);
            return;
          }
          setPayError(confirmRes?.error ?? t('paymentFailed'));
          setConfirming(false);
          return;
        }

        // Mark the payment server-side immediately so the poll below finds the
        // updated paid_amount on its first hit — no dependency on `stripe
        // listen`. Works for both split and full intents (the backend routes
        // by metadata.flow). Non-blocking: webhook reconciles on failure.
        try {
          await apiFetch(`/api/v1/customer/orders/${order.id}/confirm-payment`, {
            method: "POST",
            body: JSON.stringify({ payment_intent_id: intentRes.data.payment_intent_id }),
          });
        } catch (syncErr) {
          console.warn("[Stripe] confirm-payment sync failed; webhook will reconcile", syncErr);
        }

        // Poll order until paid_amount updates (max 10s)
        let attempts = 0;
        const maxAttempts = 20; // 20 × 500ms = 10s
        while (attempts < maxAttempts) {
          await new Promise((r) => setTimeout(r, 500));
          try {
            const checkRes = await apiFetch<{ data: ActiveOrder }>(
              `/api/v1/customer/orders/${order.id}`,
              { silent401: true },
            );
            const updated = checkRes.data;
            if (updated && (updated.paid ?? 0) > (order.paid ?? 0)) {
              // Payment landed — the attempt is over; the NEXT payment (next
              // person / next partial) must mint a fresh intent (#555 M10).
              splitAttemptRef.current = null;
              if (updated.is_fully_paid || (updated.remaining ?? 0) <= 0) {
                toast.success(t('paymentSuccess'));
                onConfirmed(updated);
                return;
              }
              // Partial payment succeeded — stay on payment view
              toast.success(t('splitPaymentSuccess', {
                amount: fmt(amountToPay),
                remaining: fmt(updated.remaining ?? 0),
              }));
              if (splitType === "even") {
                setSplitPaidCount((c) => c + 1);
                // Sau lần trả split-people đầu tiên: BE đã lưu split_count
                // vào PaymentIntent metadata. Lock UI để current user (và
                // các tab khác) không sửa được nữa. Lưu amount_per_person
                // đã trả làm reference cho subsequent payers nếu cần.
                if (!splitLocked && numPeople >= 2) {
                  setSplitLocked(true);
                  setLockedAmountPerPerson(amountToPay);
                }
              } else if (splitType === "by_items") {
                setSelectedItems(new Map());
              } else {
                setCustomAmount("");
              }
              setConfirming(false);
              if (onPartialPaid) {
                onPartialPaid(updated);
              }
              return;
            }
          } catch {
            // Keep polling
          }
          attempts++;
        }

        // Stripe.js confirm succeeded above, so the money IS captured — the
        // attempt is over even though the order row hasn't caught up yet
        // (#555 M10: keep-on-timeout applies to UNKNOWN outcomes, i.e. the
        // catch below, not this branch).
        splitAttemptRef.current = null;

        // Timeout — webhook might be delayed, but Stripe payment succeeded.
        // Fetch order 1 lần cuối trước khi pass cho onConfirmed: nếu thành công
        // → PaidView nhận order đã update (số tiền/trạng thái mới nhất);
        // nếu fetch fail → fallback về `order` cũ (paid-view.tsx đã có
        // `order.paid ?? order.total` fallback nên không hiển thị sai số).
        toast.success(t('paymentSuccess'));
        if (paymentMode === "full") {
          let finalOrder: ActiveOrder = order;
          try {
            const checkRes = await apiFetch<{ data: ActiveOrder }>(
              `/api/v1/customer/orders/${order.id}`,
              { silent401: true },
            );
            if (checkRes.data) finalOrder = checkRes.data;
          } catch (refetchErr) {
            console.warn("[dine-in/payment] final order refetch failed, using stale snapshot:", refetchErr);
          }
          onConfirmed(finalOrder);
        } else {
          setConfirming(false);
        }
      } catch (err) {
        console.error("[dine-in/payment] failed:", err);
        setPayError(
          err instanceof ApiError
            ? t('apiError', { status: err.status, message: err.message })
            : t('paymentFailed'),
        );
        setConfirming(false);
      }
      return;
    }

    setConfirming(true);
    await new Promise((r) => setTimeout(r, 1500));
    onConfirmed(order);
  }

  // JSON format → kiosk's resolveQrCode fast-path picks orderCode trực tiếp,
  // không cần BE qr/{token} lookup. Match logic trong godx-kiosk:
  // app/select-table.tsx handleScan + app/advertise.tsx handleHardwareScan.
  //
  // "Tùy chọn" (custom partial-pay): encode the chosen amount so the kiosk
  // can route straight to /custom/amount with the value pre-filled. Without
  // this, the kiosk only sees `orderCode` → falls back to the full order
  // total + share-bill options (whatever split_mode the BE has cached) and
  // ignores what the customer chose on customer-web.
  //
  // Modes that encode `amount` so kiosk routes straight to /custom/method
  // (single confirm → method picker → done, NO bill-review + count entry):
  //   - "Toàn bộ hoá đơn": paymentMode==="full", customPayAmount>0
  //   - "Chia đều"  : paymentMode==="split", splitType==="even",
  //                   numPeople>=2 → splitByPeopleAmount (≈ remaining/N)
  //   - "Chia theo món": paymentMode==="split", splitType==="by_items",
  //                     selected items present → splitByItemsAmount
  //
  // "Toàn bộ hoá đơn" VẪN phải encode amount — #3125 bỏ ô nhập tay nhưng KHÔNG
  // làm lý do dưới đây hết đúng, nó còn làm lý do đó thành đường duy nhất:
  //   - "Pay full" KHÔNG NHẤT THIẾT là toàn bộ order.total — nó là
  //     `finalRemaining` (post-paid + post-coupon). Khi đã có người 1 trả
  //     Chia đều ¥2,885 trên hoá đơn ¥8,653, người 2 chọn "pay full" =
  //     ¥5,768 (remaining), KHÔNG phải ¥8,653.
  //   - Nếu QR chỉ có orderCode → kiosk fallback đọc BE order với
  //     split_mode=even đã set → kiosk hiện ¥2,885/người (per-person
  //     cũ) thay vì ¥5,768. Sai amount → cashier confuse.
  //   - Encode amount = customPayAmount luôn → kiosk hiển thị đúng số
  //     bất kể BE còn cached split_mode gì.
  //
  // For share-bill, the amount per scan = THIS customer's share. The next
  // customer (re-enters customer-web with same order) sees their own share
  // because BE's splitLocked + remaining_amount yields the next per-person
  // value on re-render. Kiosk treats every QR as a one-shot partial pay.
  const counterPartialAmount = (() => {
    if (paymentMode === "full" && customPayAmount > 0) {
      return Math.round(customPayAmount);
    }
    if (paymentMode === "split" && splitType === "even" && splitByPeopleAmount > 0) {
      return Math.round(splitByPeopleAmount);
    }
    if (paymentMode === "split" && splitType === "by_items" && splitByItemsAmount > 0) {
      return Math.round(splitByItemsAmount);
    }
    return null;
  })();
  // For by_items mode, encode the selected (item_id, units) so the kiosk can
  // forward them as `metadata.item_allocations` on the pay request. This is
  // the only path that lets BE attribute the payment to specific items →
  // formatOrder()'s claimedByItem sees the units → customer-web's by_items
  // panel shows "Đã thanh toán" on items the kiosk just settled. Without
  // this, kiosk's amount-only payment is recorded with no per-item link and
  // every guest re-rendering sees paid_quantity=0 (the bug user reported).
  const counterItems =
    paymentMode === "split" && splitType === "by_items" && selectedItems.size > 0
      ? Array.from(selectedItems.entries())
          .filter(([, qty]) => qty > 0)
          .map(([id, qty]) => ({ id, qty }))
      : null;
  const counterQr = JSON.stringify({
    orderCode: order.code,
    ...(counterPartialAmount !== null && counterPartialAmount > 0
      ? { amount: counterPartialAmount }
      : {}),
    ...(counterItems !== null && counterItems.length > 0
      ? { items: counterItems }
      : {}),
  });
  // #2806 — the payload above is built either way. Hiding the QR is a DISPLAY
  // decision; `counterPartialAmount` / `counterItems` are the only path
  // carrying the guest's split-bill choice to the kiosk, so a shop that turns
  // the QR back on must find it still working.
  const counterQrShown = shouldShowCounterPayQr(counterPaySettings);

  const totalQty = order.items.reduce((s, i) => s + i.qty, 0);

  // Nút thanh toán (online / PayPay) — dùng chung cho sidebar desktop và sticky
  // footer mobile. PayPay chỉ đổi nhãn + icon: cùng một nút, cùng handler, vì
  // phần validate chia bill và coupon là như nhau.
  //
  // #1303 — every one of these comes from `dineInOnlineSurface` so the parts of
  // this card cannot contradict each other. `payingByPayPay` means "the confirm
  // button will mint", which is NOT the same as "a code is on screen".
  const payingOnline = method === "online";
  const payingByCard = onlineSurface.showCard;
  const payingByPayPay = payingOnline && activeGateway === "paypay" && paypayQrEnabled;

  // #3116 — the flat radio list Takeaway uses, projected onto the two-level
  // state this screen keeps. It is a PROJECTION, not a replacement: `method`
  // (channel) and `onlineGateway` (gateway) still hold the truth, and every
  // body rendered under the list still asks `dineInOnlineSurface()`. One radio
  // row simply writes both levels in a single tap, which is exactly what a tap
  // on "Trực tuyến" followed by a tap on the "Stripe" tab used to do.
  // #3118 — luật của phép chiếu nay sống ở `lib/payment-method-choice.ts` và
  // CÓ RÀO. Trước đó nó nằm nguyên trong file này, nghĩa là không ghim được:
  // test runner của customer-web (`node --test 'lib/**' 'messages/**'`) không
  // dựng DOM nên không với tới một component. Đo được: gỡ `setOnlineGateway`
  // khỏi hàm chọn thì 607 test vẫn xanh, triệu chứng duy nhất là khách bấm
  // "PayPay" rồi trả bằng thẻ.
  const paymentChoice: PaymentOption = paymentOptionFrom(method, activeGateway);

  // The PayPay row exists wherever the branch can mint — INDEPENDENT of the
  // channel currently chosen, unlike the old tab bar (`showTabs` is false while
  // the guest is on "tại quầy"). A flat list whose rows appear and disappear as
  // you move between them is not a list; keeping the row rendered is what makes
  // it one. A live code keeps the row alive even if the capability later says
  // no, for the same reason `showQrPanel` outranks `paypayEnabled`.
  const payPayOptionShown = payPayRowShown(paypayQrEnabled, paypayMint);

  // Same rule the channel buttons and gateway tabs enforced with `disabled`:
  // a minted code is live at PayPay, so walking away from it silently would
  // leave it scannable with nothing on screen tracking it. Cancel is the way out.
  const paymentChoiceLocked = isPaymentChoiceLocked(paypayMint);
  function choosePaymentOption(next: PaymentOption): void {
    if (!canSelectPaymentOption(next, paymentChoice, paymentChoiceLocked)) return;
    const state = paymentStateFor(next);
    setMethod(state.method);
    // `gateway === null` nghĩa là ĐỪNG ghi, không phải "ghi giá trị mặc định":
    // chọn "tại quầy" không nói gì về cổng, và đè lên đây sẽ âm thầm đổi lựa
    // chọn cổng của khách khi họ chỉ ghé qua rồi quay lại.
    if (state.gateway !== null) setOnlineGateway(state.gateway);
  }

  /** Shared row chrome — selected ô viền/nền xanh, ô còn lại viền xám (Takeaway). */
  const paymentRowStyle = (option: PaymentOption) => ({
    backgroundColor: paymentChoice === option ? "#2D8A390D" : "transparent",
    border: paymentChoice === option ? "1px solid #2D8A39" : "1px solid #E5E7EB",
  });
  const confirmButton = (
    <button
      onClick={handleConfirm}
      disabled={confirming || amountToPay <= 0}
      className="w-full rounded-lg bg-[#2D8336] hover:bg-[#25692C] text-white flex items-center justify-center gap-2 disabled:opacity-60 transition-all"
      style={{
        height: '56px',
        fontSize: '16px',
        fontWeight: 500,
        lineHeight: '24px'
      }}
    >
      {confirming ? (
        <>
          <span className="size-4 rounded-full border-2 border-white border-t-transparent animate-spin" />
          <span className="truncate">{t('processing')}</span>
        </>
      ) : payingByPayPay ? (
        <>
          <Smartphone className="size-4 shrink-0" />
          <span className="truncate">{t('paypayShowQr')}</span>
        </>
      ) : (
        <>
          <CreditCard className="size-4 shrink-0" />
          <span className="truncate">{t('payMyPart')}</span>
        </>
      )}
    </button>
  );

  // Desktop summary - original
  const orderSummary = (
    <div className="rounded-xl bg-white p-4 md:p-5 lg:border lg:border-neutral-200">
      <div className="flex items-center justify-between gap-2">
        <h3 className="min-w-0 flex-1 truncate text-lg font-bold text-neutral-900">{t('paymentSummary')}</h3>
        <span className="hidden md:inline-block shrink-0 rounded-full bg-green-50 px-2.5 py-0.5 text-[11px] font-semibold text-green-600">
          #{shortOrderCode(order.code)}
        </span>
      </div>

      <div className="mt-3 space-y-2.5 md:mt-4 md:space-y-3">
        <div className="flex items-center justify-between gap-3 text-xs md:text-sm">
          <span className="min-w-0 flex-1 text-neutral-600">{t('billTotal')}</span>
          <span className="shrink-0 font-medium text-neutral-900 tabular-nums">{fmt(order.subtotal)}</span>
        </div>

        {discount > 0 && (
          <div className="flex items-center justify-between gap-3 text-xs md:text-sm">
            <span className="min-w-0 flex-1 text-green-600">{t('discount')}</span>
            <span className="shrink-0 font-medium text-green-600 tabular-nums">- {fmt(discount)}</span>
          </div>
        )}

        {serviceCharge > 0 && (
          <div className="flex items-center justify-between gap-3 text-xs md:text-sm">
            <span className="min-w-0 flex-1 text-neutral-600">{t('serviceCharge')}{serviceRate > 0 ? ` (${serviceRate}%)` : ''}</span>
            <span className="shrink-0 font-medium text-neutral-900 tabular-nums">{fmt(serviceCharge)}</span>
          </div>
        )}
        {/* plan-043 — per-rate consumption-tax breakdown (8%対象 / 10%対象).
            Falls back to the single tax figure for legacy payloads. */}
        {taxBreakdown && taxBreakdown.length > 0 ? (
          <TaxBreakdownLines
            breakdown={taxBreakdown}
            isTaxIncluded={isTaxIncluded}
            format={fmt}
            namespace="dineInPayment"
            className="space-y-2.5 md:space-y-3"
          />
        ) : tax > 0 ? (
          <div className="flex items-center justify-between gap-3 text-xs md:text-sm">
            <span className="min-w-0 flex-1 text-neutral-600">{t('tax')}</span>
            <span className="shrink-0 font-medium text-neutral-900 tabular-nums">{fmt(tax)}</span>
          </div>
        ) : null}

        <div className="flex items-center justify-between gap-3 border-t border-neutral-100 pt-2.5 md:pt-3">
          <span className="min-w-0 flex-1 text-lg font-bold text-neutral-900">
            {t('totalDue')}
            <span className="ml-1.5 align-middle text-[11px] font-medium text-neutral-500">
              ({t('taxIncludedBadge')})
            </span>
          </span>
          <span className="shrink-0 text-xl font-bold tabular-nums md:text-2xl" style={{ color: '#006A34' }}>
            {fmt(order.total)}
          </span>
        </div>

        {hasPriorPayments && (
          <>
            <div className="flex items-center justify-between gap-3 text-xs md:text-sm">
              <span className="min-w-0 flex-1 text-neutral-500">{t('alreadyPaid')}</span>
              <span className="shrink-0 font-medium text-green-600 tabular-nums">- {fmt(order.paid ?? 0)}</span>
            </div>
            <div className="flex items-center justify-between gap-3 border-t border-neutral-100 pt-2.5 md:pt-3">
              <span className="min-w-0 flex-1 text-sm font-bold text-red-600 md:text-base">{t('remainingLabel')}</span>
              <span className="shrink-0 text-xl font-bold text-red-600 tabular-nums md:text-2xl">
                {fmt(remaining)}
              </span>
            </div>
          </>
        )}

        {/* Coupon section. Two modes:
            - Order already locked in a coupon → show "Đã áp mã X" info, no input.
            - No coupon yet → input + Áp dụng button. */}
        <div className="space-y-2 border-t border-neutral-100 pt-2.5 md:pt-3">
          <label className="hidden md:block text-xs md:text-sm font-medium text-neutral-700">{t('couponLabel')}</label>
          {orderHasCoupon ? (
            <div className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs md:text-sm text-emerald-700">
              <div className="flex items-center justify-between gap-2">
                <span className="font-medium">
                  {t('couponAppliedInfo', { code: orderAppliedCouponCode ?? "" })}
                </span>
                {orderAppliedDiscount > 0 && (
                  <span className="font-semibold tabular-nums">
                    −{fmt(orderAppliedDiscount)}
                  </span>
                )}
              </div>
              <p className="mt-1 text-[11px] text-emerald-600/80">
                {t('couponAppliedHint')}
              </p>
            </div>
          ) : (
            <div className="flex gap-2">
              <Input
                placeholder={t('couponPlaceholder')}
                className="flex-1 h-[42px] text-[18px] placeholder:text-[16px] placeholder:text-[#C4C4C4]"
                value={couponCode}
                onChange={(e) => setCouponCode(e.target.value.toUpperCase())}
                maxLength={50}
              />
              <Button
                type="button"
                size="sm"
                className="shrink-0 bg-[#2D8336] hover:bg-[#25692C] h-[42px] text-[14px]"
                disabled={!couponCode.trim() || couponPending}
                onClick={() => {
                  setCouponDebounced(couponCode.trim().toUpperCase());
                }}
              >
                {couponPending ? (
                  <Loader2 className="size-3.5 animate-spin" />
                ) : (
                  t('couponApply')
                )}
              </Button>
            </div>
          )}

          {/* Coupon error */}
          {couponPreview?.data?.is_valid === false && couponPreview.data.error_code && (
            <div className="rounded-md border border-destructive/30 bg-destructive/5 px-2.5 py-1.5 text-xs text-destructive">
              {couponPreview.data.error_code === "coupon_min_subtotal_not_met"
                ? tCheckout('couponError.coupon_min_subtotal_label', { amount: fmt(Number(couponPreview.data.meta?.min_required ?? 0)) })
                : tCheckout(`couponError.${couponPreview.data.error_code}` as Parameters<typeof tCheckout>[0]) ||
                  tCheckout('couponError.generic')}
            </div>
          )}

          {/* Warning when by_items mode has a valid coupon but nothing picked */}
          {couponBlockedByEmptyItems && rawCouponDiscount > 0 && (
            <div className="rounded-md border border-amber-300 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-700">
              {tCheckout('couponError.coupon_blocked_no_items_selected')}
            </div>
          )}

          {/* Discount row (only if valid coupon and actually applied) */}
          {couponDiscount > 0 && (
            <div className="flex items-center justify-between gap-3 text-xs md:text-sm">
              <span className="min-w-0 flex-1 text-emerald-700 font-medium">
                {t('discountCoupon', { code: couponDebounced })}
              </span>
              <span className="shrink-0 font-semibold text-emerald-700 tabular-nums">
                −{fmt(couponDiscount)}
              </span>
            </div>
          )}
        </div>

        {/* Final "Tổng cộng" — what the user actually pays now.
            - by_items: their picked-items subtotal (0 until items are picked).
            - others: whole-order remaining post-coupon. */}
        {paymentMode === "split" && splitType === "by_items" ? (
          <div className="flex items-center justify-between gap-3 border-t border-neutral-100 pt-2.5 md:pt-3">
            <span className="min-w-0 flex-1 text-sm font-bold text-neutral-900 md:text-base">
              {t('selectedTotalWithCount', { count: selectedItemsQuantity })}
            </span>
            <span className="shrink-0 text-xl font-bold text-neutral-900 tabular-nums md:text-2xl">
              {fmt(splitByItemsAmount)}
            </span>
          </div>
        ) : couponDiscount > 0 ? (
          <div className="flex items-center justify-between gap-3 border-t border-neutral-100 pt-2.5 md:pt-3">
            <span className="min-w-0 flex-1 text-sm font-bold text-neutral-900 md:text-base">{t('total')}</span>
            <span className="shrink-0 text-xl font-bold text-neutral-900 tabular-nums md:text-2xl">
              {fmt(finalRemaining)}
            </span>
          </div>
        ) : null}

        {/* Split by people: show per-person amount */}
        {paymentMode === "split" && splitType === "even" && numPeople >= 2 && (
          <div className="flex items-center justify-between gap-3 border-t border-neutral-100 pt-2.5 md:pt-3">
            <span className="min-w-0 flex-1 text-lg font-bold text-[#2D8336]">
              {t('splitPerPersonWithCount', { count: numPeople })}
            </span>
            <span className="shrink-0 text-xl font-bold text-[#2D8336] tabular-nums md:text-2xl">
              {fmt(perPersonAmount)}
            </span>
          </div>
        )}

        {/* #3125 — dòng "Số tiền tuỳ chọn" ĐÃ GỠ. Nó chỉ hiện khi khách gõ một
            số nhỏ hơn hoá đơn (`!isFullPayment`), mà đường gõ đó không còn.
            Tổng phải trả nay luôn là dòng "Tổng cần trả" ngay trên. */}
      </div>

      {/* Error */}
      {payError && (
        <div className="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-xs text-red-600">
          {payError}
        </div>
      )}

      {/* Confirm button — online, or PayPay before a code exists. Desktop only:
          on mobile it lives in the sticky footer at the bottom of the screen.
          Once a PayPay code is minted the button goes away — the QR panel owns
          the screen from there, and a second press would void the live code. */}
      {onlineSurface.showConfirmButton && (
        <div className="mt-3 hidden md:block">{confirmButton}</div>
      )}

      {/* Counter payment hint */}
      {method === "counter" && (
        <p className="mt-3 text-xs text-neutral-400 text-center leading-relaxed px-2">
          {t('staffConfirm')}
        </p>
      )}
    </div>
  );

  return (
    <div className="flex flex-col min-h-dvh bg-white md:bg-neutral-50">
      {/* Mobile Header — sticky ngay dưới global Header (h-12 = 48px) */}
      <div className="md:hidden sticky top-12 z-30 bg-white py-3 shrink-0 border-b border-neutral-200">
        <div className="mx-auto max-w-6xl flex items-center gap-2 px-4">
          <button
            onClick={onBack}
            aria-label={tCommon('back')}
            className="size-8 flex items-center justify-center -ml-1"
          >
            <ArrowLeft className="size-5" />
          </button>
          <h1 className="text-xl font-bold text-neutral-900 md:text-base">{t('titleSimple')}</h1>
        </div>
      </div>

      {/* Desktop Header — inner container `max-w-7xl` để khớp width với
          global Header (mặc định max-w-7xl). Back arrow + "Thanh toán"
          align cùng cột dọc với logo brand ở Header trên desktop. */}
      <div className="hidden md:block py-3 shrink-0">
        <div className="mx-auto flex max-w-7xl items-center gap-2 px-4 md:gap-3 md:px-6">
          <button
            onClick={onBack}
            aria-label={tCommon('back')}
            className="size-7 flex items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground transition-colors shrink-0"
          >
            <ArrowLeft className="size-4" />
          </button>
          <span className="flex-1 min-w-0 truncate text-sm font-semibold text-neutral-800 md:text-base">
            {t('titleSimple')}
          </span>
        </div>
      </div>

      <main className="flex-1 pb-8">
        <div className="mx-auto w-full max-w-6xl px-4 md:px-6 lg:grid lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start lg:gap-6">
          {/* Main column */}
          <div className="lg:min-w-0 space-y-4">

            {/* Table & Order Info */}
            <div className="mt-5 md:mt-0 rounded-xl border border-neutral-200 bg-white p-4">
              <div className="space-y-2">
                {/* Row 1: Table name + Order code */}
                <div className="flex items-center justify-between">
                  <h2 className="text-lg font-bold text-neutral-900 md:text-base">{table.name || table.code}</h2>
                  <p className="text-xs text-muted-foreground">
                    {t('orderCode', { code: shortOrderCode(order.code) })}
                  </p>
                </div>
                {/* Row 2: Total seats + Date time */}
                <div className="flex items-center justify-between">
                  <p className="text-sm text-muted-foreground md:text-xs">
                    {/* godx-tempo#1719 — chuỗi này là "Tổng cộng: {count} NGƯỜI".
                        Fallback cũ `|| order.items.reduce(…i.qty)` lấy TỔNG SỐ MÓN
                        làm số người khi bàn chưa khai báo chỗ ngồi — bàn gọi 7 món
                        thì in "Tổng cộng: 7 người". Số đó vô nghĩa, mà lại nằm ngay
                        trên màn chia tiền theo đầu người. Không biết thì không nói:
                        để trống, giữ nguyên ô flex cho ngày giờ vẫn căn phải. */}
                    {table.seats ? t('tableSeats', { count: table.seats }) : null}
                  </p>
                  <p className="text-sm text-muted-foreground md:text-xs">
                    {formatGuestDate(new Date(), locale)} - {formatGuestTime(new Date(), locale)}
                  </p>
                </div>
              </div>
            </div>

            {/* Payment Mode Tabs */}
            <div className="rounded-xl border border-neutral-200 bg-white p-4">
              {/* #3121 — TẦNG 1: "Tùy chọn" (`paymentMode="full"`) vs "Chia hoá
                  đơn" (`paymentMode="split"`).

                  Trước đây ba tab nằm ngang hàng — "Tùy chọn", "Chia đều",
                  "Chia theo món" — nhưng chúng không cùng cấp: hai cái sau đều
                  là `split`, chỉ khác `splitType`. Hàng phẳng nói dối về cấu
                  trúc đó, nên tách làm hai tầng. State vẫn là hai biến riêng;
                  tab chỉ ghi vào chúng. */}
              <div className="grid grid-cols-2 gap-1.5 p-1 rounded-lg sm:gap-2 sm:p-1.5 h-[42px] md:h-auto" style={{ backgroundColor: '#F6F3F2' }}>
                {([
                  { key: "full" as const, label: t('tabFullBill') },
                  { key: "split" as const, label: t('tabSplitBill') },
                ]).map(({ key, label }) => {
                  const selected = paymentMode === key;
                  return (
                    <button
                      key={key}
                      type="button"
                      onClick={() => {
                        if (key === "split") {
                          enterSplitBillTab();
                          return;
                        }
                        setPaymentMode("full");
                      }}
                      className={`flex items-center justify-center h-full px-1.5 text-xs font-medium rounded transition-colors md:py-2 md:px-2.5 md:rounded-md text-center whitespace-nowrap lg:text-base lg:font-semibold lg:leading-[18px] ${selected
                        ? "bg-white text-[#006A34]"
                        : "bg-transparent text-neutral-700 lg:text-[#3F4940]"
                        }`}
                      style={selected ? { boxShadow: '0px 1px 2px 0px #0000000D' } : undefined}
                    >
                      {label}
                    </button>
                  );
                })}
              </div>

              {/* #3121 — TẦNG 2: chỉ hiện khi đang ở "Chia hoá đơn". Cố ý KHÁC
                  hình dạng với tầng 1 (nút viền, không phải pill trên nền xám)
                  để đọc ra ngay là một cấp con, chứ không phải hàng tab thứ hai
                  ngang hàng.

                  Mutex #407 giữ nguyên: lựa chọn bị người khác khoá thì xám và
                  không bấm được, banner giải thích nằm ngay dưới. */}
              {paymentMode === "split" && (
                <div className="mt-3 grid grid-cols-2 gap-2 md:gap-3">
                  {([
                    { key: "even" as const, label: t('tabSplitEven') },
                    { key: "by_items" as const, label: t('tabSplitByItems') },
                  ]).map(({ key, label }) => {
                    const selected = activeSplitType === key;
                    const disabled = splitModeLockedByOther(key);
                    return (
                      <button
                        key={key}
                        type="button"
                        disabled={disabled}
                        onClick={() => {
                          if (disabled) return;
                          setSplitType(key);
                          // Giữ nguyên hành vi cũ: mặc định 2 người khi vào "Chia đều".
                          if (key === "even" && !splitCount) {
                            setSplitCount("2");
                          }
                        }}
                        className={`flex items-center justify-center gap-1.5 rounded-lg border px-2 py-2.5 text-xs font-medium transition-colors md:gap-2 md:px-4 md:py-3 md:text-base ${disabled
                          ? "border-neutral-200 bg-neutral-50 text-neutral-400 cursor-not-allowed"
                          : selected
                            ? "border-[#006A34] bg-[#E8F5E9] text-[#006A34]"
                            : "border-neutral-300 bg-white text-neutral-700 hover:bg-neutral-50"
                          }`}
                      >
                        <span className="truncate md:whitespace-normal">{label}</span>
                      </button>
                    );
                  })}
                </div>
              )}

              {/* #407 — Banner explaining why a tab is greyed out. Two copy
                  variants depending on who triggered the lock from THIS
                  device's perspective:
                  - SELF: hard lock AND this device was the owner (the device
                    that POSTed /split-mode last). User chose Chia đều, paid,
                    and now wants to switch — explain it's locked because
                    they themselves committed.
                  - OTHER: hard lock without owner flag OR soft lock from
                    another device. Frame it as "someone else chose X". */}
              {(() => {
                const isSplitOwner = typeof window !== "undefined"
                  && window.localStorage.getItem(`dine_in_split_owner_${order.id}`) === "1";
                const lockingMode = confirmedSplitMode
                  ?? (!isSplitOwner ? tentativeSplitMode : null);
                if (!lockingMode) return null;

                const isSelfPaid = confirmedSplitMode !== null && isSplitOwner;
                const messageKey = isSelfPaid
                  ? (lockingMode === "even"
                    ? "splitModeSelfPaidByPeopleBanner"
                    : "splitModeSelfPaidByItemsBanner")
                  : (lockingMode === "even"
                    ? "splitModeLockedByPeopleBanner"
                    : "splitModeLockedByItemsBanner");

                return (
                  <div className="mt-3 flex items-start gap-2 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2 text-xs text-amber-900">
                    <span className="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full bg-amber-500 text-[10px] font-bold text-white">i</span>
                    <p className="leading-relaxed">{t(messageKey)}</p>
                  </div>
                );
              })()}

              {/* #3125 — "Toàn bộ hoá đơn": số tiền phải trả, chỉ để ĐỌC.
                  Ô nhập tay + checkbox "thanh toán toàn bộ hoá đơn" đã gỡ —
                  cùng với chúng là đường trả một phần tuỳ ý từ customer-web.
                  Khách muốn chia nhỏ thì dùng tab "Chia hoá đơn".

                  Vẫn là một `<input readOnly>` chứ không phải một dòng chữ:
                  `readOnly` (KHÔNG phải `disabled`) giữ ô trong luồng tab và
                  đọc được bằng screen reader — `disabled` sẽ nhấc chính con số
                  khách sắp trả ra khỏi tầm với của bàn phím. */}
              {paymentMode === "full" && (
                <div className="mt-4 space-y-3">
                  <label htmlFor="full-bill-amount" className="text-sm font-medium text-neutral-900 md:font-semibold">
                    {t('fullBillAmountLabel')}
                  </label>
                  <div>
                    <input
                      id="full-bill-amount"
                      type="text"
                      inputMode="numeric"
                      readOnly
                      value={String(finalRemaining)}
                      className="w-full cursor-default rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-base text-neutral-900 focus:outline-none"
                    />
                    <p className="mt-1 text-xs text-neutral-500 tabular-nums">
                      {fmt(finalRemaining)}
                    </p>
                  </div>
                </div>
              )}

              {/* Split by people UI */}
              {paymentMode === "split" && splitType === "even" && (
                <div className="mt-4 space-y-3">
                  <div className="flex items-center justify-between">
                    <span className="text-base font-semibold text-neutral-900 md:text-sm">{t('splitCountLabel')}</span>
                    <div className="flex items-center gap-2 rounded-full px-2 py-1" style={{ backgroundColor: '#F6F3F2' }}>
                      <button
                        type="button"
                        onClick={() => {
                          if (splitLocked) return; // Locked: người đầu đã chọn
                          const current = Number(splitCount) || 0;
                          if (current > 2) {
                            setSplitCount(String(current - 1));
                            setSplitPaidCount(0);
                          }
                        }}
                        aria-label={`${t('decrease')}: ${t('splitCountLabel')}`}
                        className="flex size-7 items-center justify-center rounded-full text-neutral-600 transition-colors hover:bg-neutral-300 disabled:opacity-30 md:bg-neutral-200"
                        style={{ backgroundColor: '#E4E2E1' }}
                        disabled={splitLocked || numPeople <= 2}
                      >
                        <Minus className="size-3.5" />
                      </button>
                      <span className="min-w-[2ch] text-center text-lg font-bold tabular-nums text-neutral-900">
                        {splitCount || "0"}
                      </span>
                      <button
                        type="button"
                        onClick={() => {
                          if (splitLocked) return; // Locked: người đầu đã chọn
                          const current = Number(splitCount) || 0;
                          setSplitCount(String(current + 1));
                          setSplitPaidCount(0);
                        }}
                        aria-label={`${t('increase')}: ${t('splitCountLabel')}`}
                        className="flex size-7 items-center justify-center rounded-full text-white transition-colors hover:bg-emerald-700 disabled:opacity-30"
                        style={{ backgroundColor: '#006A34' }}
                        disabled={splitLocked}
                      >
                        <Plus className="size-3.5" />
                      </button>
                    </div>
                  </div>

                  {numPeople >= 2 && (
                    <div className="flex flex-col items-center justify-center rounded-xl p-4 md:p-3 gap-1.5" style={{ backgroundColor: '#2685491A' }}>
                      <div className="text-sm font-normal text-neutral-600 md:text-xs">
                        {t('totalAmountLabel')} {fmt(order.total)}
                      </div>
                      <div className="flex items-baseline gap-1">
                        <span className="text-2xl font-bold tabular-nums" style={{ color: '#006A34' }}>
                          {fmt(splitByPeopleAmount)}
                        </span>
                        <span className="text-sm font-normal text-neutral-600">{t('perPersonSuffix')}</span>
                      </div>
                      <div className="text-sm font-medium text-neutral-700">
                        {t('splitGuestProgress', { current: splitGuestCurrent, total: numPeople })}
                      </div>
                    </div>
                  )}
                </div>
              )}

              {/* Split by items UI */}
              {paymentMode === "split" && splitType === "by_items" && (
                <div className="mt-4 space-y-3">
                  {/* Info banner */}
                  <div className="flex items-start gap-2 rounded-xl bg-emerald-50 px-3 py-2.5 text-xs text-neutral-800">
                    <span className="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-[10px] font-bold leading-none text-white">
                      i
                    </span>
                    <p className="leading-relaxed">
                      {t('splitByItemsBannerFull')}
                    </p>
                  </div>

                  {/* Item cards */}
                  <div className="space-y-2.5">
                    {order.items.filter((item) => (item.status as string) !== "voided").map((item) => {
                      const paidQty = item.paid_quantity ?? 0;
                      // An item is "fully paid" when every unit has been
                      // claimed by prior by_items payments. Partially-paid
                      // items expose only the unpaid remainder for selection.
                      const remainingQty = Math.max(0, item.qty - paidQty);
                      const isFullyPaid = remainingQty === 0;
                      const selectedQty = selectedItems.get(item.id) ?? 0;
                      const isSelected = selectedQty > 0;
                      const unitWithOptions = item.qty > 0 ? item.subtotal / item.qty : item.unit_price;

                      const inc = () => {
                        if (isFullyPaid) return;
                        setSelectedItems((prev) => {
                          const next = new Map(prev);
                          const cur = next.get(item.id) ?? 0;
                          if (cur < remainingQty) next.set(item.id, cur + 1);
                          return next;
                        });
                      };
                      const dec = () => {
                        if (isFullyPaid) return;
                        setSelectedItems((prev) => {
                          const next = new Map(prev);
                          const cur = next.get(item.id) ?? 0;
                          if (cur <= 1) {
                            next.delete(item.id);
                          } else {
                            next.set(item.id, cur - 1);
                          }
                          return next;
                        });
                      };

                      return (
                        <div
                          key={item.id}
                          className={`rounded-xl border p-3 transition-colors ${
                            isFullyPaid
                              ? "border-red-200 bg-red-50/60 opacity-70"
                              : isSelected
                                ? "border-green-500 bg-[#F9FFFB] ring-1 ring-green-500"
                                : "border-neutral-200 bg-white hover:border-neutral-300"
                          }`}
                          aria-disabled={isFullyPaid}
                        >
                          <div className="flex gap-2.5">
                            {/* Image */}
                            <div className="relative size-16 shrink-0 overflow-hidden rounded-lg bg-neutral-100">
                              {item.image_url ? (
                                <img src={item.image_url} alt={item.name} className="h-full w-full object-cover" />
                              ) : (
                                <div className="flex h-full w-full items-center justify-center text-xs text-neutral-400">—</div>
                              )}
                              {/* Checkmark badge when selected */}
                              {isSelected && selectedQty > 0 && (
                                <div className="absolute right-0.5 top-0.5 flex size-6 items-center justify-center rounded-full shadow-sm" style={{ backgroundColor: '#299236' }}>
                                  <svg className="size-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth="3">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                  </svg>
                                </div>
                              )}
                            </div>

                            {/* Body */}
                            <div className="min-w-0 flex-1">
                              <div className="flex items-start justify-between gap-2">
                                <h3 className={`text-sm font-semibold line-clamp-2 ${isFullyPaid ? "text-neutral-500 line-through" : "text-neutral-900"}`}>{item.name}</h3>
                                <span className="shrink-0 font-bold text-[#1F2937] tabular-nums" style={{ fontSize: '16px', lineHeight: '20px' }}>x{item.qty}</span>
                              </div>

                              {item.options && item.options.length > 0 && (
                                <ul className="mt-1 space-y-0.5 text-[11px] text-neutral-500">
                                  {item.options.map((o) => (
                                    <li key={o.id}>+ {o.name ?? "—"} ({fmt(o.unit_price)})</li>
                                  ))}
                                </ul>
                              )}

                              {item.note && (
                                <p className="mt-1 text-[11px] italic text-neutral-500">{t('itemNoteLabel', { note: item.note })}</p>
                              )}

                              {/* Fully-paid items show a pill instead of the
                                  qty +/- — there is nothing left to allocate.
                                  Partially-paid items keep the +/- but the
                                  upper bound is `remainingQty` (set above). */}
                              {isFullyPaid ? (
                                <div className="mt-2 flex items-center justify-between gap-2">
                                  <span className="text-sm font-bold text-neutral-500 tabular-nums line-through">{fmt(unitWithOptions)}</span>
                                  <span className="inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-1 text-[11px] font-semibold text-red-700">
                                    <svg className="size-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" strokeWidth="3"><path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    {t('itemAlreadyPaid')}
                                  </span>
                                </div>
                              ) : (
                                <div className="mt-2 flex items-center justify-between">
                                  <div className="flex flex-col">
                                    <span className="text-sm font-bold text-neutral-900 tabular-nums">{fmt(unitWithOptions)}</span>
                                    {paidQty > 0 && (
                                      <span className="text-[10px] font-medium text-red-600">
                                        {t('itemPartiallyPaid', { paid: paidQty, total: item.qty })}
                                      </span>
                                    )}
                                  </div>
                                  <div className="flex items-center gap-2">
                                    <button
                                      type="button"
                                      onClick={dec}
                                      disabled={selectedQty === 0}
                                      // Names the DISH, not just the direction:
                                      // this list is where the guest picks which
                                      // dishes they are paying for, and "giảm"
                                      // repeated N times says nothing.
                                      aria-label={`${t('decrease')}: ${item.name}`}
                                      className="flex size-6 items-center justify-center rounded-full border border-neutral-300 text-neutral-600 transition-colors hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-30"
                                    >
                                      <Minus className="size-3" />
                                    </button>
                                    <span className="min-w-[2ch] text-center text-sm font-bold tabular-nums text-neutral-900">{selectedQty}</span>
                                    <button
                                      type="button"
                                      onClick={inc}
                                      disabled={selectedQty >= remainingQty}
                                      aria-label={`${t('increase')}: ${item.name}`}
                                      className="flex size-6 items-center justify-center rounded-full border border-neutral-300 text-neutral-600 transition-colors hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-30"
                                    >
                                      <Plus className="size-3" />
                                    </button>
                                  </div>
                                </div>
                              )}
                            </div>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>
              )}
            </div>

            {/* Store info */}
            {branch.name && (
              <div className="hidden rounded-xl border border-neutral-200 bg-white p-3 md:p-4">
                <div className="flex items-start gap-3 md:gap-4">
                  {branch.img_branches ? (
                    <div className="relative h-20 w-20 md:h-[132px] md:w-[132px] shrink-0 overflow-hidden rounded-lg">
                      {/* eslint-disable-next-line @next/next/no-img-element */}
                      <img
                        src={branch.img_branches}
                        alt={branch.name}
                        className="h-full w-full object-cover"
                      />
                    </div>
                  ) : branch.brand?.logo_url ? (
                    <div className="flex h-20 w-20 md:h-[132px] md:w-[132px] shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-white p-2">
                      {/* eslint-disable-next-line @next/next/no-img-element */}
                      <img
                        src={branch.brand.logo_url}
                        alt={branch.brand.name}
                        className="h-full w-full object-contain"
                      />
                    </div>
                  ) : (
                    <div className="flex h-20 w-20 md:h-[132px] md:w-[132px] shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                      <Store className="h-7 w-7 md:h-10 md:w-10" />
                    </div>
                  )}
                  <div className="min-w-0 flex-1">
                    {branch.brand?.name && (
                      <p className="truncate text-xs text-muted-foreground">{branch.brand.name}</p>
                    )}
                    <h2 className="break-words font-inter text-xl font-bold leading-[1.2] tracking-normal text-neutral-900 md:text-[30px] md:leading-[36px]">
                      {branch.name}
                    </h2>
                    <div className="mt-2 space-y-1.5 md:mt-3 md:space-y-2">
                      {branch.address && (
                        <p className="flex items-start gap-1.5 font-inter text-xs font-medium leading-relaxed tracking-normal text-neutral-600 md:text-sm md:leading-normal">
                          <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0 md:mt-1 md:h-4 md:w-4" />
                          <span className="min-w-0 flex-1 break-words">{branch.address}</span>
                        </p>
                      )}
                      {branch.phone && (
                        <p className="flex items-start gap-1.5 font-inter text-xs font-medium leading-relaxed tracking-normal text-neutral-600 md:text-sm md:leading-normal">
                          <Phone className="mt-0.5 h-3.5 w-3.5 shrink-0 md:mt-1 md:h-4 md:w-4" />
                          <span className="min-w-0 flex-1">{branch.phone}</span>
                        </p>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            )}

            {/* Method selector + (online) Payment info — gộp 2 section vào
                CÙNG 1 card theo yêu cầu.

                #3116 — phần VẼ bê nguyên từ Takeaway (`checkout-page.tsx` +
                `checkout-page-mobile.tsx`): MỘT danh sách radio phẳng ba lựa
                chọn, form Stripe mở ngay trong ô "thẻ", thay cho "hàng nút kênh
                + tab cổng" hai tầng của #1303.

                Chỉ cái vẽ đổi. Máy trạng thái vẫn hai tầng (`methodChoice` ×
                `onlineGateway`) và mọi khối bên dưới vẫn hỏi
                `dineInOnlineSurface()` — xem `paymentChoice` /
                `choosePaymentOption` ở trên. Đừng làm phẳng state cho khớp UI:
                cái tách hai tầng ra là vì `method === "online"` từng mang hai
                nghĩa cùng lúc, và một danh sách radio không làm nghĩa đó gộp
                lại. */}
            <div className="rounded-xl border border-neutral-200 bg-white p-4 md:p-6">
              <div className="mb-3 flex items-center gap-2 md:mb-4">
                <CreditCard className="size-4 shrink-0 text-neutral-600 md:size-5" />
                <h3 className="text-base font-semibold text-neutral-900 md:text-[20px]">
                  {tCheckout('paymentMethod')}
                </h3>
              </div>

              <RadioGroup
                value={paymentChoice}
                onValueChange={(next) => choosePaymentOption(next as PaymentOption)}
                className="gap-2"
              >
                {/* Thẻ — thân Stripe nằm TRONG ô, đúng như Takeaway. Ô là
                    `div` + onClick chứ không phải `<label>`: `RadioGroupItem`
                    của Base UI vẽ ra `<span role="radio">`, mà `<label>` chỉ
                    chuyển tiếp cú bấm cho phần tử labelable (input/button/…),
                    nên bọc label thì chỉ bấm trúng chấm tròn mới ăn. */}
                <div
                  onClick={() => choosePaymentOption("card")}
                  className={cn(
                    "flex flex-col rounded-lg px-3 transition-all md:rounded-xl md:px-4",
                    paymentChoice === "card"
                      ? "py-3"
                      : "h-[50px] justify-center md:h-[58px]",
                    paymentChoiceLocked && paymentChoice !== "card"
                      ? "cursor-not-allowed opacity-50"
                      : "cursor-pointer",
                  )}
                  style={paymentRowStyle("card")}
                >
                  <div className="flex w-full items-center gap-3">
                    {/* #3118 — `aria-labelledby` là BẮT BUỘC ở đây, không phải
                        trang trí. `RadioGroupItem` render ra `<span role="radio">`
                        (Base UI), mà `<span>` không phải phần tử labelable: bọc
                        `<label>` quanh nó vừa không chuyển tiếp cú bấm, vừa
                        KHÔNG cấp tên cho nó. Thiếu thuộc tính này thì trình đọc
                        màn hình đọc ra ba radio vô danh — trên đúng màn chọn
                        cách trả tiền. WCAG 2.1 SC 4.1.2 (Name, Role, Value). */}
                    <RadioGroupItem
                      value="card"
                      aria-labelledby={paymentOptionLabelId("card")}
                      disabled={paymentChoiceLocked && paymentChoice !== "card"}
                    />
                    <p
                      id={paymentOptionLabelId("card")}
                      className="flex-1 text-sm font-semibold md:text-base"
                    >
                      {tCheckout('creditCard')}
                    </p>
                  </div>

                  {/* #1303 — hold the space until the branch capability is
                      known. Not cosmetic: mounting the card form now and
                      swapping it for PayPay when the probe lands would tear
                      down Stripe Elements, and on a slow connection it would
                      tear down a card number the guest had already typed. The
                      probe always terminates — the hook falls back to
                      "undetermined" once its retry ladder is spent. */}
                  {onlineSurface.showProbeSpinner && (
                    <div className="mt-3 flex items-center justify-center py-4">
                      <Loader2 className="size-5 animate-spin text-neutral-400" />
                    </div>
                  )}

                  {payingByCard && (
                    <div className="mt-3" onClick={(e) => e.stopPropagation()}>
                      {stripePublishableKey && amountToPay > 0 ? (
                        <>
                          <StripeCardSection
                            ref={stripeCardRef}
                            amount={Math.round(amountToPay)}
                            // #815 — the Stripe Elements currency MUST match the
                            // PaymentIntent currency (branch priced currency), else
                            // Stripe.js throws at confirm. Was hardcoded "jpy".
                            currency={branch.currency_code ?? "JPY"}
                            publishableKey={stripePublishableKey}
                            // #1125 option B — this site used to omit the flag, so it
                            // always built card-only Elements. Harmless while async
                            // methods are OFF; the moment a branch enables them the
                            // intent is automatic and the confirm fails.
                            showMethodTabs={asyncMethodsEnabled}
                          />
                          {/* Câu này trước đây đứng dưới card; Takeaway đặt nó
                              ngay dưới form nên nó theo form vào đây. */}
                          <p className="mt-3 text-xs leading-relaxed text-neutral-500">
                            {t('cardEncryptedHint')}
                          </p>
                        </>
                      ) : !stripePublishableKey && !stripeConfigLoading ? (
                        <p className="text-xs text-red-500 md:text-sm">
                          {t('stripeNotConfigured')}
                        </p>
                      ) : null}
                    </div>
                  )}
                </div>

                {/* PayPay — nhãn + badge thương hiệu, một dòng gợi ý bên dưới
                    (chỗ ở mới của hộp `showPayPayIntro`). Ẩn khi đã mint: lúc
                    đó QR đã nằm trên màn, "bấm bên dưới để hiện mã" hết đúng. */}
                {payPayOptionShown && (
                  <div
                    onClick={() => choosePaymentOption("qr_pay")}
                    className={cn(
                      "flex flex-col justify-center rounded-lg px-3 transition-all md:rounded-xl md:px-4",
                      paypayMint === null
                        ? "py-2.5 md:py-3"
                        : "h-[50px] md:h-[58px]",
                      paymentChoiceLocked && paymentChoice !== "qr_pay"
                        ? "cursor-not-allowed opacity-50"
                        : "cursor-pointer",
                    )}
                    style={paymentRowStyle("qr_pay")}
                  >
                    <div className="flex w-full items-center gap-3">
                      <RadioGroupItem
                        value="qr_pay"
                        aria-labelledby={paymentOptionLabelId("qr_pay")}
                        disabled={paymentChoiceLocked && paymentChoice !== "qr_pay"}
                      />
                      <p
                        id={paymentOptionLabelId("qr_pay")}
                        className="flex-1 text-sm font-semibold md:text-base"
                      >
                        {t('paypay')}
                      </p>
                      <PayPayBrandIcon />
                    </div>
                    {paypayMint === null && (
                      <p className="mt-1 pl-7 text-xs leading-relaxed text-neutral-500">
                        {t('paypayTabHint')}
                      </p>
                    )}
                  </div>
                )}

                {/* #2806 — "thanh toán tại quầy" là cờ của chi nhánh. */}
                {counterPayOffered && (
                  <div
                    onClick={() => choosePaymentOption("counter")}
                    className={cn(
                      "flex h-[50px] items-center rounded-lg px-3 transition-all md:h-[58px] md:rounded-xl md:px-4",
                      paymentChoiceLocked && paymentChoice !== "counter"
                        ? "cursor-not-allowed opacity-50"
                        : "cursor-pointer",
                    )}
                    style={paymentRowStyle("counter")}
                  >
                    <div className="flex w-full items-center gap-3">
                      <RadioGroupItem
                        value="counter"
                        aria-labelledby={paymentOptionLabelId("counter")}
                        disabled={paymentChoiceLocked && paymentChoice !== "counter"}
                      />
                      <p
                        id={paymentOptionLabelId("counter")}
                        className="flex-1 text-sm font-semibold md:text-base"
                      >
                        {t('counter')}
                      </p>
                    </div>
                  </div>
                )}
              </RadioGroup>
            </div>

            {/* Payment mode selector (Full / Split) — only for online — hidden on mobile */}
            {payingOnline && (
              <div className="hidden rounded-xl border border-neutral-200 bg-white p-4 md:p-6">
                <h3 className="text-base font-bold text-neutral-900 md:text-lg">{t('paymentModeTitle')}</h3>
                <div className="mt-3 grid grid-cols-2 gap-3 md:mt-4 md:gap-4">
                  <button
                    type="button"
                    onClick={() => { setPaymentMode("full"); setSplitCount(""); setSplitPaidCount(0); setCustomAmount(""); setSelectedItems(new Map()); }}
                    className={`flex flex-col items-center justify-center gap-1 rounded-xl border px-3 py-3 text-center transition-colors md:py-4 ${paymentMode === "full"
                      ? "border-green-500 bg-green-50 text-green-700"
                      : "border-neutral-200 bg-white text-neutral-700 hover:bg-neutral-50"
                      }`}
                  >
                    <CreditCard className="size-5 md:size-6" />
                    <span className="text-[13px] font-medium md:text-sm">{t('payFull')}</span>
                    <span className="text-[11px] text-neutral-500 md:text-xs">{fmt(remaining)}</span>
                  </button>
                  <button
                    type="button"
                    onClick={() => setPaymentMode("split")}
                    className={`flex flex-col items-center justify-center gap-1 rounded-xl border px-3 py-3 text-center transition-colors md:py-4 ${paymentMode === "split"
                      ? "border-green-500 bg-green-50 text-green-700"
                      : "border-neutral-200 bg-white text-neutral-700 hover:bg-neutral-50"
                      }`}
                  >
                    <SplitSquareHorizontal className="size-5 md:size-6" />
                    <span className="text-[13px] font-medium md:text-sm">{t('paySplit')}</span>
                    <span className="text-[11px] text-neutral-500 md:text-xs">{t('paySplitDesc')}</span>
                  </button>
                </div>

                {/* Split options */}
                {paymentMode === "split" && (
                  <div className="mt-4 space-y-3">
                    {/* Split type tabs */}
                    <div className="flex rounded-lg border border-neutral-200 overflow-hidden">
                      {([
                        { key: "even" as const, label: t('splitByPeople') },
                        { key: "by_items" as const, label: t('splitByItems') },
                        { key: "by_amount" as const, label: t('splitCustom') },
                      ]).map(({ key, label }) => (
                        <button
                          key={key}
                          type="button"
                          onClick={() => {
                            setSplitType(key);
                            setSplitCount(""); setSplitPaidCount(0);
                            setCustomAmount(""); setSelectedItems(new Map());
                          }}
                          className={`flex-1 py-2 text-xs font-medium transition-colors ${splitType === key
                            ? "bg-green-600 text-white"
                            : "bg-white text-neutral-600 hover:bg-neutral-50"
                            }`}
                        >
                          {label}
                        </button>
                      ))}
                    </div>

                    {/* By people: counter + breakdown */}
                    {splitType === "even" && (
                      <>
                        {/* Counter row */}
                        <div className="flex items-center justify-between">
                          <span
                            style={{
                              fontWeight: 600,
                              fontSize: '18px',
                              lineHeight: '24px',
                            }}
                            className="text-neutral-700"
                          >
                            {t('splitCountLabel')}
                          </span>
                          <div
                            className="flex items-center gap-2 rounded-full px-2 py-1"
                            style={{
                              backgroundColor: '#F6F3F2',
                            }}
                          >
                            <button
                              type="button"
                              onClick={() => {
                                if (splitLocked) return;
                                const current = Number(splitCount) || 0;
                                if (current > 2) {
                                  setSplitCount(String(current - 1));
                                  setSplitPaidCount(0);
                                }
                              }}
                              aria-label={`${t('decrease')}: ${t('splitCountLabel')}`}
                              className="flex size-10 items-center justify-center rounded-full bg-neutral-200 text-neutral-600 transition-colors hover:bg-neutral-300 disabled:opacity-30"
                              disabled={splitLocked || numPeople <= 2}
                            >
                              <Minus className="size-4" />
                            </button>
                            <span className="min-w-[3ch] text-center text-2xl font-bold tabular-nums text-neutral-900">
                              {splitCount || "0"}
                            </span>
                            <button
                              type="button"
                              onClick={() => {
                                if (splitLocked) return;
                                const current = Number(splitCount) || 0;
                                setSplitCount(String(current + 1));
                                setSplitPaidCount(0);
                              }}
                              aria-label={`${t('increase')}: ${t('splitCountLabel')}`}
                              className="flex size-10 items-center justify-center rounded-full text-white transition-colors disabled:opacity-30"
                              style={{
                                backgroundColor: 'rgb(34, 197, 94)',
                              }}
                              disabled={splitLocked}
                            >
                              <Plus className="size-4" />
                            </button>
                          </div>
                        </div>

                        {/* Info card */}
                        {numPeople >= 2 && (
                          <div
                            className="rounded-lg p-4 space-y-1.5 text-center"
                            style={{
                              backgroundColor: '#F0F7F1',
                            }}
                          >
                            <div
                              style={{
                                fontWeight: 600,
                                fontSize: '18px',
                                lineHeight: '24px',
                                color: '#3F4940',
                              }}
                            >
                              {t('summaryTotalInvoice')}: {fmt(order.total)}
                            </div>
                            <div className="flex items-baseline justify-center gap-1">
                              <span className="text-2xl font-bold text-green-600 tabular-nums">
                                {fmt(splitByPeopleAmount)}
                              </span>
                              <span className="text-sm text-neutral-600">{t('perPersonSuffix')}</span>
                            </div>
                            <div className="text-sm font-medium text-neutral-700">
                              {t('splitGuestProgress', { current: splitGuestCurrent, total: numPeople })}
                            </div>
                          </div>
                        )}
                      </>
                    )}

                    {/* By items: card grid matching mockup */}
                    {splitType === "by_items" && (
                      <div className="space-y-3">
                        {/* Info banner */}
                        <div className="flex items-start gap-2.5 rounded-xl bg-[#2685491A] px-4 py-3 text-sm text-neutral-800">
                          <span
                            aria-hidden="true"
                            className="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full bg-[#268549] text-[11px] font-bold leading-none text-white"
                          >
                            i
                          </span>
                          <p className="leading-relaxed">
                            {t('splitByItemsBanner1')}
                            <span className="block md:inline md:before:content-['_']">
                              {t('splitByItemsBanner2')}
                            </span>
                          </p>
                        </div>

                        {/* Item cards */}
                        <div className="space-y-3">
                          {order.items.filter((item) => (item.status as string) !== "voided").map((item) => {
                            const selectedQty = selectedItems.get(item.id) ?? 0;
                            const isSelected = selectedQty > 0;
                            const unitWithOptions = item.qty > 0
                              ? item.subtotal / item.qty
                              : item.unit_price;

                            const inc = () => {
                              setSelectedItems((prev) => {
                                const next = new Map(prev);
                                const cur = next.get(item.id) ?? 0;
                                if (cur < item.qty) next.set(item.id, cur + 1);
                                return next;
                              });
                            };
                            const dec = () => {
                              setSelectedItems((prev) => {
                                const next = new Map(prev);
                                const cur = next.get(item.id) ?? 0;
                                if (cur <= 1) {
                                  next.delete(item.id);
                                } else {
                                  next.set(item.id, cur - 1);
                                }
                                return next;
                              });
                            };

                            return (
                              <div
                                key={item.id}
                                className={`rounded-xl border p-3 transition-colors ${isSelected
                                  ? "border-green-500 bg-[#F9FFFB] ring-1 ring-green-500"
                                  : "border-neutral-200 bg-white"
                                  }`}
                              >
                                <div className="flex gap-3">
                                  {/* Image */}
                                  <div className="relative size-20 shrink-0 overflow-hidden rounded-lg bg-neutral-100">
                                    {item.image_url ? (
                                      /* eslint-disable-next-line @next/next/no-img-element */
                                      <img
                                        src={item.image_url}
                                        alt={item.name}
                                        className="h-full w-full object-cover"
                                      />
                                    ) : (
                                      <div className="flex h-full w-full items-center justify-center text-xs text-neutral-400">
                                        —
                                      </div>
                                    )}
                                  </div>

                                  {/* Body */}
                                  <div className="min-w-0 flex-1">
                                    <div className="flex items-start justify-between gap-2">
                                      <h3 className="truncate text-base font-semibold text-neutral-900">
                                        {item.name}
                                      </h3>
                                      <span className="shrink-0 text-sm font-medium text-neutral-500">
                                        x{item.qty}
                                      </span>
                                    </div>

                                    {item.options && item.options.length > 0 && (
                                      <ul className="mt-1 space-y-0.5 text-xs text-neutral-500">
                                        {item.options.map((o) => (
                                          <li key={o.id}>
                                            + {o.name ?? "—"} ({fmt(o.unit_price)})
                                          </li>
                                        ))}
                                      </ul>
                                    )}

                                    {item.note && (
                                      <p className="mt-1 text-xs italic text-neutral-500">
                                        {t('itemNoteLabel', { note: item.note })}
                                      </p>
                                    )}

                                    <div className="mt-2 flex items-center justify-between">
                                      <span className="text-base font-bold text-neutral-900 tabular-nums">
                                        {fmt(unitWithOptions)}
                                      </span>
                                      <div className="flex items-center gap-2.5">
                                        <button
                                          type="button"
                                          onClick={dec}
                                          disabled={selectedQty === 0}
                                          aria-label={t('decrease')}
                                          className="flex size-7 items-center justify-center rounded-full border border-neutral-300 text-neutral-600 transition-colors hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-30"
                                        >
                                          <Minus className="size-3.5" />
                                        </button>
                                        <span className="min-w-[2.5ch] text-center text-base font-bold tabular-nums text-neutral-900">
                                          x{selectedQty}
                                        </span>
                                        <button
                                          type="button"
                                          onClick={inc}
                                          disabled={selectedQty >= item.qty}
                                          aria-label={t('increase')}
                                          className="flex size-7 items-center justify-center rounded-full border border-neutral-300 text-neutral-600 transition-colors hover:bg-neutral-50 disabled:cursor-not-allowed disabled:opacity-30"
                                        >
                                          <Plus className="size-3.5" />
                                        </button>
                                      </div>
                                    </div>
                                  </div>
                                </div>
                              </div>
                            );
                          })}
                        </div>

                        {selectedItems.size > 0 && (
                          <div className="flex items-center justify-between rounded-lg border border-green-100 bg-green-50 px-3 py-2">
                            <span className="text-sm text-neutral-600">
                              {t('selectedItemsTotal', { count: selectedItemsQuantity })}
                            </span>
                            <span className="text-base font-bold text-green-700 tabular-nums">
                              {fmt(splitByItemsAmount)}
                            </span>
                          </div>
                        )}
                      </div>
                    )}

                    {/* Custom: free-form amount input */}
                    {splitType === "by_amount" && (
                      <div className="space-y-2">
                        <label className="text-sm font-medium text-neutral-700" htmlFor="custom-amount">
                          {t('customAmountLabel', { remaining: fmt(remaining) })}
                        </label>
                        <div>
                          <input
                            id="custom-amount"
                            type="text"
                            inputMode="numeric"
                            pattern="[0-9]*"
                            value={customAmount}
                            onChange={(e) => {
                              const val = e.target.value.replace(/[^0-9]/g, "");
                              if (val === "") {
                                setCustomAmount("");
                                return;
                              }
                              const num = Number(val);
                              setCustomAmount(String(Math.min(num, remaining)));
                            }}
                            placeholder="0"
                            className="w-full rounded-lg border border-neutral-300 px-4 py-3 text-lg font-semibold tabular-nums text-neutral-900 placeholder:text-neutral-300 focus:border-green-500 focus:outline-none focus:ring-1 focus:ring-green-500"
                          />
                          <p className="mt-1 text-xs text-neutral-500 tabular-nums">
                            {fmt(Number(customAmount) || 0)}
                          </p>
                        </div>
                      </div>
                    )}
                  </div>
                )}
              </div>
            )}

            {/* #3116 — "Security hint" đứng rời ở đây đã chuyển vào trong ô
                "thẻ", ngay dưới form Stripe, theo bố cục Takeaway. Nó nói về
                form thẻ nên nó đi cùng form; để lại một bản thứ hai ở đây là
                nói cùng một câu hai lần. */}

            {/* #1296 — PayPay dynamic QR, inline. Not a route: this screen owns
                the order, the split selection and the settlement watcher, and it
                imports no router at all. Mounted only once the guest has pressed
                pay, so the code is minted for a share they have committed to
                rather than for whatever the spinner happened to read. */}
            {/* `paypayMint !== null` is redundant with `showQrPanel` at runtime
                — it is what makes that flag true — but the compiler cannot see
                through the flag to narrow the value the panel reads. */}
            {onlineSurface.showQrPanel && paypayMint !== null && (
              <div className="rounded-xl border border-neutral-200 bg-white p-4 md:p-6">
                <PayPayQrPanel
                  orderId={order.id}
                  orderCode={order.code}
                  amount={paypayMint.amount}
                  chargeAmount={paypayMint.amount}
                  splitPayload={paypayMint.split}
                  currency={branch.currency_code ?? "JPY"}
                  onPaid={syncOrderAfterPayPay}
                  onAbandoned={() => setPaypayOrphanedAtMs(Date.now())}
                />
                {/* #1737 — nút này chỉ gỡ panel. Việc HUỶ mã nằm trong cleanup
                    của `PayPayQrPanel`, vì nó phải phủ cả lối thoát của takeaway
                    (đổi radio phương thức) chứ không riêng nút này — gọi thêm ở
                    đây là gọi hai lần cho cùng một mã. Dòng chữ dưới đây nói
                    trước điều đó: takeaway đã có câu tương đương từ lâu, chỉ
                    khác là hồi ấy nó chưa đúng. */}
                <p className="mt-4 px-1 text-xs leading-relaxed text-neutral-500">
                  {tPayPay("changeAmountWarning")}
                </p>
                <button
                  type="button"
                  onClick={() => setPaypayMint(null)}
                  className="mt-2 w-full rounded-lg border border-neutral-300 bg-white px-4 py-2.5 text-sm font-medium text-neutral-700 transition-colors hover:bg-neutral-50"
                >
                  {t('paypayChangeAmount')}
                </button>
              </div>
            )}

            {/* Counter: QR for the cashier, unless the shop turned it off
                (#2806) — then the staff read the `#xxxx` code below, which is
                why that code stays in this block either way. */}
            {method === "counter" && (
              <div className="rounded-xl border border-neutral-200 bg-white p-5 flex flex-col items-center gap-4 md:p-6 md:gap-5">
                <p className="text-xs text-neutral-600 text-center leading-relaxed px-2 md:text-sm md:px-4">
                  {counterQrShown ? t('counterQrHint') : t('counterHint')}
                </p>
                {counterQrShown && (
                  <QRCodeSVG value={counterQr} size={200} fgColor="#000000" bgColor="#ffffff" />
                )}
                <div className="text-center space-y-0.5 md:space-y-1">
                  <p className="text-2xl font-extrabold text-green-600 tabular-nums tracking-tight md:text-3xl">{fmt(amountToPay)}</p>
                  <p className="text-sm font-semibold text-neutral-900 tabular-nums">{t('totalAmountInline', { amount: fmt(order.total) })}</p>
                  <p className="text-xs text-neutral-500">{t('tableLabel', { code: table.code })}</p>
                  <p className="text-xs text-neutral-500">#{shortOrderCode(order.code)}</p>
                </div>
              </div>
            )}


          </div>
          {/* /Main column */}

          {/* Sidebar — sticky order summary (visible on all screen sizes) */}
          <aside className="mt-4 lg:sticky lg:top-20 lg:mt-0">
            {orderSummary}
          </aside>
        </div>
      </main>

      {/* Mobile sticky footer — nút thanh toán ghim đáy màn hình + shadow trên */}
      {onlineSurface.showConfirmButton && (
        <div
          className="md:hidden sticky bottom-0 z-20 bg-white px-4 py-[18px] safe-area-bottom"
          style={{ boxShadow: '0px -6px 16px 0px #0000000D' }}
        >
          {confirmButton}
        </div>
      )}
    </div>
  );
}
