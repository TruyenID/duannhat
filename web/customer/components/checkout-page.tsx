"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter, Link } from "@/i18n/routing";
import { useTranslations } from 'next-intl';
import {
  useCart,
  resolveCartItemImage,
} from "@/context/cart-context";
import type { MenuCategory } from "@/data/menu";
import { useBrand } from "@/context/brand-context";
import { loginHref } from "@/lib/shop-routes";
import { useAuth } from "@/context/auth-context";
import { useGlobalLoading } from "@/context/loading-context";
import { apiFetch, ApiError } from "@/lib/api";
import type { MergedMenuContext } from "@/lib/menu-item-match";
import { driftUpdatesFromError } from "@/lib/price-drift";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { StripeCardSection, type StripeCardSectionHandle } from "@/components/stripe-card-section";
import { PayPayBrandIcon } from "@/components/payment-brand-icons";
import { PickupTimeSelector } from "@/components/pickup-time-selector";
import { CartItemOptionsList, buildCartOptionLines } from "@/components/cart-item-options";
import { RemoveItemConfirmDialog } from "@/components/remove-item-confirm-dialog";
import { useCurrency } from "@/lib/currency";
import { deriveCountry, formatAsYouType, validatePhoneForCountry } from "@/lib/phone";
import { useStripeConfig } from "@/lib/stripe-config";
import { correctPaymentMethod, defaultPaymentMethod, shouldOfferCounterPay } from "@/lib/counter-pay";
import { paymentPolicyEcho, primePaymentPolicyContext } from "@/lib/payment-policy-context";
import { useAsyncPaymentMethods } from "@/hooks/use-async-payment-methods";
import { saveGuestOrder } from "@/lib/guest-orders";
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
import {
  computeCartTax,
  currencyStep,
  resolveLineRate,
  roundStep,
  type CustomerOrderType,
} from "@/lib/tax";
import { TaxBreakdownLines } from "@/components/tax-breakdown-lines";
import { CouponLoginPrompt } from "@/components/coupon-login-prompt";
import Header from "@/components/Header";
import {
  ArrowLeft,
  User,
  CreditCard,
  MessageSquare,
  Minus,
  Plus,
  UtensilsCrossed,
  Loader2,

  Clock,
  MapPin,
  Store,
  X,
} from "lucide-react";

// ─── Guest contact persistence ────────────────────────────────────────────────

const GUEST_CONTACT_KEY = "tempo:guest-contact";
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

function saveGuestContact(contact: GuestContact): void {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.setItem(GUEST_CONTACT_KEY, JSON.stringify(contact));
  } catch {
    /* quota exceeded — ignore */
  }
}

// ─── Main ─────────────────────────────────────────────────────────────────────

export default function CheckoutPage() {
  const router = useRouter();
  const { items, totalItems, totalPrice, orderType, dineInTable, isTableLocked, pickupTimeData, setPickupTimeData, updateQuantity, removeFromCart, clearCart, isItemExpired, cartMetadata, reconcileCrossTimeItems, applyServerPrices } =
    useCart();
  // Gate the trash action behind a confirm dialog — never delete on first tap.
  const [removeTarget, setRemoveTarget] = useState<string | null>(null);
  const { currentBranch } = useBrand();

  // #367 — đồng bộ lại các field dịch được (name / description / topping
  // labels) theo locale đang dùng. Giỏ chụp `product` lúc thêm món, nên đổi
  // locale sau đó để lại chuỗi của locale cũ. Menu page vẫn reconcile bình
  // thường, nhưng checkout tới được mà không cần đi qua đó — deep-link, back,
  // hoặc mở lại giỏ đã persist — nên phải tự làm.
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
  const t = useTranslations('checkout');
  const tc = useTranslations('common');
  const tCart = useTranslations('cart');
  // #1160 — the closed-shop message lives with the picker's copy.
  const tp = useTranslations('pickup');
  const tShop = useTranslations('shop');
  const { format: fmt } = useCurrency();

  // #1167 — live "is the shop open right now?", for the ASAP guard below.
  const shopOpenState = useCurrentBranchOpenState();
  const reopenLabel = useNextOpeningLabel(shopOpenState.nextOpening);

  const isDineIn = orderType === "dine_in";
  const hasExpiredItem = items.some((item) => isItemExpired(item));

  // #1160 — ETA = shop setting (phút/món) x TỔNG SỐ LƯỢNG. Same product the
  // backend stores on the order (CustomerPickupService), read from the same
  // setting, so the number the customer sees can no longer drift from the one
  // the kitchen works to. The old copy here hardcoded `15 + 2 x (số dòng - 1)`
  // and ignored quantity entirely.
  const prepMinutesPerItem = currentBranch.effective_order_policy?.prep_minutes_per_item ?? 5;
  const totalQuantity = items.reduce((sum, item) => sum + (item.quantity ?? 1), 0);
  const estimatedMinutes = prepMinutesPerItem * totalQuantity;

  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  // #2545 — `null` nghĩa là KHÁCH CHƯA BẤM GÌ, không phải "chưa có giá trị".
  // Phân biệt này là bắt buộc: mặc định phải dẫn xuất từ cổng nào dùng được
  // (`defaultPaymentMethod`), mà cấu hình cổng về SAU khi mount — nên nếu khởi
  // tạo bằng một chuỗi thật thì không cách nào biết `"card"` là do khách chọn
  // hay do ta ghim, và phép dẫn xuất sẽ đè lên lựa chọn thật của khách.
  // Cái được đọc ở mọi nơi khác là `payment` dẫn xuất bên dưới.
  const [paymentChoice, setPayment] = useState<string | null>(null);
  const [note, setNote] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [orderError, setOrderError] = useState<string | null>(null);
  const [contactErrors, setContactErrors] = useState<{ name?: string; phone?: string; email?: string }>({});
  // Anchor for the pickup-time card so we can scroll to it when the customer
  // is bounced back here to re-pick (see the effect below).
  const pickupSectionRef = useRef<HTMLDivElement>(null);

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

  // plan-035 — country + policy resolved server-side from branches.locale.
  // Drives libphonenumber-js + the "Phải pay trước" banner + email
  // required-ness on the takeaway form.
  const branchCountry = currentBranch.effective_order_policy?.phone_country
    ?? deriveCountry(currentBranch.locale ?? null);
  const prepBeforePayment = currentBranch.effective_order_policy?.prep_before_payment ?? true;
  const emailRequired = currentBranch.effective_order_policy?.customer_email_required ?? false;

  // plan-019 — coupon preview state. Auto-fill from cart context if available.
  const { appliedCouponCode, setAppliedCouponCode } = useCart();
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
  /**
   * plan-019 — "Use coupon instead of HH" opt-in. When the preview API
   * returns `coupon_excluded_by_active_promotion`, the customer can click
   * a CTA to retry by setting this flag. The order create endpoint then
   * forwards `downgrade_exclusive_promotions: true` to the backend, which
   * reverts exclusive HH lines to original_unit_price before applying the
   * coupon (audit log preserves the change).
   */
  const [downgradePromos, setDowngradePromos] = useState(false);
  /**
   * #1763 — the customer pressed Pay while the field held a code they had not
   * applied. Only a flag: the prompt itself is DERIVED below from the same
   * predicate that blocks the submit, so it cannot go on saying "press Apply"
   * after Apply has been pressed. It used to be a second copy of the message
   * living beside `orderError`, and clearing one revealed the other.
   */
  const [couponSubmitAttempted, setCouponSubmitAttempted] = useState(false);

  // Guard: nếu đang có draft chờ xác nhận → chuyển thẳng về /order-confirm
  // để customer xác nhận hoặc huỷ trước khi đặt món/thanh toán mới.
  useEffect(() => {
    const active = findActiveCheckoutDraft();
    if (active) {
      router.replace(`/order-confirm/${active.id}`);
    }
  }, [router]);

  // Auto-fill coupon from cart context on mount.
  //
  // #1763 — the ref is load-bearing, not tidiness. Without it the condition
  // `!couponCode` is true again the moment the customer EMPTIES the field, so
  // the effect re-filled the code they had just deleted and the coupon could
  // not be removed at all. It must fire once per mount (the return-from-login
  // case it was written for), never as a reaction to the field being cleared.
  const couponAutofilled = useRef(false);
  useEffect(() => {
    if (couponAutofilled.current || !appliedCouponCode) return;
    couponAutofilled.current = true;
    setCouponCode(appliedCouponCode);
    setCouponDebounced(normalizeCouponCode(appliedCouponCode));
  }, [appliedCouponCode]);

  const stripeCardRef = useRef<StripeCardSectionHandle>(null);

  // Track order đã tạo nhưng CHƯA payment-confirm thành công. Khi user
  // click Pay → tạo order → Stripe confirm fail (card decline / network)
  // → user click Pay lại → trước đây sẽ tạo order THỨ 2 (duplicate). Ref
  // này cho phép tái sử dụng order đầu tiên: nếu còn pending → skip POST
  // /orders → đi thẳng tới full-payment-intent với order.id cũ. Clear ref
  // (a) sau khi confirm-payment thành công, (b) khi cart/coupon thay đổi
  // (order BE đã lock theo nội dung cũ, retry sẽ ra sai amount).
  const pendingOrderRef = useRef<{ id: string; code: string } | null>(null);

  // Idempotency-Key gửi kèm POST /orders. BE (nếu hỗ trợ) dedupe theo key
  // → request retry sau network timeout không tạo order trùng. Cùng vòng
  // đời với `pendingOrderRef`: reset khi cart signature đổi, clear sau
  // payment chốt. UUID v4 từ crypto.randomUUID() (Safari 15.4+/Chrome 92+).
  const idempotencyKeyRef = useRef<string | null>(null);

  useEffect(() => {
    pendingOrderRef.current = null;
    idempotencyKeyRef.current = null;
  }, [
    // Signature reflect mọi thay đổi ảnh hưởng tới BE order:
    // #1715 — GIÁ phải nằm trong chữ ký. Không có nó thì: đơn đã tạo, thanh
    // toán fail, giá đổi (khung giờ ưu đãi đóng), khách bấm trả lại → key cũ
    // được tái dùng và đơn cũ ở GIÁ CŨ được thanh toán.
    items.map((i) => `${i.id}:${i.quantity}:${i.unitPrice}:${i.note ?? ""}`).join("|"),
    couponDebounced,
    downgradePromos,
  ]);
  // #1703 — `loading` matters: card is the DEFAULT selected method, so a call
  // site that only checks the key flashes "card payment unavailable" on every
  // checkout mount while the config request is still in flight, even when
  // Stripe is configured perfectly.
  const { config: stripeConfig, loading: stripeConfigLoading } = useStripeConfig();
  const stripePublishableKey = stripeConfig?.publishable_key ?? "";

  // plan-048 T2.5 — prime the policy identity for the branch the order will be
  // charged under; echoed on the intent body so BE can log policy drift.
  const paymentPolicySlug = cartMetadata?.branch_slug || currentBranch.slug;
  useEffect(() => {
    primePaymentPolicyContext(paymentPolicySlug);
  }, [paymentPolicySlug]);

  // plan-054 — does picking PayPay run the QR flow, or stay today's
  // settle-at-the-till behaviour? NOT a visibility flag: the `qr_pay` radio
  // below renders either way (§10.2). Same slug the order POST uses.
  const {
    paypayEnabled,
    loading: paypayLoading,
    counter: counterPaySettings,
  } = usePayPayAvailability(paymentPolicySlug);

  // MỘT nguồn cho câu hỏi "chọn sẵn cái gì". Khai rời hai lần là cách chúng
  // trôi khỏi nhau.
  const gatewayAvailability = {
    stripeReady: Boolean(stripePublishableKey),
    stripeLoading: stripeConfigLoading,
    paypayEnabled,
    paypayLoading,
  };
  // #2806 — "có chào trả tại quầy không" nay là cờ của chi nhánh, không còn
  // suy ra từ trạng thái cổng (#2545). Xem `lib/counter-pay.ts`.
  const counterPayOffered = shouldOfferCounterPay(counterPaySettings);

  // Radio `counter` biến mất khỏi DOM khi cổng online hiện ra, nhưng lựa chọn
  // đang giữ thì không tự đi theo: khách chọn counter ở chi nhánh không cổng,
  // đổi sang chi nhánh có cổng, và POST vẫn mang `counter` với một radio không
  // còn trên màn. Đây là chỗ khoá lại.
  //
  // DẪN XUẤT, không phải effect ghi lại state — cấu hình cổng về SAU khi mount,
  // nên một effect sẽ render lựa chọn chết một lượt rồi mới dịch nó dưới tay
  // khách (và nó vi phạm `react-hooks/set-state-in-effect`). Cùng lý lẽ với
  // `effectiveMethod` ở `/orders/{id}/pay`.
  const payment = correctPaymentMethod(
    paymentChoice ?? defaultPaymentMethod(gatewayAvailability),
    counterPayOffered,
  );

  // #1125 option B — one read for BOTH Stripe sections below. Also decides the
  // Elements payment-method configuration, which must match how the backend
  // created the PaymentIntent, so it cannot be left to a per-call-site guess.
  const asyncMethodsEnabled = useAsyncPaymentMethods(paymentPolicySlug);

  // …and if it does, say so on the radio: "PayPay" plus a brand badge told the
  // customer nothing about the QR screen that follows. Deliberately the SAME
  // predicate as the post-order route, so the sub-copy cannot promise a screen
  // this customer will not be sent to (dine-in, branch without PayPay QR — both
  // keep the settle-at-the-till wording, i.e. none).
  const showPayPayQrHint = shouldShowPayPayCheckoutHint({
    paypayEnabled,
    orderType: isDineIn ? "dine_in" : "takeaway",
  });

  const guestContactRequired = !isLoggedIn && !isDineIn;

  useEffect(() => {
    if (isLoggedIn) return;
    let cancelled = false;
    Promise.resolve(loadGuestContact()).then((saved) => {
      if (cancelled) return;
      if (saved.name) setName((prev) => prev || saved.name);
      if (saved.phone) setPhone((prev) => prev || saved.phone);
      if (saved.email) setEmail((prev) => prev || saved.email);
    });
    return () => {
      cancelled = true;
    };
  }, [isLoggedIn]);

  // plan-019 — debounce + preview-API call when the user submits a coupon
  // code. The preview is informational only; the order create endpoint
  // re-validates server-side inside its own transaction.
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
        // Forward customer_id khi đã login để backend bỏ qua check
        // `customer_required` (vốn dành cho guest). Thiếu field này thì
        // server treat như guest dù FE đã đăng nhập.
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

  const isEmpty = items.length === 0;

  // Tax + service charge từ ShopOrderSetting (qua currentBranch). Công thức
  // khớp CustomerOrderService::checkout: tax/service tính trên (subtotal - discount),
  // rồi cộng vào tổng. Server vẫn là nguồn sự thật khi tạo order — đây chỉ là
  // hiển thị cho khách thấy giá cuối trùng khớp. Khai báo trước handleSubmit
  // vì draft takeaway (#39) snapshot lại đúng các con số này.
  // #1763 — read the preview through this, never the raw state. It is the
  // result for `couponDebounced`, so it stops meaning anything the moment the
  // field shows something else: typing over an applied code (or clearing it)
  // used to leave the green badge and its discount on screen for a coupon the
  // order would no longer carry.
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
    ? livePreview.data.discount_applied_amount ?? 0
    : 0;
  const discountedSubtotal = Math.max(0, totalPrice - couponDiscount);
  const serviceRate = currentBranch.service_charge_rate ?? 0;
  // plan-043 — per-rate consumption tax computed from each cart line's
  // effective rate (menu payload → branch default) + 総額表示 flag. The server
  // stays authoritative at checkout; this is the display preview.
  const previewOrderType: CustomerOrderType = isDineIn ? "dine_in" : "takeaway";
  const pricesIncludeTax = currentBranch.prices_include_tax ?? false;
  const taxLines = items.map((item) => ({
    subtotal: item.unitPrice * item.quantity,
    rate: resolveLineRate(item.product, currentBranch),
  }));
  // #1425 — the service charge is an input to the tax calculation, not a line
  // added after it: it carries its own rate and its tax joins the matching rate
  // group. Computed before computeCartTax for that reason.
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
  // In tax-included (総額表示) mode the menu prices already contain the tax —
  // and so does the service charge — so nothing is added again to the total.
  // In excluded mode `taxAmount` already carries the service-charge tax.
  const finalTotal = Math.max(
    0,
    totalPrice - couponDiscount + (pricesIncludeTax ? 0 : taxAmount) + serviceCharge,
  );

  const navigateToSuccess = (data: {
    id: string;
    code: string;
    type: "dine_in" | "takeaway";
    shop?: string;
    stripeConfirmed?: boolean;
    /** plan-037 — when BE returns this status the customer hasn't
     * committed yet; route them to the confirmation step instead of the
     * success page. */
    status?: string;
  }) => {
    // plan-037 — counter-pay takeaway lands in /order-confirm first.
    // The kitchen/admin only see the order after the customer commits.
    if (data.status === "awaiting_confirmation") {
      showLoading();
      router.push(`/order-confirm/${data.id}`);
      return;
    }

    const params = new URLSearchParams({ id: data.id, code: data.code, type: data.type });
    if (data.shop) params.set("shop", data.shop);
    if (data.stripeConfirmed) params.set("stripe_return", "1");
    // Show global overlay so the spinner is continuous across the
    // checkout→order-success navigation gap (Next.js takes ~500ms-1s to
    // hydrate the next route; the button-level spinner is hidden during
    // that window). Auto-dismissed on pathname change.
    showLoading();
    router.push(`/order-success?${params.toString()}`);
  };

  function validateGuestContact(gName: string, gPhone: string, gEmail: string): { name?: string; phone?: string; email?: string } {
    const errs: { name?: string; phone?: string; email?: string } = {};
    if (!gName.trim()) {
      errs.name = t('nameRequired');
    }
    const phoneResult = validatePhoneForCountry(gPhone, branchCountry);
    if (!phoneResult.valid) {
      errs.phone = t(phoneResult.errorKey ?? 'phoneInvalid', { country: branchCountry });
    }
    const trimmedEmail = gEmail.trim();
    if (emailRequired && !trimmedEmail) {
      errs.email = t('emailRequired');
    } else if (trimmedEmail && !EMAIL_REGEX.test(trimmedEmail)) {
      errs.email = t('emailInvalid');
    }
    return errs;
  }

  // Synchronous re-entrancy guard cho handleSubmit. `setIsSubmitting(true)`
  // có thể bị React batch + delay vài frame → user click 2 lần nhanh trong
  // khoảng đó → 2 lần gọi vào `apiFetch /orders` + `full-payment-intent` →
  // duplicate order / double Stripe charge. Ref set ngay đầu function
  // trước await đầu tiên → đồng bộ → click thứ 2 bị block ngay.
  const submitGuardRef = useRef(false);

  const handleSubmit = async () => {
    if (submitGuardRef.current) {
      return;
    }
    submitGuardRef.current = true;
    try {
      // 1. Validate cart timeout — block submit if any item expired
      if (hasExpiredItem) {
        setOrderError(t('expiredItemsBlocked'));
        return;
      }

      if (isDineIn && !dineInTable?.qr_token) {
        setOrderError(t('noQrToken'));
        return;
      }
      if (!isDineIn && !currentBranch.slug) {
        setOrderError(t('noStoreInfo'));
        return;
      }
      if (!isDineIn && pickupTimeData.pickup_type === "scheduled") {
        if (!pickupTimeData.scheduled_pickup_time) {
          setOrderError(t('selectPickupTime'));
          return;
        }
        // Backend validates `after:now` — stale times persisted from a
        // previous session would 422 with no helpful UI signal. Catch it
        // here so the user gets a clear "pick again" message instead.
        if (new Date(pickupTimeData.scheduled_pickup_time).getTime() <= Date.now()) {
          setOrderError(t('selectPickupTime'));
          return;
        }
        // #1160 — the shop is shut at that moment. The server refuses this too
        // (422 PICKUP_OUTSIDE_OPENING_HOURS); blocking here keeps the customer
        // from losing a filled-in form to a round trip.
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
      } else if (!isDineIn && !shopOpenState.isOpen) {
        // #1167 — an "as soon as possible" take-away order at a shut shop. The
        // server refuses it too (422 BRANCH_CLOSED); saying so here keeps the
        // customer from losing a filled-in form to a round trip. A SCHEDULED
        // pre-order is fine and handled above — only the slot has to be open.
        setOrderError(reopenLabel
          ? tShop('closedCheckoutErrorWithTime', { when: reopenLabel })
          : tShop('closedCheckoutError'));
        return;
      }

      // Check for unapplied coupon. #1763 — the prompt belongs under the coupon
      // field (where the Apply button is), NOT in the destructive box above the
      // Pay button: writing it to `orderError` as well made it a second copy
      // that survived the customer doing exactly what it asked.
      if (hasUnappliedCouponEdit(couponCode, couponDebounced)) {
        setCouponSubmitAttempted(true);
        setOrderError(null);
        return;
      }
      setCouponSubmitAttempted(false);

      if (guestContactRequired) {
        const errs = validateGuestContact(name, phone, email);
        setContactErrors(errs);
        if (Object.keys(errs).length > 0) {
          setOrderError(t('checkCustomerInfo'));
          return;
        }
      } else if (!isLoggedIn && phone.trim()) {
        const phoneResult = validatePhoneForCountry(phone, branchCountry);
        if (!phoneResult.valid) {
          const msg = t(phoneResult.errorKey ?? 'phoneInvalid', { country: branchCountry });
          setContactErrors({ phone: msg });
          setOrderError(msg);
          return;
        }
        setContactErrors({});
      } else {
        setContactErrors({});
      }

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
        const toppings = mapCartItemToppings(item);
        return {
          product_sku_id: resolvedSkuId,
          quantity: item.quantity,
          // #1715 — giá dòng này đang HIỂN THỊ cho khách. Server không bao giờ
          // tính theo nó, chỉ dùng để TỪ CHỐI (409 `line_unit_price_drift`) khi
          // giá vừa giải ra cao hơn — khách không bị tạo đơn ở một giá khác cái
          // vừa nhìn thấy.
          expected_unit_price: item.unitPrice,
          // #1768 — per-item note ("Không hành", "Ít cay") từ cart. BE đã accept
          // `items.*.note` trong CustomerOrderStoreRequest; mobile đã forward,
          // desktop trước đây bỏ quên nên bếp không nhận được. `undefined` để
          // JSON.stringify drop key khi khách không gõ ghi chú.
          note: item.note?.trim() || undefined,
          ...(toppings.length > 0 ? { toppings } : {}),
        };
      });

      if (orderItems.some((i) => !i.product_sku_id)) {
        setOrderError(t('invalidItem'));
        return;
      }

      // Takeaway: prefer `cartMetadata.branch_slug` (branch nơi user đã add item)
      // hơn `currentBranch.slug` (branch hiện đang display). Tránh case user
      // browse sang branch khác trước khi vào /checkout → order post sai branch.
      // Fallback về currentBranch khi cart chưa có metadata.
      const takeawaySlug = cartMetadata?.branch_slug || currentBranch.slug;
      const endpoint = isDineIn
        ? `/api/v1/customer/tables/${dineInTable!.qr_token}/orders`
        : `/api/v1/customer/branches/${takeawaySlug}/orders`;

      // Takeaway + thanh toán tại quầy: review step trong /order-confirm.
      // Draft sống trong localStorage, BE chưa biết gì. POST /orders sẽ
      // được gọi khi customer commit (handleConfirm trong /order-confirm).
      if (!isDineIn && payment === "counter") {
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
          // Display lines for the selected options + toppings (with names).
          // Persisted on the draft so /order-confirm can show them (#435) — the
          // `toppings` payload below only carries IDs. (Previously `.value` was
          // read here but CartOptionLine exposes `.label`, so `variant` was
          // always undefined and nothing rendered.)
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
            // sẽ forward vào POST /orders và render lại cho khách kiểm.
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
          // the backend defaults it to estimated_ready_time (computed in server
          // time) — the FE must NOT fill a naive-local time here, since the BE
          // validates `after:now` in the app timezone and a client in a
          // behind-the-app timezone would be rejected.
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
        if (!isLoggedIn && (name.trim() || phone.trim())) {
          saveGuestContact({
            name: name.trim(),
            phone: phone.trim(),
            email: email.trim(),
          });
        }
        showLoading();
        router.push(`/order-confirm/${draftId}`);
        return;
      }

      setIsSubmitting(true);
      setOrderError(null);
      try {
        if (payment === "card") {
          // Optional chaining on validate() is `undefined` when Elements is
          // gone — not an error — so we would mint a PaymentIntent and leave
          // it Incomplete (ORD-2026-0237). Same guard as checkout-page-mobile.
          if (!stripeCardRef.current) {
            setOrderError(t("stripeNotReady"));
            setIsSubmitting(false);
            return;
          }
          const v = await stripeCardRef.current.validate();
          if (v?.error) {
            setOrderError(v.error);
            setIsSubmitting(false);
            return;
          }
        }

        // plan-019 — forward the previewed coupon code only when the
        // preview last said is_valid. If the preview returned an error we
        // skip it so the order goes through without coupon. Server will
        // still re-validate inside the create transaction (CouponService::
        // apply runs lockForUpdate + freshness re-check), so a stale "valid"
        // preview that flipped to "expired" between view and submit still
        // surfaces a 422 with the structured error_code.
        // Forward the coupon when preview is valid OR when the customer
        // explicitly opted into the "use coupon over promo" downgrade
        // (in which case server-side validation will recompute against
        // the downgraded cart and accept the coupon).
        // #1763 — gated on `livePreview`/`couponInForce`, so a code the
        // customer deleted from the field is not silently attached to the order
        // (the total on screen already dropped its discount).
        const applyCouponCode =
          (livePreview?.data?.is_valid || (downgradePromos && couponInForce)) && couponDebounced
            ? couponDebounced
            : undefined;

        // Reuse pending order nếu user retry Pay sau Stripe fail (cart +
        // coupon chưa đổi — `pendingOrderRef` đã được clear bằng useEffect
        // ở phía trên nếu có thay đổi). Tránh tạo order duplicate trên BE.
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
              customer_name: name || undefined,
              customer_phone: phone || undefined,
              customer_email: email || undefined,
              customer_takeaway_name: name || undefined,
              customer_takeaway_phone: phone || undefined,
              customer_takeaway_email: email || undefined,
              note: note.trim() || undefined,
              payment_method: payment,
              // #2545 — Customer không còn chào trả sau, nên dine-in luôn là
              // "before". Giữ field vì BE vẫn validate `in:before,after`.
              payment_timing: isDineIn ? "before" : undefined,
              coupon_code: applyCouponCode,
              downgrade_exclusive_promotions: downgradePromos || undefined,
              ...((!isDineIn && pickupTimeData.pickup_type) ? {
                pickup_type: pickupTimeData.pickup_type,
                scheduled_pickup_time: pickupTimeData.scheduled_pickup_time || undefined,
              } : {}),
            }),
          });
          pendingOrderRef.current = { id: res.data.id, code: res.data.code };
        }
        if (!isLoggedIn && (name.trim() || phone.trim())) {
          saveGuestContact({ name: name.trim(), phone: phone.trim(), email: email.trim() });
        }
        const successData = {
          id: res.data.id,
          code: res.data.code,
          type: (isDineIn ? "dine_in" : "takeaway") as "dine_in" | "takeaway",
          // Phải dùng `takeawaySlug` (= `cartMetadata.branch_slug ||
          // currentBranch.slug`) — branch nơi user thật sự đặt món. Nếu chỉ
          // đọc `currentBranch.slug` thì khi user add món ở branch A rồi
          // browse sang B trước khi checkout, order tạo ở A (endpoint
          // takeawaySlug=A) nhưng success URL ?shop=B → trang success show
          // sai context.
          shop: !isDineIn && takeawaySlug ? takeawaySlug : undefined,
          // plan-037 — carry BE status so navigateToOrderSuccess can route
          // to /order-confirm when the order needs the confirmation step.
          status: (res.data as { status?: string }).status,
        };

        // #1125 option B — set when an async method (Konbini/bank transfer)
        // left the intent awaiting settlement instead of succeeding inline.
        let asyncAwaitingPayment = false;

        if (payment === "card") {
          // Step 1: Create PaymentIntent on backend
          const intentRes = await apiFetch<{
            data: { client_secret: string; payment_intent_id: string };
          }>(`/api/v1/customer/orders/${res.data.id}/full-payment-intent`, {
            method: "POST",
            body: JSON.stringify(paymentPolicyEcho(paymentPolicySlug)),
          });

          const returnUrl = `${window.location.origin}/order-success?${new URLSearchParams({
            id: successData.id,
            code: successData.code,
            type: successData.type,
            ...(successData.shop ? { shop: successData.shop } : {}),
            stripe_return: "1",
          }).toString()}`;

          // Step 2: Confirm with the EXACT client_secret from backend
          const confirmRes = await stripeCardRef.current?.confirm(
            intentRes.data.client_secret,
            returnUrl,
          );

          if (!confirmRes?.succeeded && !confirmRes?.pending) {
            setOrderError(confirmRes?.error ?? t('paymentFailed'));
            setIsSubmitting(false);
            return;
          }

          // Mark the order paid server-side immediately so admin reflects "paid"
          // without depending on `stripe listen`. Non-blocking — webhook backs up.
          // For an async method (#1125) this records the awaiting placeholder.
          try {
            await apiFetch(`/api/v1/customer/orders/${res.data.id}/confirm-payment`, {
              method: "POST",
              body: JSON.stringify({ payment_intent_id: intentRes.data.payment_intent_id }),
            });
          } catch (syncErr) {
            console.warn("[Stripe] confirm-payment sync failed; webhook will reconcile", syncErr);
          }

          if (confirmRes?.pending) {
            // #1125 option B — voucher/instructions displayed; money arrives
            // later via webhook. Keep the guest ON the order detail page (it
            // shows live payment state) instead of the success screen.
            asyncAwaitingPayment = true;
          }
        }

        // Payment đã thành công (counter-payment hoặc Stripe confirmed) →
        // order on BE đã chốt, không cần tái sử dụng nữa. Clear cả 2 ref
        // (pending order + idempotency key) để lần checkout sau là fresh.
        pendingOrderRef.current = null;
        idempotencyKeyRef.current = null;

        // Persist order pointer cho mọi đơn takeaway (cả counter lẫn card)
        // để trang /orders + /orders/{id} đọc lại được sau khi user reload
        // hoặc đóng tab. SCOPE: takeaway only (per plan-031); card-paid
        // takeaway vẫn cần pointer vì /orders/{id} dùng localStorage làm
        // guard cho guest. Không phụ thuộc isLoggedIn: khách đăng nhập không
        // cần pointer (#1452 cho /orders/[id]/pay cổng auth riêng), nhưng ghi
        // vẫn rẻ và giữ đúng một nhánh cho cả hai loại khách.
        const shouldSaveGuestOrder = !isDineIn;
        const fallbackShopSlug = successData.shop || takeawaySlug || currentBranch.slug || "default";

        if (shouldSaveGuestOrder) {
          saveGuestOrder({
            id: successData.id,
            code: successData.code,
            shop: fallbackShopSlug,
          });
        }

        clearCart();
        if (asyncAwaitingPayment) {
          // #1125 option B — the guest still owes the store visit / transfer;
          // the order detail page shows live payment state and realtime-flips
          // when the webhook settles.
          router.push(`/orders/${successData.id}?awaiting_payment=1`);
          return;
        }

        // plan-054 — the PayPay *upgrade*. `null` means "this surface keeps
        // doing exactly what it did before", which is what an unconfigured
        // branch (all of them today) must get: the `qr_pay` radio still
        // exists, still creates the order, staff still settle it by hand.
        // Only an enabled branch is handed over to the QR screen — reachable
        // for a guest because `saveGuestOrder` above just wrote the pointer
        // that gates `/orders/[id]/pay`, and for a signed-in customer because
        // that screen's auth gate needs no pointer from them (#1452/#1692).
        //
        // `awaiting_confirmation` is excluded: plan-037 says that order has
        // not been committed yet, so it belongs on /order-confirm — and the
        // pay screen's payable gate would refuse it anyway.
        const paypayRoute =
          successData.status === "awaiting_confirmation"
            ? null
            : payPayPostOrderRoute({
                paypayEnabled,
                paymentMethod: payment,
                orderType: isDineIn ? "dine_in" : "takeaway",
                orderId: successData.id,
              });
        if (paypayRoute) {
          showLoading();
          router.push(paypayRoute);
          return;
        }

        navigateToSuccess({ ...successData, stripeConfirmed: payment === "card" });
        // Keep isSubmitting=true through the route transition so the button
        // keeps spinning until /order-success mounts. Otherwise the finally
        // block re-enables the button while Next.js is still tearing this
        // page down and the user sees no loading indicator for ~1s.
        return;
      } catch (err) {
        // #1715 — 409 vì giá vừa đổi giữa lúc khách đứng ở checkout. Thân lỗi
        // mang giá thật từng dòng: áp vào giỏ, báo, để khách bấm lại. Khối tiền
        // (coupon preview / thuế / phí) tự dựng lại vì nó tính từ giỏ.
        const repriced = driftUpdatesFromError(err, items.map((i) => i.id));
        if (repriced) {
          applyServerPrices(repriced);
          setOrderError(t('priceChangedBody'));
          setIsSubmitting(false);
          return;
        }
        console.error("[checkout] order failed:", err);
        const msg = err instanceof ApiError
          ? t('apiError', { status: err.status, message: err.message })
          : t('orderFailed');
        setOrderError(msg);
        setIsSubmitting(false);
      }
    } finally {
      // Release guard cho mọi exit path: early-returns ở validation,
      // success path, hoặc thrown từ inner try/catch. `return` từ inner
      // block vẫn trigger finally này.
      submitGuardRef.current = false;
    }
  };

  if (isEmpty) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-4 p-6">
        <p className="text-muted-foreground">{t('emptyCart')}</p>
        <Button variant="outline" onClick={() => router.push("/")}>
          <ArrowLeft className="mr-2 h-4 w-4" />
          {t('backToMenu')}
        </Button>
      </div>
    );
  }

  if (isDineIn && (!isTableLocked || !dineInTable?.qr_token)) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center gap-4 p-6 text-center">
        <UtensilsCrossed className="h-10 w-10 text-muted-foreground/60" />
        <div>
          <p className="font-semibold">{t('noTable')}</p>
          <p className="mt-1 text-sm text-muted-foreground">
            {t('scanQr')}
          </p>
        </div>
        {/* Fallback khi dine-in mất table lock. FEATURES.booking off (#47) →
            /booking bị chặn nên đưa về trang chủ thay vì link mồ côi. */}
        <Button onClick={() => router.push(FEATURES.booking ? "/booking" : "/")}>
          <ArrowLeft className="mr-2 h-4 w-4" />
          {t('backToSelectTable')}
        </Button>
      </div>
    );
  }

  const orderItemsList = (
    <div className="space-y-3">
      {items.map((item) => {
        const itemImage = resolveCartItemImage(item);
        return (
          <div key={item.id} className="flex gap-3 rounded-lg border border-border/40 bg-white p-3">
            <div className="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted text-muted-foreground/30">
              {itemImage ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={itemImage}
                  alt={item.product.name}
                  className="h-full w-full object-cover"
                />
              ) : (
                <svg className="h-10 w-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={1.5}
                    d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
                  />
                </svg>
              )}
            </div>
            <div className="min-w-0 flex-1">
              {/* Cấu trúc 2 cột: left = name/price/options stretch full; right
                  = stack thùng rác (trên) + x{qty} (dưới). `items-stretch`
                  + `justify-between` ở right column giữ trash bám đỉnh và
                  x{qty} bám đáy → x{qty} thẳng cột dọc với thùng rác. */}
              <div className="flex items-stretch justify-between gap-2">
                <div className="min-w-0 flex-1">
                  <h4 className="text-sm font-semibold leading-tight">{item.product.name}</h4>
                  <p className="mt-0.5 text-sm font-medium">
                    {fmt(item.unitPrice)}
                  </p>
                  <div className="mt-1">
                    <CartItemOptionsList lines={buildCartOptionLines(item)} />
                  </div>
                </div>
                <div className="flex shrink-0 flex-col items-end justify-between">
                  <Button
                    aria-label={`${tc('remove')}: ${item.product.name}`}
                    variant="ghost"
                    size="icon"
                    className="h-6 w-6 text-muted-foreground hover:text-destructive"
                    onClick={() => setRemoveTarget(item.id)}
                  >
                    <svg width="18" height="22" viewBox="0 0 18 22" fill="none" xmlns="http://www.w3.org/2000/svg" className="h-[18px] w-[18px]">
                      <g clipPath="url(#clip0_1_4141)">
                        <path d="M16 7V18.6C16 19.2365 15.7471 19.847 15.2971 20.2971C14.847 20.7471 14.2365 21 13.6 21H4.4C3.76348 21 3.15303 20.7471 2.70294 20.2971C2.25286 19.847 2 19.2365 2 18.6V7M13 4V2.2C13 1.54 12.46 1 11.8 1H6.2C5.54 1 5 1.54 5 2.2V4M13 4H5M13 4H18M5 4H0M9 10V16M12 10V16M6 10V16" stroke="#ef4444" strokeWidth="1.5" strokeMiterlimit="10" strokeLinecap="round" strokeLinejoin="round" />
                      </g>
                      <defs>
                        <clipPath id="clip0_1_4141">
                          <rect width="18" height="22" fill="white" />
                        </clipPath>
                      </defs>
                    </svg>
                  </Button>
                  {/* godx-tempo#1752 — nút +/- được dựng lại.
                      Chúng từng có ở đây và biến mất trong `98139fa`
                      ("improve dine-in and payment UI", 2026-05-05) — một commit
                      không nói gì về việc bỏ chúng, và để lại ba tham chiếu
                      chết: `Minus`, `Plus` ở import và `updateQuantity` ở dòng
                      destructure. Bỏ có chủ đích thì đã dọn cả ba. Mọi màn anh
                      em vẫn giữ nút của mình (`cart-drawer`, dine-in
                      `confirm/page`), nên checkout là chỗ duy nhất bắt khách
                      quay lại menu chỉ để đổi số lượng.
                      Nhãn kèm TÊN MÓN vì danh sách có nhiều dòng. */}
                  <div className="flex items-center gap-1.5">
                    <Button
                      aria-label={`${tCart('decreaseQty')}: ${item.product.name}`}
                      variant="outline"
                      size="icon"
                      className="size-6 rounded-full"
                      onClick={() => updateQuantity(item.id, item.quantity - 1)}
                      disabled={item.quantity <= 1}
                    >
                      <Minus className="size-3" />
                    </Button>
                    <span className="w-5 text-center text-sm font-medium tabular-nums">
                      {item.quantity}
                    </span>
                    <Button
                      aria-label={`${tCart('increaseQty')}: ${item.product.name}`}
                      variant="outline"
                      size="icon"
                      className="size-6 rounded-full"
                      onClick={() => updateQuantity(item.id, item.quantity + 1)}
                    >
                      <Plus className="size-3" />
                    </Button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );

  return (
    <div className="min-h-screen bg-muted/30">
      {/* Header — desktop dùng global Header (logo + login/register/cart) +
          sub-header back/title theo Figma. Mobile (CheckoutPageMobile) có
          header riêng nên ở đây ẩn block này dưới md.
          `containerClassName="max-w-6xl"` để inner container của Header khớp
          width với sub-header + main content (`max-w-6xl`), 3 cụm cùng align.
          Wrapper dùng `hidden md:contents` (display: contents) thay vì
          `md:block` để wrapper KHÔNG tạo layout box — Header bên trong có
          `position: sticky top-0` sẽ pin theo viewport thay vì bị wrapper
          (cao đúng bằng Header) constrain rồi trôi theo content khi cuộn. */}
      <div className="hidden md:contents">
        <Header hideSwitcher showLogo hideOrderCta hideRegister containerClassName="max-w-6xl" />
        {/* Sub-header — ẩn border-b và bg-white theo yêu cầu để liền mạch
            với page bg (`bg-muted/30`) ở desktop. */}
        <div>
          <div className="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3">
            <Button variant="ghost" size="icon" className="shrink-0" onClick={() => router.back()} aria-label={tc('back')}>
              <ArrowLeft className="h-5 w-5" />
            </Button>
            <div className="flex-1">
              <h1 className="text-base font-bold leading-tight">{t('confirmOrder')}</h1>
              {/* Branch name ẩn theo yêu cầu — giữ markup commented để khôi phục
                  nhanh nếu cần. */}
              {false && currentBranch.name && (
                <p className="text-xs text-muted-foreground">{currentBranch.name}</p>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Mobile fallback header — render khi viewport < md (CheckoutPageMobile
          chỉ kích hoạt qua window.innerWidth ở client; lúc SSR/initial render
          có thể vẫn vào desktop component nên giữ header gọn cho mobile). */}
      <header className="sticky top-0 z-20 border-b bg-card md:hidden">
        <div className="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3">
          <Button variant="ghost" size="icon" className="shrink-0" onClick={() => router.back()} aria-label={tc('back')}>
            <ArrowLeft className="h-5 w-5" />
          </Button>
          <div className="flex-1">
            <h1 className="text-base font-bold leading-tight">{t('confirmOrder')}</h1>
            <p className="text-xs text-muted-foreground">{currentBranch.name}</p>
          </div>
        </div>
      </header>

      {/* Content */}
      <div className="mx-auto max-w-6xl px-4 py-6 pb-32 md:pt-0 lg:grid lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start lg:gap-6 lg:pb-10 anim-enter">
        {/* Main column */}
        <div className="lg:min-w-0 space-y-4">
          {/* Section: Store info — takeaway only */}
          {!isDineIn && (
            <div className="rounded-xl border border-border/60 bg-card shadow-sm">
              <section className="p-5">
                <div className="flex items-start gap-4">
                  {currentBranch.img_branches ? (
                    <div className="relative h-20 w-20 shrink-0 overflow-hidden rounded-xl ring-1 ring-border/60 shadow-sm sm:h-24 sm:w-24">
                      {/* eslint-disable-next-line @next/next/no-img-element */}
                      <img
                        src={currentBranch.img_branches}
                        alt={currentBranch.name}
                        className="h-full w-full object-cover transition-transform duration-500 hover:scale-105"
                      />
                      <div className="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/20 via-transparent to-transparent" />
                      {currentBranch.brand?.logo_url && (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img
                          src={currentBranch.brand.logo_url}
                          alt={currentBranch.brand.name}
                          className="absolute bottom-1.5 left-1.5 h-5 w-5 rounded-md border border-white/80 bg-white object-contain p-0.5 shadow-sm sm:h-6 sm:w-6"
                        />
                      )}
                    </div>
                  ) : currentBranch.brand?.logo_url ? (
                    <div className="flex h-20 w-20 shrink-0 items-center justify-center rounded-xl border bg-gradient-to-br from-primary/5 to-primary/10 p-2 ring-1 ring-border/60 shadow-sm sm:h-24 sm:w-24">
                      {/* eslint-disable-next-line @next/next/no-img-element */}
                      <img
                        src={currentBranch.brand.logo_url}
                        alt={currentBranch.brand.name}
                        className="h-full w-full object-contain"
                      />
                    </div>
                  ) : (
                    <div className="flex h-20 w-20 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-muted to-muted/60 text-muted-foreground ring-1 ring-border/60 shadow-sm sm:h-24 sm:w-24">
                      <Store className="h-8 w-8" />
                    </div>
                  )}
                  <div className="min-w-0 flex-1">
                    {/* godx-tempo#1752 — tên chi nhánh đứng MỘT MÌNH, kèm tên
                        thương hiệu làm phụ đề. Cùng khuôn trang chọn cửa hàng
                        (`select-branch/page.tsx`) và trang menu.

                        Trước đây ghép thêm tiền tố `t('store')`, mà tên chi
                        nhánh từ API đã tự mang từ đó ở cả ba ngôn ngữ ⇒ "Cửa
                        hàng Cửa hàng Ningyocho" / "店舗 人形町店" / "Store
                        Ningyocho Store".

                        Và tệ hơn lặp chữ: 10/17 chi nhánh KHÔNG phải cửa hàng —
                        `event-store` là "Xe bán đồ ăn số 1", còn có trụ sở
                        chính, nhà máy 白井工場, bếp 住吉キッチン. Với những chỗ đó
                        tiền tố không thừa mà SAI loại hình. Bỏ hẳn là đúng cho
                        cả 17 trường hợp; tên chi nhánh tự nó đã đủ nghĩa. */}
                    <h2 className="text-lg font-bold leading-tight sm:text-xl">
                      {currentBranch.slug ? (
                        <Link
                          href={`/stores/${currentBranch.slug}`}
                          className="inline-flex items-center gap-1 hover:text-primary hover:underline"
                        >
                          {currentBranch.name}
                        </Link>
                      ) : (
                        currentBranch.name
                      )}
                    </h2>
                    {currentBranch.brand?.name && (
                      <p className="mt-1 text-xs text-muted-foreground">
                        {currentBranch.brand.name}
                      </p>
                    )}
                    <div className="mt-2 space-y-1 text-sm text-muted-foreground">
                      {currentBranch.address && (
                        <p className="flex items-start gap-1.5">
                          <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                          <span>{t('addressLabel')}: {currentBranch.address}</span>
                        </p>
                      )}
                      {currentBranch.business_hours && (
                        <p className="flex items-start gap-1.5">
                          <Clock className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                          <span>{t('businessHours')}: {currentBranch.business_hours}</span>
                        </p>
                      )}
                    </div>
                  </div>
                </div>
              </section>
            </div>
          )}

          {/* Section: Pickup time — takeaway only, separate card */}
          {!isDineIn && (
            <div
              ref={pickupSectionRef}
              className="rounded-xl border border-border/60 bg-card"
              style={{
                borderRadius: '12px',
                boxShadow: '0px 1px 2px -1px #0000001A, 0px 1px 3px 0px #0000001A'
              }}
            >
              <section className="p-5">
                <PickupTimeSelector
                  value={pickupTimeData}
                  onChange={setPickupTimeData}
                  estimatedMinutes={estimatedMinutes}
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
            </div>
          )}

          {/* Section: Order summary — mobile only */}
          <div className="rounded-xl border border-border/60 bg-card shadow-sm lg:hidden">
            <section className="p-5">
              <div className="mb-3 flex items-center gap-2">
                <span className="text-base font-bold">{t('orderItems', { count: totalItems })}</span>
              </div>
              {orderItemsList}
            </section>
          </div>

          {/* Dine-in: table */}
          {isDineIn && dineInTable && (
            <div
              className="rounded-xl border border-border/60 bg-card"
              style={{
                borderRadius: '12px',
                boxShadow: '0px 1px 2px -1px #0000001A, 0px 1px 3px 0px #0000001A'
              }}
            >
              <section className="p-5">
                <div className="mb-3 flex items-center gap-2">
                  <UtensilsCrossed className="h-4 w-4 text-primary" />
                  <span className="text-base font-bold">{t('table')}</span>
                </div>
                <div className="flex items-center gap-3 rounded-xl border-2 border-primary/30 bg-primary/5 px-4 py-3">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                    <UtensilsCrossed className="h-5 w-5 text-primary" />
                  </div>
                  <div className="flex-1">
                    <p className="text-sm font-bold text-primary">{t('tableNumber', { number: dineInTable.number })}</p>
                    <p className="text-xs text-muted-foreground">{dineInTable.zoneName} · {t('seatsCount', { count: dineInTable.seats })}</p>
                  </div>
                  <span className="rounded-full bg-primary/10 px-2.5 py-1 text-[11px] font-semibold text-primary">QR</span>
                </div>
              </section>
            </div>
          )}

          {/* Section: Customer info - separate card */}
          <div
            className="rounded-xl border border-border/60 bg-card"
            style={{
              borderRadius: '12px',
              boxShadow: '0px 1px 2px -1px #0000001A, 0px 1px 3px 0px #0000001A'
            }}
          >
            <section className="p-5">
              <div className="mb-3 flex items-center gap-2">
                <User className="h-5 w-5 text-neutral-600" />
                <span className="text-base md:text-[20px] font-bold md:font-semibold">{t('customerInfo')}</span>
              </div>

              {isLoggedIn ? (
                <div className="flex items-center gap-3 rounded-xl border-2 border-primary/30 bg-primary/5 px-4 py-3">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10">
                    <User className="h-5 w-5 text-primary" />
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-bold text-primary truncate">{user?.name}</p>
                    <p className="text-xs text-muted-foreground truncate">{user?.email}</p>
                  </div>
                </div>
              ) : (
                <div className="space-y-3">
                  {guestContactRequired && (
                    <p className="text-xs text-muted-foreground">
                      {t('contactHelp')}
                    </p>
                  )}
                  {/* "Đã có tài khoản? Đăng nhập" — ẩn khi FEATURES.auth off
                      (#47) hoặc khi thôi mời đăng nhập (`authEntryPoints`). */}
                  {FEATURES.auth && FEATURES.authEntryPoints && (
                    <p className="text-xs text-muted-foreground">
                      {t('haveAccount')}{" "}
                      <Link href={loginHref(currentBranch.slug)} className="font-semibold text-primary hover:underline">
                        {t('loginLink')}
                      </Link>
                    </p>
                  )}
                  <div>
                    <Label htmlFor="name" className="text-xs text-muted-foreground">
                      {t('fullName')}
                      {guestContactRequired && <span className="ml-1 text-destructive">*</span>}
                    </Label>
                    <Input
                      id="name"
                      placeholder={t('enterName')}
                      value={name}
                      onChange={(e) => {
                        setName(e.target.value);
                        if (contactErrors.name) setContactErrors((p) => ({ ...p, name: undefined }));
                      }}
                      autoComplete="name"
                      required={guestContactRequired}
                      aria-invalid={Boolean(contactErrors.name)}
                      aria-describedby={contactErrors.name ? "name-error" : undefined}
                      className={`mt-1 h-[42px] ${contactErrors.name ? "border-destructive focus-visible:ring-destructive" : ""}`}
                    />
                    {contactErrors.name && (
                      <p id="name-error" className="mt-1 text-xs text-destructive">
                        {contactErrors.name}
                      </p>
                    )}
                  </div>
                  <div>
                    <Label htmlFor="phone" className="text-xs text-muted-foreground">
                      {t('phoneNumber')}
                      <span className="ml-1 text-muted-foreground">({branchCountry})</span>
                      {guestContactRequired && <span className="ml-1 text-destructive">*</span>}
                    </Label>
                    <Input
                      id="phone"
                      type="tel"
                      inputMode="tel"
                      placeholder={t('enterPhone')}
                      value={phone}
                      onChange={(e) => {
                        // plan-035 — format-as-you-type for the branch's
                        // country so the visible value tracks the local
                        // grouping (`033 690 9454` for VN).
                        setPhone(formatAsYouType(e.target.value, branchCountry));
                        if (contactErrors.phone) setContactErrors((p) => ({ ...p, phone: undefined }));
                      }}
                      autoComplete="tel"
                      required={guestContactRequired}
                      aria-invalid={Boolean(contactErrors.phone)}
                      aria-describedby={contactErrors.phone ? "phone-error" : undefined}
                      className={`mt-1 h-[42px] ${contactErrors.phone ? "border-destructive focus-visible:ring-destructive" : ""}`}
                    />
                    {contactErrors.phone && (
                      <p id="phone-error" className="mt-1 text-xs text-destructive">
                        {contactErrors.phone}
                      </p>
                    )}
                  </div>
                  <div>
                    <Label htmlFor="email" className="text-xs text-muted-foreground">
                      {t('emailLabel')}
                      {emailRequired && <span className="ml-1 text-destructive">*</span>}
                    </Label>
                    <Input
                      id="email"
                      type="email"
                      inputMode="email"
                      placeholder={t('enterEmail')}
                      value={email}
                      onChange={(e) => {
                        setEmail(e.target.value);
                        if (contactErrors.email) setContactErrors((p) => ({ ...p, email: undefined }));
                      }}
                      autoComplete="email"
                      required={emailRequired}
                      aria-invalid={Boolean(contactErrors.email)}
                      aria-describedby={contactErrors.email ? "email-error" : undefined}
                      className={`mt-1 h-[42px] ${contactErrors.email ? "border-destructive focus-visible:ring-destructive" : ""}`}
                    />
                    <p className="mt-1 text-[11px] text-muted-foreground">
                      {t('emailHelp')}
                    </p>
                    {contactErrors.email && (
                      <p id="email-error" className="mt-1 text-xs text-destructive">
                        {contactErrors.email}
                      </p>
                    )}
                  </div>
                  {!isDineIn && prepBeforePayment && (
                    <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                      {t('prepAfterPaymentNotice')}
                    </div>
                  )}
                </div>
              )}
            </section>
          </div>

          {/* Payment — dine-in - separate card */}
          {isDineIn && (
            <div
              className="rounded-xl border border-border/60 bg-card"
              style={{
                borderRadius: '12px',
                boxShadow: '0px 1px 2px -1px #0000001A, 0px 1px 3px 0px #0000001A'
              }}
            >
              <section className="p-5">
                <div className="mb-3 flex items-center gap-2">
                  <CreditCard className="h-5 w-5 text-neutral-600" />
                  <span className="text-base md:text-[20px] font-bold md:font-semibold">{t('payment')}</span>
                </div>

                {/* #2545 — bộ chọn "Thanh toán sau / Thanh toán trước" ĐÃ GỠ.
                    Nhánh "sau" chỉ có `counter` và `call_staff`, cả hai đều là
                    thu tiền tại quầy; gỡ chúng thì nhánh đó rỗng, và Customer
                    không có khái niệm trả sau nữa. Dine-in trả trước bằng cổng
                    online; `counter` chỉ quay lại qua khối dự phòng cuối nhóm. */}
                <RadioGroup value={payment} onValueChange={setPayment} className="gap-2">
                  <label
                    className={cn(
                      "cursor-pointer rounded-xl border-2 px-4 py-3 transition-all",
                      payment === "card"
                        ? "border-primary bg-primary/5"
                        : "border-border hover:border-muted-foreground/40",
                    )}
                  >
                    <div className="flex items-center gap-3">
                      <RadioGroupItem value="card" />
                      <p className="flex-1 text-sm lg:text-base font-semibold">{t('creditCard')}</p>
                    </div>
                    {payment === "card" && stripePublishableKey && (
                      <div onClick={(e) => e.preventDefault()}>
                        <StripeCardSection
                          ref={stripeCardRef}
                          amount={Math.max(1, Math.round(finalTotal))}
                          currency={currentBranch.currency_code ?? "JPY"} // #815 — match PI currency
                          publishableKey={stripePublishableKey}
                          // #2790 — Stripe.js refuses to confirm without these; the
                          // Element opts out of collecting them.
                          billingDetails={{ name, email, phone }}
                          // #1125 option B — Konbini/銀行振込 tabs when enabled.
                          showMethodTabs={asyncMethodsEnabled}
                        />
                      </div>
                    )}
                    {payment === "card" && !stripePublishableKey && !stripeConfigLoading && (
                      <p className="mt-2 text-xs text-destructive">
                        {t('stripeNotConfigured')}
                      </p>
                    )}
                  </label>

                  <label
                    className={cn(
                      "cursor-pointer rounded-xl border-2 px-4 py-3 transition-all",
                      payment === "qr_pay"
                        ? "border-primary bg-primary/5"
                        : "border-border hover:border-muted-foreground/40",
                    )}
                  >
                    <div className="flex items-center gap-3">
                      <RadioGroupItem value="qr_pay" />
                      <p className="flex-1 text-sm lg:text-base font-semibold">PayPay</p>
                      <PayPayBrandIcon />
                    </div>
                    {payment === "qr_pay" && (
                      <p className="mt-1.5 pl-7 text-xs text-muted-foreground">
                        {t('payByPayPay')}
                      </p>
                    )}
                    {/* plan-054 — there is deliberately NO "PayPay is not
                        available here" note.
                        It used to render on `paypayEnabled && !flowActive`,
                        which is the exact set of customers for whom it is
                        FALSE: `shouldUsePayPayQrFlow` also returns false for
                        dine-in, so a takeaway-enabled shop's dine-in guest was
                        told the shop does not accept PayPay. (#1692 removed
                        the other member of that set — a signed-in customer,
                        who now takes the QR flow like anyone else; the note
                        was already gone by then, which is why that customer
                        got NO explanation at all rather than a wrong one.)
                        The truthful case — the shop really has no PayPay QR —
                        is `!paypayEnabled`, and that one must render nothing:
                        `qr_pay` has always meant "staff settle it at the
                        till", it works, and §10.2 requires that screen to stay
                        byte-identical on every branch (all of them today).
                        Every branch of the condition therefore renders
                        nothing, so nothing is rendered. */}
                  </label>

                  {/* #2545 — lối thoát, không phải một lựa chọn: chỉ hiện khi
                      chi nhánh không có cổng online nào. */}
                  {counterPayOffered && (
                    <label
                      className={cn(
                        "cursor-pointer rounded-xl border-2 px-4 py-3 transition-all",
                        payment === "counter"
                          ? "border-primary bg-primary/5"
                          : "border-border hover:border-muted-foreground/40",
                      )}
                    >
                      <div className="flex items-center gap-3">
                        <RadioGroupItem value="counter" />
                        <p className="flex-1 text-sm lg:text-base font-semibold">{t('payAtStore')}</p>
                      </div>
                    </label>
                  )}
                </RadioGroup>
              </section>
            </div>
          )}

          {/* Payment — takeaway - separate card */}
          {!isDineIn && (
            <div
              className="rounded-xl border border-border/60 bg-card"
              style={{
                borderRadius: '12px',
                boxShadow: '0px 1px 2px -1px #0000001A, 0px 1px 3px 0px #0000001A'
              }}
            >
              <section className="p-5">
                <div className="mb-4 flex items-center gap-2">
                  <CreditCard className="h-5 w-5 text-neutral-600" />
                  <span className="text-base md:text-[20px] font-bold md:font-semibold">{t('payment')}</span>
                </div>

                <RadioGroup value={payment} onValueChange={setPayment} className="gap-2">
                  <label
                    className={cn(
                      "cursor-pointer rounded-xl px-4 transition-all flex flex-col",
                      payment === "card"
                        ? "py-3"
                        : "hover:border-muted-foreground/40 h-[58px] justify-center",
                    )}
                    style={{
                      backgroundColor: payment === "card" ? "#2D8A390D" : "transparent",
                      border: payment === "card" ? "1px solid #2D8A39" : "1px solid #E5E7EB"
                    }}
                  >
                    <div className="flex items-center gap-3 w-full">
                      <RadioGroupItem value="card" />
                      <p className="flex-1 text-sm lg:text-base font-semibold">{t('creditCard')}</p>
                    </div>
                    {payment === "card" && stripePublishableKey && (
                      <div onClick={(e) => e.preventDefault()} className="mt-3">
                        <StripeCardSection
                          ref={stripeCardRef}
                          amount={Math.max(1, Math.round(finalTotal))}
                          currency={currentBranch.currency_code ?? "JPY"} // #815 — match PI currency
                          publishableKey={stripePublishableKey}
                          // #2790 — Stripe.js refuses to confirm without these; the
                          // Element opts out of collecting them.
                          billingDetails={{ name, email, phone }}
                          // #1125 option B — this site used to omit the flag, so
                          // it always built card-only Elements. Harmless while
                          // async methods are OFF; the moment a branch enables
                          // them the intent is automatic and the confirm fails.
                          showMethodTabs={asyncMethodsEnabled}
                        />
                      </div>
                    )}
                    {payment === "card" && !stripePublishableKey && !stripeConfigLoading && (
                      <p className="mt-2 text-xs text-destructive">
                        {t('stripeNotConfigured')}
                      </p>
                    )}
                  </label>

                  <label
                    className={cn(
                      "cursor-pointer rounded-xl px-4 transition-all flex flex-col justify-center",
                      showPayPayQrHint ? "py-3" : "h-[58px]",
                      payment === "qr_pay"
                        ? ""
                        : "hover:border-muted-foreground/40",
                    )}
                    style={{
                      backgroundColor: payment === "qr_pay" ? "#2D8A390D" : "transparent",
                      border: payment === "qr_pay" ? "1px solid #2D8A39" : "1px solid #E5E7EB"
                    }}
                  >
                    <div className="flex items-center gap-3 w-full">
                      <RadioGroupItem value="qr_pay" />
                      <p className="flex-1 text-sm lg:text-base font-semibold">PayPay</p>
                      <PayPayBrandIcon />
                    </div>
                    {/* Same convention as the pay-after radios above: label on
                        top, one quiet line of what actually happens below. */}
                    {showPayPayQrHint && (
                      <p className="mt-1 pl-7 text-xs text-muted-foreground">
                        {t('payByPayPayQrHint')}
                      </p>
                    )}
                  </label>

                  {/* #2545 — lối thoát, không phải một lựa chọn: chỉ hiện khi
                      chi nhánh không có cổng online nào. */}
                  {counterPayOffered && (
                    <label
                      className={cn(
                        "cursor-pointer rounded-xl px-4 transition-all h-[58px] flex items-center",
                        payment === "counter"
                          ? ""
                          : "hover:border-muted-foreground/40",
                      )}
                      style={{
                        backgroundColor: payment === "counter" ? "#2D8A390D" : "transparent",
                        border: payment === "counter" ? "1px solid #2D8A39" : "1px solid #E5E7EB"
                      }}
                    >
                      <div className="flex items-center gap-3 w-full">
                        <RadioGroupItem value="counter" />
                        <p className="flex-1 text-sm lg:text-base font-semibold">{t('payAtStore')}</p>
                      </div>
                    </label>
                  )}
                </RadioGroup>
              </section>
            </div>
          )}

          {/* Section: Note - separate card */}
          <div
            className="rounded-xl border border-border/60 bg-card"
            style={{
              borderRadius: '12px',
              boxShadow: '0px 1px 2px -1px #0000001A, 0px 1px 3px 0px #0000001A'
            }}
          >
            <section className="p-5">
              <div className="mb-3 flex items-center gap-2">
                <MessageSquare className="h-5 w-5 text-neutral-600" />
                <span className="text-base md:text-[20px] font-bold md:font-semibold">{t('note')}</span>
              </div>
              <Textarea
                placeholder={t('notePlaceholder')}
                value={note}
                onChange={(e) => setNote(e.target.value)}
                rows={3}
                className="min-h-[100px]"
              />
            </section>
          </div>
        </div>

        {/* Desktop sidebar */}
        <aside className="mt-4 hidden lg:sticky lg:top-20 lg:mt-0 lg:block">
          <div className="rounded-xl border border-border/60 bg-card p-6 shadow-sm">
            <h2 className="text-lg font-bold">{t('orderSummaryShort')}</h2>

            {/* Cart items */}
            <div className="mt-4">
              {orderItemsList}
            </div>

            {/* Subtotal */}
            <div className="mt-4 flex items-center justify-between border-t pt-4">
              <span className="text-sm">{t('subtotalInline', { count: totalItems })}</span>
              <span className="text-base font-medium">{fmt(totalPrice)}</span>
            </div>

            {/* Coupon input (plan-019) */}
            <div className="mt-4 border-t pt-4">
              <div className="flex gap-2">
                <Input
                  placeholder={t('couponPlaceholder')}
                  className="flex-1 font-mono uppercase h-[42px]"
                  value={couponCode}
                  onChange={(e) => {
                    const next = e.target.value.toUpperCase();
                    setCouponCode(next);
                    // #1763 — emptying the field is a removal, not just an
                    // edit: otherwise the code stayed in sessionStorage and
                    // came back (with its discount) on the next mount.
                    if (!next.trim()) removeCoupon();
                  }}
                  maxLength={50}
                />
                <Button
                  type="button"
                  variant="default"
                  className="shrink-0 h-[42px]"
                  style={{ backgroundColor: '#2D8336' }}
                  disabled={!couponCode.trim() || couponPending}
                  onClick={() => {
                    setCouponSubmitAttempted(false);
                    setCouponDebounced(normalizeCouponCode(couponCode));
                  }}
                >
                  {couponPending ? <Loader2 className="size-4 animate-spin" /> : t('couponApply')}
                </Button>
              </div>
              {showUnappliedWarning && (
                <div className="mt-2 rounded-md border border-amber-300 bg-amber-50 px-2.5 py-2 text-xs text-amber-900">
                  {t('couponUnappliedWarning')}
                </div>
              )}
              {livePreview?.data?.is_valid && (
                <div className="mt-2 flex items-center justify-between gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs text-emerald-900">
                  <span>
                    {t('couponApplied', { code: couponDebounced })}
                  </span>
                  <span className="flex items-center gap-1.5">
                    <span className="font-semibold">
                      −{fmt(livePreview.data.discount_applied_amount ?? 0)}
                    </span>
                    {/* #1763 — an applied coupon had no way off the order:
                        no remove control, and emptying the field left the
                        discount standing. */}
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
              {/* Khi error_code === 'customer_required' và guest → hiển thị
                  CouponLoginPrompt thay vì block error đỏ thông thường. Sau
                  khi login, user quay về /checkout và cart-context auto-fill
                  coupon từ sessionStorage → preview chạy lại với customer_id.
                  FEATURES.auth off (#47) → không có chỗ nào để đăng nhập, rơi
                  về block lỗi coupon thường bên dưới. `authEntryPoints` off
                  cũng rơi về đó: prompt này CHÍNH LÀ một nút "Đăng nhập", chỉ
                  là nó mọc ra từ lỗi coupon thay vì đứng sẵn trên header.
                  Khách vẫn đọc được lý do coupon không dùng được, chỉ là không
                  còn lời mời đăng nhập kèm theo. */}
              {livePreview?.data?.is_valid === false
                && livePreview.data.error_code === 'customer_required'
                && FEATURES.auth && FEATURES.authEntryPoints && !isLoggedIn && (
                  <CouponLoginPrompt couponCode={couponDebounced} />
                )}
              {livePreview?.data?.is_valid === false && livePreview.data.error_code
                && !(livePreview.data.error_code === 'customer_required' && FEATURES.auth && FEATURES.authEntryPoints && !isLoggedIn) && (
                  <div className={cn(
                    "mt-2 rounded-md border px-2.5 py-2 text-xs",
                    // `coupon_not_started` là thông tin tạm thời (ưu đãi sắp đến)
                    // → dùng tone amber/warning thay vì destructive đỏ.
                    livePreview.data.error_code === 'coupon_not_started'
                      ? "border-amber-300 bg-amber-50 text-amber-900"
                      : "border-destructive/30 bg-destructive/5 text-destructive",
                  )}>
                    <div>
                      {t(`couponError.${livePreview.data.error_code}` as Parameters<typeof t>[0]) ||
                        t('couponError.generic')}
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
                        {/* Plan-019 — let the customer pick which discount to keep. Toggling
                          downgradePromos flips the request body so the order create endpoint
                          reverts HH lines to original_unit_price before applying the coupon. */}
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
              {/* Visible chip when the customer chose coupon-over-promo */}
              {downgradePromos && (
                <div className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-900">
                  {t('downgradePromosChosen')}
                </div>
              )}
            </div>

            {/* Tax + Service charge từ ShopOrderSetting (xem khối tính ở trên render) */}
            {serviceRate > 0 && (
              <div className="mt-2 flex items-center justify-between text-sm">
                <span className="text-muted-foreground">{t('serviceChargeWithRate', { rate: serviceRate })}</span>
                <span>{fmt(serviceCharge)}</span>
              </div>
            )}
            {/* plan-043 — per-rate consumption-tax preview (8%対象 / 10%対象). */}
            <TaxBreakdownLines
              breakdown={taxRows}
              isTaxIncluded={pricesIncludeTax}
              format={fmt}
              namespace="checkout"
              className="mt-1 space-y-1"
            />

            {/* Total */}
            <div className="mt-4 flex items-baseline justify-between">
              <span className="text-base md:text-[20px] font-semibold">
                {t('total')}
                <span className="ml-1.5 align-middle text-[11px] font-normal text-muted-foreground">
                  ({t('taxIncludedBadge')})
                </span>
              </span>
              <span className="text-2xl md:text-[20px] font-bold">
                {fmt(finalTotal)}
              </span>
            </div>

            {/* #1763 — no `!showUnappliedWarning` guard any more: the unapplied
                -coupon prompt is never written to `orderError`, so this box now
                only ever holds a real order failure. */}
            {orderError && (
              <div className="mt-3 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-xs text-destructive">
                {orderError}
              </div>
            )}

            {/* Submit button */}
            <Button
              className="mt-4 w-full font-bold text-base"
              style={{ backgroundColor: '#2D8336', height: '56px' }}
              disabled={isSubmitting}
              onClick={() => {
                // Always submit directly — no review step
                handleSubmit();
              }}
            >
              {isSubmitting ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <svg width="21" height="17" viewBox="0 0 21 17" fill="none" xmlns="http://www.w3.org/2000/svg" className="mr-2">
                  <path d="M0 3.375C0 2.47989 0.355579 1.62145 0.988515 0.988515C1.62145 0.355579 2.47989 0 3.375 0H17.625C18.5201 0 19.3785 0.355579 20.0115 0.988515C20.6444 1.62145 21 2.47989 21 3.375V13.125C21 14.0201 20.6444 14.8785 20.0115 15.5115C19.3785 16.1444 18.5201 16.5 17.625 16.5H3.375C2.47989 16.5 1.62145 16.1444 0.988515 15.5115C0.355579 14.8785 0 14.0201 0 13.125V3.375ZM3.375 1.5C2.87772 1.5 2.40081 1.69754 2.04917 2.04917C1.69754 2.40081 1.5 2.87772 1.5 3.375V4.5H19.5V3.375C19.5 2.87772 19.3025 2.40081 18.9508 2.04917C18.5992 1.69754 18.1223 1.5 17.625 1.5H3.375ZM1.5 13.125C1.5 13.6223 1.69754 14.0992 2.04917 14.4508C2.40081 14.8025 2.87772 15 3.375 15H17.625C18.1223 15 18.5992 14.8025 18.9508 14.4508C19.3025 14.0992 19.5 13.6223 19.5 13.125V6H1.5V13.125ZM14.25 10.5H16.5C16.6989 10.5 16.8897 10.579 17.0303 10.7197C17.171 10.8603 17.25 11.0511 17.25 11.25C17.25 11.4489 17.171 11.6397 17.0303 11.7803C16.8897 11.921 16.6989 12 16.5 12H14.25C14.0511 12 13.8603 11.921 13.7197 11.7803C13.579 11.6397 13.5 11.4489 13.5 11.25C13.5 11.0511 13.579 10.8603 13.7197 10.7197C13.8603 10.579 14.0511 10.5 14.25 10.5Z" fill="white" />
                </svg>
              )}
              {isDineIn ? t('placeOrderBtnDineIn') : t('placeOrderBtnPay')}
            </Button>
          </div>
        </aside>
      </div>

      {/* Mobile sticky footer */}
      <div className="fixed bottom-0 left-0 right-0 z-20 border-t bg-card lg:hidden">
        {orderError && (
          <div className="border-t border-destructive/20 bg-destructive/5 px-4 py-2 text-center">
            <p className="text-xs text-destructive">{orderError}</p>
          </div>
        )}
        <div className="mx-auto flex max-w-2xl items-center justify-between px-4 py-3">
          <div>
            <p className="text-xs text-muted-foreground">{t('total')}</p>
            <p className="text-xl font-bold">
              {fmt(finalTotal)}
            </p>
          </div>
          <Button
            size="lg"
            className="gap-2 px-8 font-bold"
            disabled={isSubmitting}
            onClick={() => {
              // Always submit directly — no review step
              handleSubmit();
            }}
          >
            {isSubmitting
              ? <Loader2 className="h-4 w-4 animate-spin" />
              : <UtensilsCrossed className="h-4 w-4" />}
            {isDineIn
              ? (dineInTable ? t('placeOrderDineInTable', { number: dineInTable.number }) : t('placeOrderDineIn'))
              : t('payButton')}
          </Button>
        </div>
      </div>

      <RemoveItemConfirmDialog
        open={removeTarget !== null}
        onOpenChange={(o) => !o && setRemoveTarget(null)}
        onConfirm={() => {
          if (removeTarget) removeFromCart(removeTarget);
          setRemoveTarget(null);
        }}
      />
    </div>
  );
}
