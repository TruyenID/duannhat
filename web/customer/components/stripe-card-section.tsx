"use client";

import {
  forwardRef,
  useEffect,
  useImperativeHandle,
  useMemo,
  useRef,
  useState,
} from "react";
import { useLocale, useTranslations } from "next-intl";
import { loadStripe, type Stripe, type PaymentIntentResult } from "@stripe/stripe-js";
import {
  Elements,
  PaymentElement,
  useElements,
  useStripe,
} from "@stripe/react-stripe-js";
import { ZERO_FRACTION, THREE_DECIMAL } from "@/lib/split-rounding";
import { formatCurrency } from "@/lib/currency";
// #1427 — the table moved to lib/ so the pay screen's Stripe radio and this
// card frame read the same limit. Behaviour here is unchanged.
import { stripeMinimumFor } from "@/lib/stripe-minimum";
import { createDeferredSubmitGate } from "@/lib/deferred-submit-gate";
import {
  CARD_BILLING_FIELDS,
  billingDetailsForConfirm,
  type StripeBillingDetails,
} from "@/lib/stripe-billing-details";
import { decideConfirmOutcome } from "@/lib/stripe-confirm-outcome";

/**
 * #815 — convert a MAJOR-unit amount to Stripe minor units for the given
 * currency, mirroring the backend ZeroDecimalCurrency table. Elements deferred
 * mode expects the smallest currency unit and must equal the PaymentIntent
 * amount, or Stripe.js throws at confirm. Zero-decimal (JPY/VND/…) ×1,
 * three-decimal (KWD/…) ×1000, everything else ×100.
 */
function toMinorUnits(amount: number, currency: string): number {
  const upper = currency.toUpperCase();
  const factor = ZERO_FRACTION.has(upper) ? 1 : THREE_DECIMAL.has(upper) ? 1000 : 100;
  return Math.max(1, Math.round(amount * factor));
}

// Cache Stripe instance per publishable key — loadStripe khuyến nghị singleton.
const stripeCache = new Map<string, Promise<Stripe | null>>();

function getStripe(publishableKey: string): Promise<Stripe | null> {
  if (!stripeCache.has(publishableKey)) {
    stripeCache.set(publishableKey, loadStripe(publishableKey));
  }
  return stripeCache.get(publishableKey)!;
}

export type { StripeBillingDetails };

export interface StripeCardSectionHandle {
  /**
   * Validate card fields locally (không gọi Stripe API). Parent gọi hàm này
   * trước khi tạo PaymentIntent để tránh race "đơn đã tạo nhưng form thẻ sai".
   */
  validate: () => Promise<{ error?: string }>;
  /**
   * Confirm payment với client_secret vừa nhận từ backend.
   * IMPORTANT: Sử dụng chính client_secret này để confirm, KHÔNG tạo intent mới.
   */
  confirm: (
    clientSecret: string,
    returnUrl: string,
  ) => Promise<{
    succeeded: boolean;
    /**
     * #1125 option B — async method awaiting settlement: konbini voucher
     * printed / bank transfer initiated. NOT an error — the parent shows an
     * awaiting screen and the webhook settles the order later.
     */
    pending?: boolean;
    error?: string;
  }>;
  /**
   * Update Elements với clientSecret từ backend sau khi PaymentIntent được tạo.
   */
  setClientSecret: (clientSecret: string) => void;
}

interface Props {
  amount: number;
  currency: string;
  publishableKey: string;
  /**
   * #1125 option B — render Stripe's payment-method tabs (Konbini, 銀行振込…)
   * when the branch has async methods enabled. Default false = card-only look.
   */
  showMethodTabs?: boolean;
  /**
   * #2790 — guest identity our own form already collected. Required by
   * Stripe.js because `fields.billingDetails` opts out of collecting it inside
   * the Element; the caller passing nothing is fine (each value goes as `null`),
   * the caller not wiring this at all is what broke card payments.
   */
  billingDetails?: StripeBillingDetails;
}

/**
 * Map next-intl locale code → Stripe Elements supported locale code.
 * Stripe accepts {en, vi, ja, ...} hoặc "auto". Default về "auto" để
 * Stripe đọc browser Accept-Language khi locale không trong whitelist.
 */
function resolveStripeLocale(code: string): "vi" | "ja" | "en" | "auto" {
  switch (code) {
    case "vi": return "vi";
    case "ja": return "ja";
    case "en": return "en";
    default: return "auto";
  }
}

export const StripeCardSection = forwardRef<StripeCardSectionHandle, Props>(
  function StripeCardSection({ amount, currency, publishableKey, showMethodTabs = false, billingDetails }, ref) {
    const locale = useLocale();
    const stripeLocale = resolveStripeLocale(locale);
    const stripePromise = useMemo(
      () => getStripe(publishableKey),
      [publishableKey],
    );

    // Use payment mode with deferred amount — Elements will collect card,
    // then we provide the actual clientSecret when calling confirm.
    return (
      <Elements
        // Elements locks its payment-method configuration at MOUNT —
        // `paymentMethodTypes` is not an updatable option, so a later flip is
        // silently ignored (Stripe.js warns and keeps the original). Keying on
        // the flag remounts the group instead, which is the only way a branch
        // whose policy prime lands after first paint gets the right config.
        key={showMethodTabs ? "automatic" : "card"}
        stripe={stripePromise}
        options={{
          mode: "payment",
          // #815 — minor units for the currency (was Math.round(amount), which
          // only matched the PaymentIntent for zero-decimal currencies like JPY).
          amount: toMinorUnits(amount, currency),
          currency: currency.toLowerCase(),
          // The Elements payment-method configuration MUST match how the
          // backend created the PaymentIntent. In deferred mode, OMITTING
          // `paymentMethodTypes` means automatic payment methods — and Stripe
          // refuses to confirm an automatic-methods Element against an intent
          // created with `payment_method_types`: "Payment details were
          // collected through Stripe Elements using automatic payment methods
          // and cannot be confirmed through the API configured with
          // payment_method_types or allowed_payment_method_types."
          //
          // Backend side is StripePaymentService::browserIntentMethodParams():
          // card-only while payments.async_payment_methods is OFF (the shipped
          // default), automatic_payment_methods once it is ON. `showMethodTabs`
          // carries that same server flag, so the two stay in step.
          ...(showMethodTabs ? {} : { paymentMethodTypes: ["card"] }),
          appearance: {
            theme: "stripe",
            variables: {
              colorPrimary: "#2D8336",
              colorBackground: "#ffffff",
              colorText: "#1f2937",
              colorDanger: "#dc2626",
              fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif",
              // Banner Link ("Thanh toán nhanh và an toàn với Link") dùng cỡ chữ
              // base/sm này. Set 14px theo yêu cầu UI; ô nhập thẻ (.Input 15px)
              // và .Label (16px) đã ghim cỡ riêng ở `rules` bên dưới nên không
              // bị ảnh hưởng. Đây là cách HỢP LỆ duy nhất tác động vào iframe —
              // Stripe đọc variable này và apply bên trong iframe của họ.
              fontSizeBase: "14px",
              fontSizeSm: "14px",
              spacingUnit: "4px",
              borderRadius: "6px",
              colorTextPlaceholder: "#9ca3af",
            },
            rules: {
              ".Label": {
                fontSize: "16px",
                fontWeight: "400",
                marginBottom: "8px",
                color: "#374151",
              },
              ".Input": {
                fontSize: "15px",
                padding: "12px 14px",
                border: "1px solid #e5e7eb",
                boxShadow: "none",
                backgroundColor: "#ffffff",
              },
              ".Input:focus": {
                border: "1px solid #2D8336",
                boxShadow: "0 0 0 1px #2D8336",
                outline: "none",
              },
              ".Input::placeholder": {
                color: "#9ca3af",
              },
              ".Error": {
                fontSize: "13px",
                color: "#dc2626",
                marginTop: "4px",
              },
              // [Removed #2790] `.Tab` / `.TabLabel` / `.RedirectText` /
              // `.p-Message` / `.p-Message--informational` với `display` —
              // Stripe.js từ chối cả selector lẫn thuộc tính
              // ("display" is not a supported property for ".Tab"), nên chúng
              // CHƯA BAO GIỜ ẩn được gì. Ở chế độ card-only thì
              // `paymentMethodTypes: ["card"]` chỉ sinh MỘT phương thức, nên
              // không có tab nào để ẩn ngay từ đầu.
              // Bỏ outer border/shadow quanh PaymentElement wrapper. KHÔNG bỏ
              // padding vì padding của các container này lại là spacing cho
              // field grid bên trong (Ngày hết hạn + CVC cần ngang hàng).
              // [Removed #2790] `.PaymentMethodItem` — "is not a supported
              // class"; `.AccordionItem` ngay dưới mới là class thật.
              ".AccordionItem": {
                border: "none",
                boxShadow: "none",
              },
              ".Block": {
                border: "none",
                boxShadow: "none",
              },
              // [Removed] Rules style banner "Thanh toán nhanh và an toàn với
              // Link" — class .LinkAutofillPromptBody-blockHeader không nằm
              // trong list selectors được Stripe appearance API support, nên
              // không có tác dụng. Banner giữ default Stripe styling.
              //
              // [Removed #2790] Sáu rule ẩn header "Thẻ" —
              // .PaymentMethodTitle · .AccordionItemHeader · .AccordionTrigger
              // · .AccordionItemTitle · .MethodLabel · .MethodIcon.
              // (#2804 sửa bảng kê: bản đầu ghi "tám" vì đếm .PaymentMethodItem
              // lần thứ hai — nó đã có mục riêng ở trên — và gộp nhầm .p-Message
              // vào đây, trong khi .p-Message/.p-Message--informational thuộc
              // nhóm .RedirectText. Tổng thật của cả PR vẫn là 12 selector, đếm
              // bằng `git show 9fcabf794 -- <file> | grep -cE '^-\s+"\.'`.)
              // Stripe.js từ chối TỪNG CÁI lúc mount
              // ("is not a supported class" / "invalid selector … namespaces are
              // not supported") — đo bằng console warning trong Chromium, xem
              // thân PR. Chúng chưa bao giờ có tác dụng, nên chúng KHÔNG phải
              // nguyên nhân của #2790; giữ lại chỉ để lại ấn tượng sai rằng
              // header đang bị ẩn từ đây.
            },
          },
          locale: stripeLocale,
        }}
      >
        <InnerForm ref={ref} amount={amount} currency={currency} showMethodTabs={showMethodTabs} billingDetails={billingDetails} />
      </Elements>
    );
  },
);

const InnerForm = forwardRef<
  StripeCardSectionHandle,
  { amount: number; currency: string; showMethodTabs?: boolean; billingDetails?: StripeBillingDetails }
>(function InnerForm({ amount, currency, showMethodTabs = false, billingDetails }, ref) {
  const stripe = useStripe();
  const elements = useElements();
  const [ready, setReady] = useState(false);
  // #815 — set when Stripe.js fires `loaderror` on the PaymentElement (e.g. the
  // branch currency has no activated payment method, or the amount is below the
  // currency minimum). Ref mirror so the imperative validate/confirm read a fresh
  // value from their closures.
  const [loadError, setLoadError] = useState<string | null>(null);
  const loadErrorRef = useRef<string | null>(null);
  const t = useTranslations("stripe");

  // Stabilise stripe/elements qua closure — confirm() có thể được gọi sau khi
  // component re-render nhiều lần.
  const stripeRef = useRef(stripe);
  const elementsRef = useRef(elements);
  stripeRef.current = stripe;
  elementsRef.current = elements;
  // Stripe deferred Payment Element: submit() ONCE per Pay click, before the
  // async PI create, then confirmPayment without submitting again
  // (docs.stripe.com/payments/accept-a-payment-deferred). A second submit()
  // after full-payment-intent left live PIs at requires_payment_method with
  // no payment_method — ORD-2026-0237 and 20 customer_web attempts since 11 Aug.
  const submitGateRef = useRef(createDeferredSubmitGate());
  // #2790 — read through a ref: useImperativeHandle below has `[]` deps, so a
  // closure over the prop would freeze the guest details captured at mount.
  const billingRef = useRef(billingDetails);
  billingRef.current = billingDetails;

  useImperativeHandle(
    ref,
    () => ({
      validate: async () => {
        if (loadErrorRef.current) return { error: loadErrorRef.current };
        const elementsInst = elementsRef.current;
        if (!elementsInst) return { error: t('notReady') };
        const { error } = await elementsInst.submit();
        if (error) {
          submitGateRef.current.onValidateError();
          return { error: error.message ?? t('cardInvalid') };
        }
        submitGateRef.current.onValidateOk();
        return {};
      },
      setClientSecret: (_clientSecret: string) => {
        // Not needed with payment mode - Elements auto-uses the clientSecret from confirm()
        // KHÔNG log clientSecret (kể cả substring) — Stripe docs: client_secret
        // là sensitive, attacker có thể dùng để confirm intent nếu leak qua
        // browser extension / devtools / log aggregator.
      },
      confirm: async (clientSecret, returnUrl) => {
        if (loadErrorRef.current) return { succeeded: false, error: loadErrorRef.current };
        const stripeInst = stripeRef.current;
        const elementsInst = elementsRef.current;
        if (!stripeInst || !elementsInst) {
          return { succeeded: false, error: t('notReady') };
        }

        // KHÔNG log `clientSecret` ra console — Stripe docs: client_secret là
        // sensitive, có thể bị attacker dùng để confirm intent nếu leak qua
        // browser extension / shared devtools.

        // submit() already ran in validate() on this click. Only submit here
        // when confirm() is called without validate() (no caller does that
        // today; keep the fallback so a future caller still matches Stripe).
        if (submitGateRef.current.shouldSubmitOnConfirm()) {
          const { error: submitError } = await elementsInst.submit();
          if (submitError) {
            console.error('[StripeCardSection] Submit error:', submitError);
            submitGateRef.current.onFallbackSubmitError();
            return { succeeded: false, error: submitError.message ?? "Validation failed." };
          }
          submitGateRef.current.onFallbackSubmitOk();
        }

        // confirmPayment with explicit clientSecret from backend PaymentIntent
        //
        // #2790 — `payment_method_data.billing_details` is NOT optional here.
        // `fields.billingDetails` opts out of collecting name/email/phone in
        // the Element, and Stripe.js then THROWS an IntegrationError at confirm
        // when the same keys are missing from confirmParams. It throws before
        // any network call, so the PaymentIntent stays `requires_payment_method`
        // with `payment_method: null` and `last_payment_error: null` — exactly
        // the production shape, and invisible to the backend.
        // Values we do not have go as `null`; the KEY must still be present.
        let result: PaymentIntentResult;
        try {
          result = await stripeInst.confirmPayment({
            elements: elementsInst,
            clientSecret, // ← Backend's PaymentIntent client_secret
            confirmParams: {
              return_url: returnUrl,
              payment_method_data: {
                billing_details: billingDetailsForConfirm(billingRef.current),
              },
            },
            redirect: "if_required",
          });
        } catch (thrown) {
          // Stripe.js THROWS (không reject kèm `error`) với lỗi tích hợp. Trước
          // #2790 nó rơi vào catch chung của caller và khách nhận câu generic,
          // không gì tới được server.
          //
          // #2804 — chỉ log TÊN + THÔNG ĐIỆP, không log nguyên object: object
          // ném ra có thể mang payment_method id / raw_message, đúng thứ mà
          // luật ngay dưới đây cấm. Và khách nhận KHOÁ i18n, không nhận câu
          // tiếng Anh thô của Stripe — khách Nhật đọc không hiểu, mà câu đó
          // cũng là thông điệp dành cho lập trình viên.
          const name = thrown instanceof Error ? thrown.name : typeof thrown;
          const message = thrown instanceof Error ? thrown.message : '';
          console.error('[StripeCardSection] confirmPayment threw:', name, message);
          submitGateRef.current.onConfirmOutcome("error");
          return { succeeded: false, error: t('cardNotCharged') };
        }

        // KHÔNG log paymentIntent.id / status — PI id là sensitive metadata,
        // dùng để tra/manipulate intent qua API.
        const error = result.error;
        const paymentIntent = "paymentIntent" in result ? result.paymentIntent : undefined;

        if (error) {
          // Stripe error code/type là an toàn để log; KHÔNG log full error
          // object vì có thể chứa payment_method id hoặc raw_message PII.
          console.error('[StripeCardSection] Confirm error:', error.code, error.type);
          submitGateRef.current.onConfirmOutcome("error");
          return { succeeded: false, error: error.message ?? "Payment failed." };
        }
        // #1125 option B — konbini/bank-transfer confirms resolve with the
        // intent in `processing` (funds in transit) or `requires_action`
        // (Stripe.js just displayed the voucher / instructions modal). That is
        // an AWAITING state, not a failure: the backend tracks a pending row
        // and the webhook settles the order when the money lands.
        // #2804 — luật phân loại sống ở `lib/stripe-confirm-outcome.ts` để test
        // được mà không phải dựng React + Stripe.js. Trước khi tách, tiêm lại
        // đúng lỗi #2790 vào chỗ này vẫn cho 594/594 test xanh.
        const decision = decideConfirmOutcome(paymentIntent?.status);
        submitGateRef.current.onConfirmOutcome(decision.gate);
        if (decision.pending) {
          return { succeeded: false, pending: true };
        }
        if (decision.errorKey) {
          // KHÔNG log `status` — nó là metadata của intent, cùng họ với PI id
          // mà luật ngay trên cấm log. Mã lỗi cho người vận hành đi qua thông
          // điệp i18n mà khách nhìn thấy, không qua console.
          console.error('[StripeCardSection] confirm không đạt succeeded');
          return { succeeded: false, error: t(decision.errorKey) };
        }
        return { succeeded: true };
      },
    }),
    [],
  );

  useEffect(() => {
    if (stripe && elements) setReady(true);
  }, [stripe, elements]);

  // [Removed] useEffect cố style banner "Thanh toán nhanh và an toàn với Link"
  // — confirm element ở trong iframe cross-origin của Stripe. Browser
  // Same-Origin Policy + Stripe PCI compliance không cho phép parent
  // document chạm vào CSS/DOM của iframe đó. Banner giữ default Stripe styling.

  return (
    <div className="w-full">
      <style>{`
        ${showMethodTabs ? "" : `/* Hide tabs (card-only mode) */
        .p-Tab,
        .p-TabLabel,
        .p-Tabs,
        [role="tab"],
        [role="tablist"] {
          display: none !important;
        }`}

        /* Hide ALL Link-related messages - aggressive approach */
        .p-LinkDescription,
        .p-LinkAction,
        .p-RedirectText,
        .p-Message,
        .p-Message--informational,
        .p-Message--info,
        .p-LinkAuthenticationRedirectText,
        [class*="RedirectText"],
        [class*="LinkDescription"],
        [class*="LinkAction"],
        [class*="LinkAuthentication"],
        [class*="Message"],
        div[class*="Message"],
        p[class*="Message"],
        span[class*="Link"] {
          display: none !important;
          visibility: hidden !important;
          max-width: 0 !important;
          max-height: 0 !important;
          overflow: hidden !important;
          opacity: 0 !important;
          position: absolute !important;
          left: -9999px !important;
        }

        /* [Removed] "Hide any div/p containing Link text - nuclear option"
           rule cũ áp max-height 20px lên MỌI div con của #payment-element,
           kéo cả grid container của field rows, fields rớt dọc và dồn về trái.
           Rule này bị gỡ để Stripe field grid hoạt động đúng. */

        /* Force single line on mobile */
        @media (max-width: 768px) {
          .p-Field,
          .p-Field-wrapper {
            font-size: 14px !important;
          }
        }

        /* Left align everything INSIDE the payment element only.
           Bug history: trước đây selector là bare '*' → áp text-align:left
           toàn document khi component mount, kéo lệch Dialog/Sheet titles,
           tổng tiền centered, footer copyright ở /checkout và dine-in
           /payment. Scope vào '#payment-element *' để chỉ ảnh hưởng iframe
           wrapper của Stripe Elements. */
        #payment-element * {
          text-align: left !important;
        }

        /* Bỏ outer border + padding quanh PaymentElement (theo yêu cầu UI).
           Target các class wrapper mà Stripe sinh ra cho payment method panel. */
        /* Chỉ áp dụng cho wrapper ngoài cùng — KHÔNG dùng "#payment-element > div"
           vì selector đó match cả các field rows bên trong, vỡ layout grid
           (Ngày hết hạn / Mã bảo mật trở thành dọc thay vì ngang). */
        .p-PaymentMethod,
        .p-PaymentMethodSelector,
        .PaymentMethodSelector {
          border: none !important;
          box-shadow: none !important;
        }

        /* [Removed] Toàn bộ block style banner Link đã bị gỡ — selectors có
           dạng class-contains "LinkAutofillPrompt" quá rộng, match nhầm form
           container và apply display flex/gap → squish/vỡ field grid (Ngày
           hết hạn, CVC rớt dọc, dồn về trái). Banner Link giữ default Stripe. */

        /* Ẩn header "Thẻ" (card icon + label) — Stripe render qua nhiều
           class name khác nhau tùy version: PaymentMethodTitle, AccordionItem
           Header, AccordionTrigger, MethodLabel, MethodIcon, MethodTabIcon,
           MethodTabLabel, … Target rộng để bắt mọi case. */
        .p-PaymentMethodTitle,
        .PaymentMethodTitle,
        .p-AccordionItemHeader,
        .AccordionItemHeader,
        .p-AccordionTrigger,
        .AccordionTrigger,
        .p-AccordionItemTitle,
        .AccordionItemTitle,
        .p-Method,
        .Method,
        .p-MethodLabel,
        .MethodLabel,
        .p-MethodTabLabel,
        .MethodTabLabel,
        .p-MethodIcon,
        .MethodIcon,
        .p-PaymentMethodMessaging,
        .PaymentMethodMessaging,
        button[aria-expanded][aria-controls*="accordion"],
        button[aria-expanded][aria-controls*="payment"],
        [class*="PaymentMethodTitle"],
        [class*="AccordionItemHeader"],
        [class*="AccordionTrigger"],
        [class*="MethodLabel"],
        [class*="MethodIcon"] {
          display: none !important;
        }
      `}</style>
      {loadError && (
        <div className="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          {loadError}
        </div>
      )}
      {!ready && !loadError && (
        <div className="h-48 animate-pulse rounded-lg bg-muted/40" />
      )}
      <PaymentElement
        onLoadError={(event) => {
          // #815 — the branch currency/amount can't be charged in this Stripe
          // account (currency not activated, or amount below the currency minimum).
          // Handle the event (so it's not an unhandled Stripe.js loaderror) and show
          // a friendly fallback; validate()/confirm() then block submission. When the
          // order is below the currency's Stripe minimum, tell the customer that
          // minimum so they understand why card isn't available.
          console.warn('[StripeCardSection] PaymentElement load error:', event?.error?.message);
          const min = stripeMinimumFor(currency);
          const msg = min !== undefined && amount < min
            ? t('cardBelowMinimum', { min: formatCurrency(min, currency) })
            : t('cardUnavailable');
          loadErrorRef.current = msg;
          setLoadError(msg);
        }}
        options={{
          // Wallets ride the card PaymentIntent — Stripe shows them when the
          // Dashboard has Apple/Google Pay enabled and the device is eligible.
          layout: showMethodTabs ? "tabs" : "auto",
          wallets: {
            applePay: "auto",
            googlePay: "auto",
          },
          terms: {
            card: "never",
          },
          // Card networks + brand detection render inside Stripe's Payment Element.
          // Do not collect billing address here — guest name/phone live on our form.
          // #2790 — `address: "never"` made Stripe.js demand
          // `billing_details.address.country` AND `.postal_code` at confirm,
          // and we have neither (the guest never gives an address). Measured in
          // Chromium: with `never` the confirm throws; with `auto` the same card
          // reaches `succeeded`. `auto` lets Stripe collect only what the card
          // network actually needs. name/email/phone stay opted out — our own
          // form has them and confirm() now passes them through.
          fields: { billingDetails: CARD_BILLING_FIELDS },
        }}
      />
    </div>
  );
});
