"use client";

import { useState, useRef, useEffect } from "react";
import { useRouter, Link } from "@/i18n/routing";
import { useTranslations } from 'next-intl';
import { useCart } from "@/context/cart-context";
import type { MenuCategory } from "@/data/menu";
import { useBrand } from "@/context/brand-context";
import { loginHref } from "@/lib/shop-routes";
import { useAuth } from "@/context/auth-context";
import { useGlobalLoading } from "@/context/loading-context";
import { ArrowLeft, User, CreditCard, Loader2, MessageSquare, X } from "lucide-react";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Button } from "@/components/ui/button";
import { CartItemOptionsList, buildCartOptionLines } from "@/components/cart-item-options";
import { RemoveItemConfirmDialog } from "@/components/remove-item-confirm-dialog";
import { useCurrency } from "@/lib/currency";
import { saveGuestOrder } from "@/lib/guest-orders";
import { deriveCountry, formatAsYouType, validatePhoneForCountry } from "@/lib/phone";
import { PickupTimeSelector } from "@/components/pickup-time-selector";
import { apiFetch, ApiError } from "@/lib/api";
import type { MergedMenuContext } from "@/lib/menu-item-match";
import { driftUpdatesFromError } from "@/lib/price-drift";
import { useStripeConfig } from "@/lib/stripe-config";
import { correctPaymentMethod, defaultPaymentMethod, shouldOfferCounterPay } from "@/lib/counter-pay";
import { paymentPolicyEcho, primePaymentPolicyContext } from "@/lib/payment-policy-context";
import { useAsyncPaymentMethods } from "@/hooks/use-async-payment-methods";
import { StripeCardSection, type StripeCardSectionHandle } from "@/components/stripe-card-section";
import { PayPayBrandIcon } from "@/components/payment-brand-icons";
import {
  findActiveCheckoutDraft,
  generateDraftCode,
  saveCheckoutDraft,
  type CheckoutDraft,
  type CheckoutDraftItem,
} from "@/lib/checkout-draft";
import { mapCartItemToppings } from "@/lib/cart-toppings";
import {
  activeCouponPreview,
  hasUnappliedCouponEdit,
  normalizeCouponCode,
  shouldPromptCouponApply,
} from "@/lib/checkout-coupon";
import { closingTimeLabel, isOpenAt } from "@/lib/opening-hours";
import { useCurrentBranchOpenState, useNextOpeningLabel } from "@/hooks/use-branch-open-state";
import { payPayPostOrderRoute, shouldShowPayPayCheckoutHint } from "@/lib/paypay-qr";
import { usePayPayAvailability } from "@/hooks/use-paypay-availability";
import { FEATURES } from "@/lib/feature-flags";
import { computeCartTax, currencyStep, resolveLineRate, roundStep } from "@/lib/tax";
import { TaxBreakdownLines } from "@/components/tax-breakdown-lines";
import Header from "@/components/Header";
import { CouponLoginPrompt } from "@/components/coupon-login-prompt";

const GUEST_CONTACT_KEY = "tempo:guest-contact";
// Match desktop checkout-page.tsx — accept quốc tế (`+` prefix tùy chọn),
// digits + space/dash/parens, length 9-20. Trước đây VN-only `/^0\d{9,10}$/`
// gây inconsistency mobile vs desktop.
const PHONE_REGEX = /^\+?[\d\s\-()]{9,20}$/;

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

interface GuestContact {
  name: string;
  phone: string;
  email: string;
}

function loadGuestContact(): GuestContact {
  if (typeof window === "undefined") return { name: "", phone: "", email: "" };
  try {
    const raw = window.localStorage.getItem(GUEST_CONTACT_KEY);
    if (!raw) return { name: "", phone: "", email: "" };
    const parsed = JSON.parse(raw) as Partial<GuestContact>;
    return {
      name: typeof parsed.name === "string" ? parsed.name : "",
      phone: typeof parsed.phone === "string" ? parsed.phone : "",
      email: typeof parsed.email === "string" ? parsed.email : "",
    };
  } catch {
    return { name: "", phone: "", email: "" };
  }
}

function saveGuestContact(contact: GuestContact) {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.setItem(GUEST_CONTACT_KEY, JSON.stringify(contact));
  } catch {
    // ignore
  }
}

export default function CheckoutPageMobile() {
  const router = useRouter();
  const { items, totalItems, totalPrice, removeFromCart, pickupTimeData, setPickupTimeData, clearCart, appliedCouponCode, setAppliedCouponCode, cartMetadata, reconcileCrossTimeItems, applyServerPrices } = useCart();
  // Gate the trash action behind a confirm dialog — never delete on first tap.
  const [removeTarget, setRemoveTarget] = useState<string | null>(null);
  const { currentBranch } = useBrand();

  // #367 — đồng bộ lại các field dịch được (name / description / topping
  // labels) theo locale đang dùng. Giỏ chụp `product` lúc thêm món, nên đổi
  // locale sau đó để lại chuỗi của locale cũ. Menu page vẫn reconcile bình
  // thường, nhưng checkout tới được mà không cần đi qua đó — deep-link, back,
  // hoặc mở lại giỏ đã persist — nên phải tự làm. Giữ song song với bản desktop.
  // Không cần dep `locale`: đổi locale remount cả cây [locale] nên effect chạy lại.
  useEffect(() => {
    if (!currentBranch.slug || items.length === 0) return;
    const ac = new AbortController();
    apiFetch<{
      data: {
        menu_id: string;
        menu_name: string;
        schedule_end_time: string | null;
        cart_timeout_minutes: number;
        categories: MenuCategory[];
        menus?: MergedMenuContext[];
      };
    }>(`/api/v1/customer/branches/${currentBranch.slug}/menu`, {
      signal: ac.signal,
      silent401: true,
    })
      .then(({ data }) => {
        if (ac.signal.aborted) return;
        reconcileCrossTimeItems({
          menuId: data.menu_id,
          menuName: data.menu_name,
          scheduleEndTime: data.schedule_end_time,
          cartTimeoutMinutes: data.cart_timeout_minutes,
          categories: data.categories,
          menus: data.menus,
        });
      })
      .catch(() => {
        // Best-effort: menu lỗi thì giữ nguyên tên đã lưu, không chặn checkout.
      });
    return () => ac.abort();
  }, [currentBranch.slug, items.length, reconcileCrossTimeItems]);

  const { isLoggedIn, user } = useAuth();
  const { showLoading } = useGlobalLoading();
  // Match desktop: takeaway-only mobile → khi đã login, không cần bắt user
  // nhập lại tên/SĐT (BE sẽ infer từ authenticated user). Khi guest, bắt
  // buộc nhập để BE biết người nhận hàng.
  const guestContactRequired = !isLoggedIn;
  const t = useTranslations('checkout');
  const tCommon = useTranslations('common');
  // #1160 — the closed-shop message lives with the picker's copy.
  const tp = useTranslations('pickup');
  const tShop = useTranslations('shop');
  const { format: fmt } = useCurrency();

  // #1167 — live "is the shop open right now?", for the ASAP guard below.
  const shopOpenState = useCurrentBranchOpenState();
  const reopenLabel = useNextOpeningLabel(shopOpenState.nextOpening);

  const [name, setName] = useState(() => loadGuestContact().name);
  const [phone, setPhone] = useState(() => loadGuestContact().phone);
  const [email, setEmail] = useState(() => loadGuestContact().email);

  // plan-035 — branch-aware phone country + effective policy for the
  // "must-pay-before-prep" banner + email required flag. Mirrors
  // checkout-page.tsx desktop.
  const branchCountry = currentBranch.effective_order_policy?.phone_country
    ?? deriveCountry(currentBranch.locale ?? null);
  const prepBeforePayment = currentBranch.effective_order_policy?.prep_before_payment ?? true;
  const emailRequired = currentBranch.effective_order_policy?.customer_email_required ?? false;
  // #2545 — `null` = KHÁCH CHƯA BẤM GÌ. Mirror desktop; lý do đầy đủ ở
  // `checkout-page.tsx` cùng chỗ. `paymentMethod` dẫn xuất bên dưới mới là
  // cái được đọc ở mọi nơi khác.
  const [paymentChoice, setPaymentMethod] = useState<string | null>(null);
  // Order-level note (free text) — mirror desktop. Mobile trước đây không
  // có UI cho field này → user không gửi được yêu cầu đặc biệt cho cả đơn
  // (vd: không bỏ hành, gói riêng tương ớt). Per-item note từ cart cũng
  // được forward trong `orderItems` mapping bên dưới.
  const [note, setNote] = useState("");
  const [couponCode, setCouponCode] = useState("");
  const [couponDebounced, setCouponDebounced] = useState("");
  // plan-019 — coupon preview state (mirrors desktop checkout-page.tsx).
  // Cho phép detect lỗi customer_required sớm để render CouponLoginPrompt.
  const [couponPreview, setCouponPreview] = useState<{
    data: {
      is_valid: boolean;
      discount_applied_amount?: number;
      error_code?: string;
      meta?: Record<string, unknown>;
    };
  } | null>(null);
  // plan-019 — "Use coupon instead of HH" opt-in. Mirror desktop:
  // khi preview trả `coupon_excluded_by_active_promotion`, mobile user có
  // thể tick checkbox để revert HH lines về original_unit_price → coupon
  // áp được. Trước đây mobile thiếu UI này → user gặp error → kẹt vì
  // không có cách dùng coupon.
  const [downgradePromos, setDowngradePromos] = useState(false);
  const [couponPending, setCouponPending] = useState(false);
  /**
   * #1763 — see the desktop build: a flag only. The prompt is derived from the
   * same predicate that blocks submit, and it is never copied into
   * `orderError`. Mobile rendered `orderError` unconditionally, so the old copy
   * showed the customer the SAME sentence twice at once — a red banner at the
   * top of the page and an amber box under the coupon field.
   */
  const [couponSubmitAttempted, setCouponSubmitAttempted] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [orderError, setOrderError] = useState<string | null>(null);
  const [contactErrors, setContactErrors] = useState<{ name?: string; phone?: string; email?: string }>({});
  // Anchor for the pickup-time section so we can scroll to it when the customer
  // is bounced back here to re-pick (see the effect below).
  const pickupSectionRef = useRef<HTMLElement>(null);

  // Recover from a pickup-time rejection bounced back from /order-confirm:
  // surface the reason right on the picker screen so the customer can choose a
  // new slot here — the pickup error stays on the order page instead of
  // dead-ending on a separate screen.
  useEffect(() => {
    if (typeof window === "undefined") return;
    if (new URLSearchParams(window.location.search).get("repick") === "pickup") {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setOrderError(t("pickupTimeExpired"));
      pickupSectionRef.current?.scrollIntoView({ behavior: "smooth", block: "center" });
    }
    // Mount-only: the flag is a one-shot hand-off from the confirm page.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Guard: nếu đang có draft chờ xác nhận → chuyển thẳng /order-confirm
  // để customer xác nhận hoặc huỷ trước khi đặt món/thanh toán mới.
  useEffect(() => {
    const active = findActiveCheckoutDraft();
    if (active) {
      router.replace(`/order-confirm/${active.id}`);
    }
  }, [router]);

  // Auto-fill coupon từ cart-context khi mount (sau khi user quay về từ
  // trang /login). Cart-context persist appliedCouponCode trong sessionStorage.
  //
  // #1763 — fire once per mount. Keyed on `!couponCode` it also fired when the
  // customer EMPTIED the field, refilling the code they had just deleted, which
  // is why an applied coupon could not be removed.
  const couponAutofilled = useRef(false);
  useEffect(() => {
    if (couponAutofilled.current || !appliedCouponCode) return;
    couponAutofilled.current = true;
    setCouponCode(appliedCouponCode);
    setCouponDebounced(normalizeCouponCode(appliedCouponCode));
  }, [appliedCouponCode]);

  // plan-019 — debounce + preview-API khi user submits coupon. Mirror logic
  // của desktop checkout-page.tsx để có cùng error surface (customer_required,
  // coupon_expired, …). Server vẫn re-validate ở order create.
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
    }>('/api/v1/customer/coupons/preview', {
      method: 'POST',
      body: JSON.stringify({
        code: couponDebounced,
        brand_id: currentBranch.brand.id,
        branch_id: currentBranch.id,
        // Forward customer_id khi đã login — thiếu field này thì server
        // treat như guest, trả `customer_required` dù FE đã đăng nhập.
        customer_id: user?.id,
        subtotal: totalPrice,
      }),
      silent401: true,
    })
      .then((res) => {
        if (!cancelled) setCouponPreview(res);
      })
      .catch((err) => {
        if (cancelled) return;
        if (err instanceof ApiError) {
          const body = err.body as { error_code?: string; meta?: Record<string, unknown> };
          setCouponPreview({
            data: {
              is_valid: false,
              error_code: body.error_code ?? 'generic',
              meta: body.meta,
            },
          });
        } else {
          setCouponPreview({
            data: { is_valid: false, error_code: 'generic' },
          });
        }
      })
      .finally(() => {
        if (!cancelled) setCouponPending(false);
      });
    return () => {
      cancelled = true;
    };
  }, [couponDebounced, currentBranch.id, currentBranch.brand?.id, totalPrice, user?.id]);

  // Khi preview hợp lệ → persist vào cart-context (sessionStorage) để giữ
  // qua điều hướng (vd: quay lại trang menu rồi vào lại checkout). Khi
  // preview không hợp lệ HOẶC user xoá input → clear khỏi sessionStorage.
  useEffect(() => {
    if (couponPreview?.data?.is_valid && couponDebounced) {
      setAppliedCouponCode(couponDebounced);
    } else if (!couponDebounced) {
      setAppliedCouponCode(null);
    }
  }, [couponPreview, couponDebounced, setAppliedCouponCode]);

  // #1160 — ETA = shop setting (phút/món) x TỔNG SỐ LƯỢNG, mirroring the
  // backend product (CustomerPickupService) instead of the old hardcoded
  // `15 + 2 x (số dòng - 1)`, which ignored quantity and could not be tuned.
  const prepMinutesPerItem = currentBranch.effective_order_policy?.prep_minutes_per_item ?? 5;
  const totalQuantity = items.reduce((sum, item) => sum + (item.quantity ?? 1), 0);
  const estimatedMinutes = prepMinutesPerItem * totalQuantity;

  // Tax + service charge từ ShopOrderSetting (qua currentBranch). Khớp công thức
  // backend CustomerOrderService::checkout — tax/service tính trên
  // (subtotal - discount), không phải subtotal raw. Server vẫn là nguồn sự thật.
  // #1763 — mirror of the desktop rule: the preview answers for
  // `couponDebounced`, so it is withdrawn as soon as the field shows something
  // else. Read `livePreview`, never the raw state.
  const livePreview = activeCouponPreview(couponPreview, couponCode, couponDebounced);
  const couponInForce = livePreview !== null;
  const showUnappliedWarning = shouldPromptCouponApply({
    input: couponCode,
    applied: couponDebounced,
    submitAttempted: couponSubmitAttempted,
  });

  /** Take the coupon off entirely — badge, discount and order payload. */
  const removeCoupon = () => {
    setCouponCode("");
    setCouponDebounced("");
    setCouponPreview(null);
    setDowngradePromos(false);
    setCouponSubmitAttempted(false);
    setAppliedCouponCode(null);
  };

  const couponDiscount = livePreview?.data?.is_valid
    ? (livePreview.data.discount_applied_amount ?? 0)
    : 0;
  const discountedSubtotal = Math.max(0, totalPrice - couponDiscount);
  const serviceRate = currentBranch.service_charge_rate ?? 0;
  // plan-043 — per-rate consumption tax from each cart line's effective rate
  // (mobile checkout is takeaway-only). 総額表示 mode leaves the tax inside the
  // listed prices; excluded mode adds it. Server remains authoritative.
  const pricesIncludeTax = currentBranch.prices_include_tax ?? false;
  const taxLines = items.map((item) => ({
    subtotal: item.unitPrice * item.quantity,
    rate: resolveLineRate(item.product, currentBranch),
  }));
  // #1425 — service charge first: its own tax rate feeds the same per-rate
  // grouping as an item rate (see computeCartTax).
  const serviceCharge = roundStep(
    (discountedSubtotal * serviceRate) / 100,
    currencyStep(currentBranch.currency_code),
  );
  const { rows: taxRows, taxTotal: taxAmount } = computeCartTax(taxLines, {
    discount: couponDiscount,
    pricesIncludeTax,
    currencyCode: currentBranch.currency_code,
    serviceCharge,
    serviceChargeTaxRate: currentBranch.service_charge_tax_rate ?? 0,
  });
  const finalTotal = Math.max(
    0,
    totalPrice - couponDiscount + (pricesIncludeTax ? 0 : taxAmount) + serviceCharge,
  );

  // Stripe integration
  // #1703 — see checkout-page.tsx: without `loading` this flashes a red
  // "card payment unavailable" on every mount, card being the default method.
  const { config: stripeConfig, loading: stripeConfigLoading } = useStripeConfig();
  const stripeCardRef = useRef<StripeCardSectionHandle>(null);

  // plan-048 T2.5 — prime the policy identity for the branch the order will be
  // charged under; echoed on the intent body so BE can log policy drift.
  const paymentPolicySlug = cartMetadata?.branch_slug || currentBranch.slug;
  useEffect(() => {
    primePaymentPolicyContext(paymentPolicySlug);
  }, [paymentPolicySlug]);

  // plan-054 — mirrors desktop. `paypayEnabled` upgrades the behaviour of the
  // `qr_pay` radio; it never decides whether that radio exists, and it changes
  // nothing on screen (§10.2). Mobile checkout is takeaway-only.
  const {
    paypayEnabled,
    loading: paypayLoading,
    counter: counterPaySettings,
  } = usePayPayAvailability(paymentPolicySlug);

  // MỘT nguồn cho câu hỏi "chọn sẵn cái gì" — xem chú thích cùng chỗ ở
  // `checkout-page.tsx`.
  const gatewayAvailability = {
    stripeReady: Boolean(stripeConfig?.publishable_key),
    stripeLoading: stripeConfigLoading,
    paypayEnabled,
    paypayLoading,
  };
  // #2806 — mirrors desktop: cờ của chi nhánh, không suy ra từ cổng.
  const counterPayOffered = shouldOfferCounterPay(counterPaySettings);

  // Dẫn xuất, không phải effect ghi lại state — xem chú thích cùng chỗ ở
  // `checkout-page.tsx`.
  const paymentMethod = correctPaymentMethod(
    paymentChoice ?? defaultPaymentMethod(gatewayAvailability),
    counterPayOffered,
  );

  // #1125 option B — also decides the Elements payment-method configuration,
  // which must match how the backend created the PaymentIntent.
  const asyncMethodsEnabled = useAsyncPaymentMethods(paymentPolicySlug);

  // …but it does change one thing: when the QR flow really is what follows,
  // the radio says so. Same predicate as the post-order route below, so the
  // sub-copy can never describe a screen this customer is not sent to.
  const showPayPayQrHint = shouldShowPayPayCheckoutHint({
    paypayEnabled,
    orderType: "takeaway",
  });

  // Track order đã tạo nhưng CHƯA payment-confirm thành công. Khi user
  // click Pay → tạo order → Stripe confirm fail → user click Pay lại →
  // trước đây sẽ tạo order THỨ 2 (duplicate). Ref này cho phép tái sử
  // dụng order đầu tiên. Clear ref khi cart/coupon thay đổi (order BE đã
  // lock theo nội dung cũ).
  const pendingOrderRef = useRef<{ id: string; code: string } | null>(null);

  // Idempotency-Key cho POST /orders — BE (nếu hỗ trợ) dedupe theo key
  // khi FE retry sau network timeout. Cùng vòng đời với pendingOrderRef.
  const idempotencyKeyRef = useRef<string | null>(null);

  // Synchronous re-entrancy guard cho handleSubmit. `setIsSubmitting(true)`
  // có thể bị React batch + delay vài frame → user click 2 lần nhanh →
  // duplicate POST /orders. Ref set ngay đầu function trước await đầu
  // tiên → đồng bộ → click thứ 2 bị block ngay.
  const submitGuardRef = useRef(false);

  useEffect(() => {
    pendingOrderRef.current = null;
    idempotencyKeyRef.current = null;
  }, [
    items.map((i) => `${i.id}:${i.quantity}:${i.note ?? ""}`).join("|"),
    couponDebounced,
    downgradePromos,
  ]);

  const validateGuestContact = (n: string, p: string, e: string) => {
    const errs: { name?: string; phone?: string; email?: string } = {};
    if (!n.trim()) {
      errs.name = t('nameRequired');
    }
    const phoneResult = validatePhoneForCountry(p, branchCountry);
    if (!phoneResult.valid) {
      errs.phone = t(phoneResult.errorKey ?? 'phoneInvalid', { country: branchCountry });
    }
    const trimmedEmail = e.trim();
    if (emailRequired && !trimmedEmail) {
      errs.email = t('emailRequired');
    } else if (trimmedEmail && !EMAIL_REGEX.test(trimmedEmail)) {
      errs.email = t('emailInvalid');
    }
    return errs;
  };

  const handleSubmit = async () => {
    if (submitGuardRef.current) {
      return;
    }
    submitGuardRef.current = true;
    try {
    // Validate pickup time
    if (pickupTimeData.pickup_type === "scheduled") {
      if (!pickupTimeData.scheduled_pickup_time) {
        setOrderError(t('selectPickupTime'));
        return;
      }
      if (new Date(pickupTimeData.scheduled_pickup_time).getTime() <= Date.now()) {
        setOrderError(t('selectPickupTime'));
        return;
      }
      // #1160 — shop is shut at that moment (server refuses it too with 422
      // PICKUP_OUTSIDE_OPENING_HOURS; this saves the round trip).
      if (!isOpenAt(
        currentBranch.weekly_hours,
        new Date(pickupTimeData.scheduled_pickup_time),
        currentBranch.timezone,
      )) {
        const closesAt = closingTimeLabel(
          currentBranch.weekly_hours,
          new Date(pickupTimeData.scheduled_pickup_time),
          currentBranch.timezone,
        );
        setOrderError(closesAt
          ? tp('outsideOpeningHoursWithClosing', { time: closesAt })
          : tp('outsideOpeningHours'));
        return;
      }
    } else if (!shopOpenState.isOpen) {
      // #1167 — "prepare it now" at a shut shop (this screen is take-away
      // only). Mirrors the server's 422 BRANCH_CLOSED; a scheduled pre-order
      // is still allowed by the branch above.
      setOrderError(reopenLabel
        ? tShop('closedCheckoutErrorWithTime', { when: reopenLabel })
        : tShop('closedCheckoutError'));
      return;
    }

    // Check for unapplied coupon. #1763 — the prompt lives under the coupon
    // field only. Copying it into `orderError` as well put the same sentence on
    // screen twice here, and left the red one standing after Apply was pressed.
    if (hasUnappliedCouponEdit(couponCode, couponDebounced)) {
      setCouponSubmitAttempted(true);
      setOrderError(null);
      return;
    }
    setCouponSubmitAttempted(false);

    // Validate guest contact CHỈ khi guest (chưa login). Logged-in user
    // không cần nhập — BE infer tên/SĐT từ authenticated user.
    if (guestContactRequired) {
      const errs = validateGuestContact(name, phone, email);
      setContactErrors(errs);
      if (Object.keys(errs).length > 0) {
        setOrderError(t('checkCustomerInfo'));
        // Return the customer to the first empty/invalid field instead of just
        // flashing an error at the bottom — scroll it into view + focus it.
        const firstErrorId = errs.name ? "name" : errs.phone ? "phone" : "email";
        const el = typeof document !== "undefined" ? document.getElementById(firstErrorId) : null;
        el?.scrollIntoView({ behavior: "smooth", block: "center" });
        el?.focus({ preventScroll: true });
        return;
      }
    }

    // Map items to order format
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
      // Forward per-item note (vd: "Không hành", "Ít cay") từ cart context
      // sang BE. BE đã accept `items.*.note` trong CustomerOrderStoreRequest.
      // `undefined` để JSON.stringify drop key khi không có note.
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
      setOrderError(t('invalidItem'));
      return;
    }

    // Prefer `cartMetadata.branch_slug` (branch nơi user đã add item) hơn
    // `currentBranch.slug` (branch hiện đang display). Tránh case user
    // browse sang branch khác trước khi vào /checkout → order post sai branch.
    const takeawaySlug = cartMetadata?.branch_slug || currentBranch.slug;
    const endpoint = `/api/v1/customer/branches/${takeawaySlug}/orders`;

    // Takeaway + thanh toán tại quầy: review step trong /order-confirm.
    // Draft sống trong localStorage, BE chưa biết gì. POST /orders sẽ
    // được gọi khi customer commit (handleConfirm trong /order-confirm).
    if (paymentMethod === "counter") {
      const draftId =
        typeof crypto !== "undefined" && typeof crypto.randomUUID === "function"
          ? crypto.randomUUID()
          : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
      const confirmationTimeoutMinutes =
        currentBranch.effective_order_policy?.confirmation_timeout_minutes ?? 3;
      const now = new Date();
      const draftItems: CheckoutDraftItem[] = items.map((item, idx) => {
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
        // Display lines for selected options + toppings (with names), persisted
        // so /order-confirm can render them (#435). `.label`, not `.value` —
        // CartOptionLine has no `value`, so `variant` was always undefined.
        const optionLines = buildCartOptionLines(item);
        const variantStr = optionLines.map((l) => l.label).join(", ") || undefined;
        const toppings = mapCartItemToppings(item);
        return {
          id: `${draftId}-i${idx}`,
          product_sku_id: resolvedSkuId ?? "",
          name: item.product.name,
          variant: variantStr,
          qty: item.quantity,
          unit_price: item.unitPrice,
          subtotal: item.unitPrice * item.quantity,
          image_url: item.product.image ?? undefined,
          ...(optionLines.length > 0 ? { option_lines: optionLines } : {}),
          ...(toppings.length > 0 ? { toppings } : {}),
          // #1768 — persist per-item note vào draft; commit step (/order-confirm)
          // sẽ forward vào POST /orders và render lại cho khách kiểm. Đường
          // POST thẳng của mobile (paymentMethod !== "counter") đã forward
          // note; đường counter-pay thì trước đây bỏ quên và bếp không nhận.
          note: item.note?.trim() || undefined,
        };
      });
      const draft: CheckoutDraft = {
        id: draftId,
        code: generateDraftCode(),
        shop_slug: takeawaySlug!,
        items: draftItems,
        customer_name: name || undefined,
        customer_phone: phone || undefined,
        customer_email: email || undefined,
        note: note.trim() || undefined,
        payment_method: "counter",
        coupon_code:
          (livePreview?.data?.is_valid || (downgradePromos && couponInForce)) && couponDebounced
            ? couponDebounced
            : undefined,
        downgrade_promos: downgradePromos || undefined,
        pickup_type: pickupTimeData.pickup_type,
        // Only send a customer-chosen time. For "immediate" this is null and
        // the backend defaults it to estimated_ready_time (server time) — the FE
        // must NOT fill a naive-local time (BE validates `after:now` in the app
        // timezone; a behind-the-app-timezone client would be rejected).
        scheduled_pickup_time: pickupTimeData.scheduled_pickup_time || undefined,
        // #39 — snapshot the same money breakdown the checkout summary just
        // showed, so /order-confirm can display tax/service and a total that
        // matches what the customer actually pays (it used to show only the
        // pre-tax merchandise sum).
        subtotal: draftItems.reduce((s, it) => s + it.subtotal, 0),
        discount_amount: couponDiscount || undefined,
        tax_amount: taxAmount,
        tax_breakdown: taxRows,
        prices_include_tax: pricesIncludeTax,
        service_charge: serviceCharge || undefined,
        service_charge_rate: serviceRate || undefined,
        total: finalTotal,
        currency_code: currentBranch.currency_code ?? "JPY",
        created_at: now.toISOString(),
        expires_at: new Date(
          now.getTime() + confirmationTimeoutMinutes * 60 * 1000,
        ).toISOString(),
      };
      const ok = saveCheckoutDraft(draft);
      if (!ok) {
        setOrderError(t("orderFailed"));
        return;
      }
      showLoading();
      router.push(`/order-confirm/${draftId}`);
      return;
    }

    setIsSubmitting(true);
    setOrderError(null);
    try {
      // STEP 1: Validate Stripe card if payment method is "card"
      if (paymentMethod === "card") {
        if (!stripeCardRef.current) {
          console.error('[Checkout Mobile] Stripe ref is null!');
          setOrderError(t('stripeNotReady'));
          setIsSubmitting(false);
          return;
        }
        const { error: validateError } = await stripeCardRef.current.validate();
        if (validateError) {
          console.error('[Checkout Mobile] Stripe validation failed:', validateError);
          setOrderError(validateError);
          setIsSubmitting(false);
          return;
        }
      }

      // STEP 2: Create order — hoặc tái sử dụng order từ lần Pay trước
      // nếu user retry sau Stripe fail. `pendingOrderRef` được clear khi
      // cart/coupon thay đổi, nên reuse là an toàn.
      let res: { data: { id: string; code: string } };
      if (pendingOrderRef.current) {
        res = { data: pendingOrderRef.current };
      } else {
        // Sinh idempotency key lần đầu của attempt hiện tại. Nếu network
        // timeout → user retry → cùng key → BE dedupe (nếu BE hỗ trợ).
        if (!idempotencyKeyRef.current) {
          idempotencyKeyRef.current =
            typeof crypto !== "undefined" && typeof crypto.randomUUID === "function"
              ? crypto.randomUUID()
              : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
        }
        res = await apiFetch<{ data: { id: string; code: string } }>(endpoint, {
          method: "POST",
          headers: { "Idempotency-Key": idempotencyKeyRef.current },
          body: JSON.stringify({
            items: orderItems,
            // `|| undefined` để JSON.stringify drop khoá khi rỗng (vd: đã login,
            // không nhập form) → BE infer từ authenticated user thay vì
            // override bằng chuỗi rỗng.
            customer_takeaway_name: name.trim() || undefined,
            customer_takeaway_phone: phone.trim() || undefined,
            customer_takeaway_email: email.trim() || undefined,
            note: note.trim() || undefined,
            payment_method: paymentMethod,
            // Forward coupon khi preview valid HOẶC user đã opt-in
            // downgradePromos (server sẽ revert HH lines + re-validate coupon
            // trong cùng transaction). Guest customer_required vẫn bị block
            // bởi server-side validation, không cần FE filter thêm.
            coupon_code:
              (livePreview?.data?.is_valid || (downgradePromos && couponInForce)) && couponDebounced
                ? couponDebounced
                : undefined,
            downgrade_exclusive_promotions: downgradePromos || undefined,
            pickup_type: pickupTimeData.pickup_type,
            scheduled_pickup_time: pickupTimeData.scheduled_pickup_time || undefined,
          }),
        });
        pendingOrderRef.current = { id: res.data.id, code: res.data.code };
      }

      const orderId = res.data.id;
      const orderCode = res.data.code;

      // STEP 3: If card payment, create PaymentIntent and confirm
      // #1125 option B — set when an async method left the intent awaiting.
      let asyncAwaitingPayment = false;

      if (paymentMethod === "card") {
        // Create PaymentIntent from backend
        const intentRes = await apiFetch<{
          data: { client_secret: string; payment_intent_id: string };
        }>(`/api/v1/customer/orders/${orderId}/full-payment-intent`, {
          method: "POST",
          body: JSON.stringify(paymentPolicyEcho(paymentPolicySlug)),
        });

        // shop slug PHẢI khớp với branch nơi user đặt món (`takeawaySlug`),
        // không phải branch hiện đang display (`currentBranch.slug`). Order
        // đã tạo ở `takeawaySlug` qua endpoint /branches/{slug}/orders.
        const returnUrl = `${window.location.origin}/order-success?id=${orderId}&code=${orderCode}&type=takeaway&shop=${takeawaySlug}&stripe_return=1`;

        // Confirm with the EXACT client_secret from backend
        const confirmRes = await stripeCardRef.current?.confirm(
          intentRes.data.client_secret,
          returnUrl,
        );

        if (!confirmRes?.succeeded && !confirmRes?.pending) {
          setOrderError(confirmRes?.error ?? t('paymentFailed'));
          setIsSubmitting(false);
          return;
        }

        if (confirmRes?.pending) {
          // #1125 option B — async method awaiting settlement (voucher / bank
          // transfer). Route to the order detail page instead of success.
          asyncAwaitingPayment = true;
        }

        // Mark the order paid server-side immediately. This is what makes
        // admin show "paid" without anyone running `stripe listen` — the
        // webhook stays as a backup. Non-blocking: the charge already
        // succeeded at Stripe, so a failure here is reconciled by the webhook.
        try {
          await apiFetch(`/api/v1/customer/orders/${orderId}/confirm-payment`, {
            method: "POST",
            body: JSON.stringify({ payment_intent_id: intentRes.data.payment_intent_id }),
          });
        } catch (syncErr) {
          console.warn("[Stripe] confirm-payment sync failed; webhook will reconcile", syncErr);
        }
      }

      // STEP 4: Success - save contact, clear cart, navigate.
      // Order đã chốt → clear cả pendingOrderRef + idempotencyKeyRef để
      // lần checkout kế tiếp là fresh, không reuse order đã thanh toán.
      pendingOrderRef.current = null;
      idempotencyKeyRef.current = null;

      // Persist order pointer cho guest takeaway → /orders page đọc lại
      // sau khi reload tab. Logged-in user có BE /me/orders rồi, skip.
      // TTL 3 ngày, prune tự động.
      if (!isLoggedIn) {
        saveGuestOrder({
          id: orderId,
          code: orderCode,
          shop: takeawaySlug,
        });
      }

      saveGuestContact({ name: name.trim(), phone: phone.trim(), email: email.trim() });
      clearCart();

      // plan-054 — PayPay upgrade, mirroring desktop. `null` = unchanged, so
      // an unconfigured branch still lands on /order-success exactly as today.
      // A guest reaches the screen on the pointer `saveGuestOrder` just wrote;
      // a signed-in customer gets no pointer (above) and does not need one —
      // /orders/[id]/pay stopped requiring it in #1452, and reads the branch
      // slug off the order payload rather than the pointer (#1692).
      const paypayRoute = payPayPostOrderRoute({
        paypayEnabled,
        paymentMethod,
        orderType: "takeaway",
        orderId,
      });
      if (paypayRoute) {
        showLoading();
        router.push(paypayRoute);
        return;
      }

      // `takeawaySlug` — branch nơi đặt món thật sự, không phải branch
      // hiện đang browse. Xem comment ở khúc `takeawaySlug` declare.
      const successUrl = asyncAwaitingPayment
        // #1125 — awaiting async settlement: the order detail page shows live
        // payment state and flips when the webhook lands.
        ? `/orders/${orderId}?awaiting_payment=1`
        : `/order-success?id=${orderId}&code=${orderCode}&type=takeaway&shop=${takeawaySlug}${paymentMethod === "card" ? "&stripe_return=1" : ""}`;
      // Global overlay bridges the checkout → /order-success navigation
      // gap (auto-dismissed on pathname change in LoadingProvider).
      showLoading();
      router.push(successUrl);
      return;
    } catch (err) {
      // #1715 — xem checkout-page.tsx: 409 trôi giá thì sửa giỏ rồi để bấm lại.
      const repriced = driftUpdatesFromError(err, items.map((i) => i.id));
      if (repriced) {
        applyServerPrices(repriced);
        setOrderError(t('priceChangedBody'));
        setIsSubmitting(false);
        return;
      }
      console.error("[Checkout Mobile] Order failed:", err);
      console.error("[Checkout Mobile] Error details:", {
        type: err instanceof ApiError ? 'ApiError' : typeof err,
        status: err instanceof ApiError ? err.status : 'N/A',
        message: err instanceof Error ? err.message : String(err),
        full: err,
      });
      const msg = err instanceof ApiError
        ? t('apiError', { status: err.status, message: err.message })
        : t('orderFailed');
      setOrderError(msg);
      setIsSubmitting(false);
    }
    } finally {
      // Release re-entrancy guard cho mọi exit path (early returns +
      // success + thrown). `return` từ inner try vẫn trigger finally này.
      submitGuardRef.current = false;
    }
  };

  // Redirect if cart is empty
  if (items.length === 0) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center p-4 text-center">
        <div className="text-6xl mb-4">🛒</div>
        <h2 className="text-lg font-bold text-neutral-900 mb-2">{t('emptyCart')}</h2>
        <p className="text-sm text-muted-foreground mb-6">{t('emptyCartDesc')}</p>
        <Button onClick={() => router.back()} className="bg-emerald-600 hover:bg-emerald-700">
          {t('backToMenu')}
        </Button>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-white">
      {/* Global brand header (VIET ORIGIN + đăng nhập + lang) — khớp Figma */}
      {/* hideShadow: ẩn shadow-sm bên dưới global header ở mobile để
          không có gạch ngang giữa header và sub-header "Thanh toán".
          Desktop vẫn giữ shadow vì prop chỉ apply `md:shadow-sm`. */}
      <Header hideSwitcher showLogo hideOrderCta hideShadow hideRegister />

      {/* Sub-header: ← Thanh toán (sticky dưới global header h-12 = 48px) */}
      <header className="sticky top-12 z-30 flex items-center border-b bg-white px-4 py-3">
        <div className="flex items-center gap-2">
          <button onClick={() => router.back()} className="flex size-8 items-center justify-center -ml-1" aria-label={tCommon('back')}>
            <ArrowLeft className="size-5" />
          </button>
          <h1 className="text-base font-bold">{t('headerTitle')}</h1>
        </div>
      </header>

      {/* Content */}
      <main className="space-y-3 pb-40 overflow-x-clip" style={{ paddingTop: '12px', paddingLeft: '12px', paddingRight: '8px' }}>
        {/* Error Alert */}
        {orderError && (
          <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900">
            {orderError}
          </div>
        )}

        {/* Pickup Time Section */}
        <section ref={pickupSectionRef} className="rounded-xl border border-neutral-200 bg-white p-3 pr-2">
          <PickupTimeSelector
            value={pickupTimeData}
            onChange={setPickupTimeData}
            estimatedMinutes={estimatedMinutes}
            // #1160 — mobile used to pass neither window, so it silently fell
            // back to the 3'/15' defaults and could show a different earliest
            // slot than desktop at the same shop.
            confirmationTimeoutMinutes={
              currentBranch.effective_order_policy?.confirmation_timeout_minutes ?? 3
            }
            paymentTimeoutMinutes={
              currentBranch.effective_order_policy?.payment_timeout_minutes ?? 15
            }
            prepBeforePayment={prepBeforePayment}
            weeklyHours={currentBranch.weekly_hours}
            branchTimeZone={currentBranch.timezone}
          />
        </section>

        {/* Customer Info Section — logged-in: hiện card user (name+email).
            Guest: hiện form name/phone bắt buộc + link đăng nhập. */}
        <section className="rounded-xl border border-neutral-200 bg-white p-3">
          <div className="mb-3 flex items-center gap-2">
            <User className="size-4 text-neutral-600 shrink-0" />
            <h3 className="text-base font-semibold text-neutral-900">{t('customerInfoTitle')}</h3>
          </div>

          {isLoggedIn ? (
            <div className="flex items-center gap-3 rounded-xl border-2 border-primary/30 bg-primary/5 px-3 py-2.5">
              <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10">
                <User className="h-4 w-4 text-primary" />
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-bold text-primary truncate">{user?.name}</p>
                <p className="text-xs text-muted-foreground truncate">{user?.email}</p>
              </div>
            </div>
          ) : (
            <>
              {/* Hint + CTA đăng nhập — FEATURES.auth off (#47) hoặc thôi mời
                  đăng nhập (`authEntryPoints`) → chỉ giữ hint. */}
              <p className="text-xs text-muted-foreground leading-relaxed mb-3">
                {t('customerInfoHint')}
                {FEATURES.auth && FEATURES.authEntryPoints && (
                  <>
                    {' '}
                    <Link href={loginHref(currentBranch.slug)} className="text-primary font-medium hover:underline">
                      {t('loginCta')}
                    </Link>
                  </>
                )}
              </p>

              <div className="space-y-3">
                <div>
                  <Label htmlFor="name" className="text-sm text-neutral-900">
                    {t('fullName')} <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="name"
                    value={name}
                    onChange={(e) => {
                      setName(e.target.value);
                      if (contactErrors.name) setContactErrors({ ...contactErrors, name: undefined });
                    }}
                    placeholder={t('fullNamePlaceholder')}
                    className="mt-1.5 h-10"
                  />
                  {contactErrors.name && (
                    <p className="mt-1 text-xs text-destructive">{contactErrors.name}</p>
                  )}
                </div>
                <div>
                  <Label htmlFor="phone" className="text-sm text-neutral-900">
                    {t('phone')} <span className="ml-1 text-muted-foreground">({branchCountry})</span> <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="phone"
                    type="tel"
                    inputMode="tel"
                    value={phone}
                    onChange={(e) => {
                      // plan-035 — format-as-you-type for the branch's country
                      setPhone(formatAsYouType(e.target.value, branchCountry));
                      if (contactErrors.phone) setContactErrors({ ...contactErrors, phone: undefined });
                    }}
                    placeholder="0336909454"
                    className="mt-1.5 h-10 text-base"
                  />
                  {contactErrors.phone && (
                    <p className="mt-1 text-xs text-destructive">{contactErrors.phone}</p>
                  )}
                </div>
                <div>
                  <Label htmlFor="email" className="text-sm text-neutral-900">
                    {t('emailLabel')}{emailRequired && <span className="text-destructive"> *</span>}
                  </Label>
                  <Input
                    id="email"
                    type="email"
                    inputMode="email"
                    value={email}
                    onChange={(e) => {
                      setEmail(e.target.value);
                      if (contactErrors.email) setContactErrors({ ...contactErrors, email: undefined });
                    }}
                    placeholder={t('enterEmail')}
                    autoComplete="email"
                    className="mt-1.5 h-10 text-base"
                  />
                  <p className="mt-1 text-[11px] text-muted-foreground">{t('emailHelp')}</p>
                  {contactErrors.email && (
                    <p className="mt-1 text-xs text-destructive">{contactErrors.email}</p>
                  )}
                </div>
                {prepBeforePayment && (
                  <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    {t('prepAfterPaymentNotice')}
                  </div>
                )}
              </div>
            </>
          )}
        </section>

        {/* Order Note Section — mirror desktop (Ghi chú đơn hàng). Cho phép
            user gửi yêu cầu chung cho cả đơn (vd: "Không gia vị", "Để bàn 5"). */}
        <section className="rounded-xl border border-neutral-200 bg-white p-3">
          <div className="mb-3 flex items-center gap-2 text-base font-semibold text-neutral-900">
            <MessageSquare className="size-4 text-neutral-600" />
            <span>{t('note')}</span>
          </div>
          <Textarea
            placeholder={t('notePlaceholder')}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            rows={3}
            className="min-h-[80px]"
          />
        </section>

        {/* Payment Method Section */}
        <section className="rounded-xl border border-neutral-200 bg-white p-3">
          <div className="mb-3 flex items-center gap-2 text-base font-semibold text-neutral-900">
            <CreditCard className="size-4 text-neutral-600" />
            <span>{t('paymentMethod')}</span>
          </div>
          <RadioGroup value={paymentMethod} onValueChange={setPaymentMethod}>
            <div className="space-y-2">
              {/* Card Payment Option */}
              <label
                className="flex flex-col cursor-pointer rounded-lg px-3 transition-all"
                style={{
                  backgroundColor: paymentMethod === "card" ? "#2D8A390D" : "transparent",
                  border: paymentMethod === "card" ? "1px solid #2D8A39" : "1px solid #E5E7EB",
                  paddingTop: paymentMethod === "card" ? "12px" : "0",
                  paddingBottom: paymentMethod === "card" ? "12px" : "0",
                  minHeight: paymentMethod === "card" ? "auto" : "50px",
                }}
              >
                <div className="flex items-center space-x-2" style={{ height: paymentMethod === "card" ? "auto" : "50px" }}>
                  <RadioGroupItem value="card" id="payment-card" />
                  <Label htmlFor="payment-card" className="flex-1 cursor-pointer font-medium">
                    {t('creditCard')}
                  </Label>
                </div>

                {/* Stripe Form - shows when card is selected */}
                {paymentMethod === "card" && stripeConfig?.publishable_key && (
                  <div onClick={(e) => e.preventDefault()} className="mt-3">
                    <StripeCardSection
                      ref={stripeCardRef}
                      amount={Math.max(1, Math.round(finalTotal))}
                      currency={currentBranch.currency_code ?? "JPY"} // #815 — match PI currency (was stripeConfig.currency)
                      publishableKey={stripeConfig.publishable_key}
                      // #2790 — Stripe.js refuses to confirm without these; the
                      // Element opts out of collecting them.
                      billingDetails={{ name, email, phone }}
                      // #1125 option B — Konbini/銀行振込 tabs when enabled.
                      showMethodTabs={asyncMethodsEnabled}
                    />
                    <p className="mt-3 text-xs text-neutral-500">
                      {t('cardSecuredByStripe')}
                    </p>
                  </div>
                )}
                {paymentMethod === "card" && !stripeConfig?.publishable_key && !stripeConfigLoading && (
                  <p className="mt-2 text-xs text-destructive">
                    {t('stripeNotConfigured')}
                  </p>
                )}
              </label>

              {/* PayPay Option — value="qr_pay" để match BE enum
                  (CustomerOrderStoreRequest: in:counter,transfer,call_staff,qr_pay,card).
                  Trước đây dùng "paypal" → BE 422 reject. */}
              <div
                className="flex flex-col justify-center rounded-lg px-3"
                style={{
                  backgroundColor: paymentMethod === "qr_pay" ? "#2D8A390D" : "transparent",
                  border: paymentMethod === "qr_pay" ? "1px solid #2D8A39" : "1px solid #E5E7EB",
                  ...(showPayPayQrHint
                    ? { paddingTop: "10px", paddingBottom: "10px" }
                    : { height: "50px" }),
                }}
              >
                <div className="flex items-center space-x-2">
                  <RadioGroupItem value="qr_pay" id="payment-qr-pay" />
                  <Label htmlFor="payment-qr-pay" className="flex-1 cursor-pointer font-medium">
                    PayPay
                  </Label>
                  <PayPayBrandIcon />
                </div>
                {/* plan-054 — the bare word "PayPay" said nothing about the QR
                    screen that follows. Only rendered where it actually does. */}
                {showPayPayQrHint && (
                  <p className="mt-1 pl-6 text-xs text-neutral-500">
                    {t('payByPayPayQrHint')}
                  </p>
                )}
              </div>

              {/* #2545 — lối thoát, không phải một lựa chọn: chỉ hiện khi chi
                  nhánh không có cổng online nào dùng được. */}
              {counterPayOffered && (
                <div
                  className="flex items-center space-x-2 rounded-lg px-3"
                  style={{
                    backgroundColor: paymentMethod === "counter" ? "#2D8A390D" : "transparent",
                    border: paymentMethod === "counter" ? "1px solid #2D8A39" : "1px solid #E5E7EB",
                    height: "50px"
                  }}
                >
                  <RadioGroupItem value="counter" id="payment-counter" />
                  <Label htmlFor="payment-counter" className="flex-1 cursor-pointer font-medium">
                    {t('payCounter')}
                  </Label>
                </div>
              )}
            </div>
          </RadioGroup>
        </section>

        {/* Order Summary Section */}
        <section className="rounded-xl border border-neutral-200 bg-white p-3">
          <h3 className="mb-3 text-base font-bold text-neutral-900">{t('orderSummaryShort')}</h3>
          <div className="space-y-2.5">
            {items.map((item) => (
              <div key={item.id} className="flex gap-2.5 rounded-xl border border-neutral-200 p-2.5">
                <div className="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted">
                  {item.product.image ? (
                    <img src={item.product.image} alt={item.product.name} className="h-full w-full object-cover" />
                  ) : (
                    <div className="text-muted-foreground/30 text-xl">📦</div>
                  )}
                </div>
                <div className="flex-1 min-w-0">
                  {/* 2 cột: left = name/price/options; right = stack thùng rác
                      (trên) + x{qty} (dưới). `items-stretch` + `justify-between`
                      ở right column giữ x{qty} thẳng cột dọc với thùng rác. */}
                  <div className="flex items-stretch justify-between gap-2">
                    <div className="flex-1 min-w-0">
                      <h4 className="text-base font-bold leading-tight line-clamp-2" style={{ color: '#1F2937' }}>{item.product.name}</h4>
                      <p className="mt-0.5 text-base font-bold" style={{ color: '#1F2937' }}>{fmt(item.unitPrice)}</p>
                      <div className="mt-1">
                        <CartItemOptionsList lines={buildCartOptionLines(item)} />
                      </div>
                    </div>
                    <div className="flex shrink-0 flex-col items-end justify-between">
                      <button
                        aria-label={`${tCommon('remove')}: ${item.product.name}`}
                        onClick={() => setRemoveTarget(item.id)}
                        className="flex size-6 items-center justify-center text-muted-foreground hover:text-destructive"
                      >
                        <svg width="18" height="22" viewBox="0 0 18 22" fill="none" xmlns="http://www.w3.org/2000/svg" className="h-[18px] w-[18px]">
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
                      <span className="text-[20px] font-medium" style={{ color: '#1F2937' }}>x{item.quantity}</span>
                    </div>
                  </div>
                </div>
              </div>
            ))}
          </div>

          {/* Subtotal */}
          <div className="mt-3 border-t border-neutral-200 pt-3">
            <div className="flex items-center justify-between text-sm">
              <span className="text-muted-foreground">{t('subtotalInline', { count: totalItems })}</span>
              <span className="font-semibold text-neutral-900">{fmt(totalPrice)}</span>
            </div>
          </div>

          {/* Coupon */}
          <div className="mt-3 border-t border-neutral-200 pt-3 space-y-2">
            <div className="flex gap-2">
              <Input
                placeholder={t('couponPlaceholder')}
                value={couponCode}
                onChange={(e) => {
                  const next = e.target.value.toUpperCase();
                  setCouponCode(next);
                  // #1763 — emptying the field is a removal, not just an edit:
                  // otherwise the code stayed in sessionStorage and came back
                  // (with its discount) the next time checkout mounted.
                  if (!next.trim()) removeCoupon();
                }}
                className="flex-1 text-sm"
                style={{ height: '42px' }}
                maxLength={50}
                disabled={couponPending}
              />
              <Button
                variant="default"
                className="shrink-0 px-4 text-sm font-semibold"
                style={{ backgroundColor: '#2D8336', height: '42px' }}
                onClick={() => {
                  setCouponSubmitAttempted(false);
                  // Normalise like the desktop build: the applied code is what
                  // every comparison below is made against, so a stray case
                  // difference would read as "not applied yet" forever.
                  setCouponDebounced(normalizeCouponCode(couponCode));
                }}
                disabled={!couponCode.trim() || couponPending}
              >
                {couponPending ? <Loader2 className="h-4 w-4 animate-spin" /> : t('couponApply')}
              </Button>
            </div>
            {showUnappliedWarning && (
              <div className="rounded-md border border-amber-300 bg-amber-50 px-2.5 py-2 text-xs text-amber-900">
                {t('couponUnappliedWarning')}
              </div>
            )}
            {/* Preview thành công → hiển thị banner xanh + amount giảm */}
            {livePreview?.data?.is_valid && (
              <div className="flex items-center justify-between gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs text-emerald-900">
                <span>{t('couponApplied', { code: couponDebounced })}</span>
                <span className="flex items-center gap-1.5">
                  <span className="font-semibold">−{fmt(livePreview.data.discount_applied_amount ?? 0)}</span>
                  {/* #1763 — the only way off the order. */}
                  <button
                    type="button"
                    onClick={removeCoupon}
                    aria-label={t('couponRemove')}
                    title={t('couponRemove')}
                    className="rounded-full p-0.5 text-emerald-700 transition-colors hover:bg-emerald-100 hover:text-emerald-900"
                  >
                    <X className="size-3.5" aria-hidden="true" />
                  </button>
                </span>
              </div>
            )}
            {/* Lỗi customer_required + guest → CouponLoginPrompt (CTA đăng nhập).
                FEATURES.auth off (#47) hoặc `authEntryPoints` off → bỏ qua,
                rơi về block lỗi thường. Xem chú thích dài ở checkout-page.tsx. */}
            {livePreview?.data?.is_valid === false
              && livePreview.data.error_code === 'customer_required'
              && FEATURES.auth && FEATURES.authEntryPoints && !isLoggedIn && (
              <CouponLoginPrompt couponCode={couponDebounced} />
            )}
            {/* Lỗi khác → block đỏ; trừ `coupon_not_started` dùng tone amber
                (thông tin tạm thời, không phải lỗi destructive).
                Nếu là `coupon_excluded_by_active_promotion` → kèm UI cho
                user opt-in "use coupon over promo" (mirror desktop). */}
            {livePreview?.data?.is_valid === false
              && livePreview.data.error_code
              && !(livePreview.data.error_code === 'customer_required' && FEATURES.auth && FEATURES.authEntryPoints && !isLoggedIn) && (
              <div className={
                `rounded-md border px-2.5 py-2 text-xs ${
                  livePreview.data.error_code === 'coupon_not_started'
                    ? 'border-amber-300 bg-amber-50 text-amber-900'
                    : 'border-destructive/30 bg-destructive/5 text-destructive'
                }`
              }>
                <div>
                  {t(`couponError.${livePreview.data.error_code}` as Parameters<typeof t>[0]) || t('couponError.generic')}
                </div>
                {livePreview.data.error_code === 'coupon_excluded_by_active_promotion' && (
                  <>
                    {Array.isArray(livePreview.data.meta?.exclusive_item_names) && (
                      <ul className="mt-1 list-disc pl-4">
                        {(livePreview.data.meta?.exclusive_item_names as string[]).map((n) => (
                          <li key={n}>{n}</li>
                        ))}
                      </ul>
                    )}
                    {/* Plan-019 — toggle để revert HH lines về original_unit_price
                        trước khi apply coupon. Server ghi audit log. */}
                    <div className="mt-2 flex items-center gap-2">
                      <label className="inline-flex cursor-pointer items-center gap-1 text-[11px] font-medium">
                        <input
                          type="checkbox"
                          checked={downgradePromos}
                          onChange={(e) => setDowngradePromos(e.target.checked)}
                          className="size-3.5"
                        />
                        {t('useCouponOverPromo')}
                      </label>
                    </div>
                    <p className="mt-1 text-[10px] text-muted-foreground">
                      {t('useCouponOverPromoHint')}
                    </p>
                  </>
                )}
              </div>
            )}
            {/* Visible chip khi user chọn coupon-over-promo */}
            {downgradePromos && (
              <div className="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-900">
                {t('downgradePromosChosen')}
              </div>
            )}
          </div>

          {/* Service charge + per-rate tax (from shop settings) — only when set */}
          {(serviceRate > 0 || taxRows.length > 0) && (
            <div className="mt-3 space-y-1 border-t border-neutral-200 pt-3">
              {serviceRate > 0 && (
                <div className="flex items-center justify-between text-sm">
                  <span className="text-muted-foreground">{t('serviceChargeWithRate', { rate: serviceRate })}</span>
                  <span className="text-neutral-900">{fmt(serviceCharge)}</span>
                </div>
              )}
              {/* plan-043 — per-rate consumption-tax preview (8%対象 / 10%対象). */}
              <TaxBreakdownLines
                breakdown={taxRows}
                isTaxIncluded={pricesIncludeTax}
                format={fmt}
                namespace="checkout"
                className="space-y-1"
              />
            </div>
          )}

          {/* Total */}
          <div className="mt-3 flex items-center justify-between border-t border-neutral-200 pt-3">
            <span className="text-base font-bold text-neutral-900">
              {t('total')}
              <span className="ml-1.5 align-middle text-[11px] font-normal text-muted-foreground">
                ({t('taxIncludedBadge')})
              </span>
            </span>
            <span className="text-[20px] font-bold text-neutral-900">{fmt(finalTotal)}</span>
          </div>
        </section>
      </main>

      <RemoveItemConfirmDialog
        open={removeTarget !== null}
        onOpenChange={(o) => !o && setRemoveTarget(null)}
        onConfirm={() => {
          if (removeTarget) removeFromCart(removeTarget);
          setRemoveTarget(null);
        }}
      />

      {/* Sticky Bottom CTA */}
      <div className="fixed bottom-0 left-0 right-0 border-t border-neutral-200 bg-white p-3 shadow-lg safe-area-bottom">
        <Button
          onClick={handleSubmit}
          disabled={isSubmitting}
          className="w-full h-12 text-base font-semibold rounded-lg flex items-center justify-center gap-2"
          style={{ backgroundColor: '#2D8336' }}
        >
          {isSubmitting ? (
            <Loader2 className="h-5 w-5 animate-spin" />
          ) : (
            <>
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M17 18C17.5304 18 18.0391 18.2107 18.4142 18.5858C18.7893 18.9609 19 19.4696 19 20C19 20.5304 18.7893 21.0391 18.4142 21.4142C18.0391 21.7893 17.5304 22 17 22C16.4696 22 15.9609 21.7893 15.5858 21.4142C15.2107 21.0391 15 20.5304 15 20C15 18.89 15.89 18 17 18ZM1 2H4.27L5.21 4H20C20.2652 4 20.5196 4.10536 20.7071 4.29289C20.8946 4.48043 21 4.73478 21 5C21 5.17 20.95 5.34 20.88 5.5L17.3 11.97C16.96 12.58 16.3 13 15.55 13H8.1L7.2 14.63L7.17 14.75C7.17 14.8163 7.19634 14.8799 7.24322 14.9268C7.29011 14.9737 7.3537 15 7.42 15H19V17H7C6.46957 17 5.96086 16.7893 5.58579 16.4142C5.21071 16.0391 5 15.5304 5 15C5 14.65 5.09 14.32 5.24 14.04L6.6 11.59L3 4H1V2ZM7 18C7.53043 18 8.03914 18.2107 8.41421 18.5858C8.78929 18.9609 9 19.4696 9 20C9 20.5304 8.78929 21.0391 8.41421 21.4142C8.03914 21.7893 7.53043 22 7 22C6.46957 22 5.96086 21.7893 5.58579 21.4142C5.21071 21.0391 5 20.5304 5 20C5 18.89 5.89 18 7 18ZM16 11L18.78 6H6.14L8.5 11H16Z" fill="white"/>
              </svg>
              {t('payButton')}
            </>
          )}
        </Button>
      </div>
    </div>
  );
}
