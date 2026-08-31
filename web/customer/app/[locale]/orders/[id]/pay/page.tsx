"use client";

/**
 * #370 — Counter-pay takeaway customer switched mind → online card flow.
 *
 * Reached from /orders/[id] via the "Pay now" CTA. Loads the existing
 * order, creates a full-amount PaymentIntent, runs Stripe Elements
 * confirmation, then redirects to /order-success. Mirrors the
 * `checkout-page` card path but skips cart/customer-form steps because
 * the order is already created.
 *
 * plan-054 — this is also the PayPay dynamic-QR screen. Checkout sends
 * `qr_pay` orders here with `?method=paypay` on branches whose
 * `payment-context.paypay_enabled` is true. No new route: this page already
 * had the four things a QR screen needs — reload safety (the order id is in
 * the URL and is refetched on mount), the guest-order access gate, the
 * "already paid" gate, and a settlement watcher that redirects to
 * /order-success. See plan-054 §10.3.
 *
 * Which means TWO arrivals share this file, and they are not the same screen —
 * see `shouldShowPayMethodChooser`. With `?method=paypay` the method was just
 * chosen at checkout, so the page is the QR and nothing else: no radios (the
 * choice is made, and re-picking here voids the live code), no amount hero
 * (the panel already states amount + order code, so it only pushed the QR down
 * the page). Without the hint — the "Pay now" CTA on `/orders` and
 * `/orders/[id]` — nothing has been chosen and the chooser is the whole point.
 */

import { useEffect, useRef, useState } from "react";
import { useParams, useSearchParams } from "next/navigation";

import { useRouter } from "@/i18n/routing";
import { useTranslations } from "next-intl";
import {
  AlertCircle,
  ArrowLeft,
  ArrowRight,
  CreditCard,
  Globe,
  Loader2,
  Lock,
  Receipt,
} from "lucide-react";

import { QRCodeSVG } from "qrcode.react";
import Header from "@/components/Header";
import { Button } from "@/components/ui/button";
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group";
import { Label } from "@/components/ui/label";
import {
  StripeCardSection,
  type StripeCardSectionHandle,
} from "@/components/stripe-card-section";
import { PayPayBrandIcon } from "@/components/payment-brand-icons";
import { useStripeConfig } from "@/lib/stripe-config";
import { apiFetch, ApiError } from "@/lib/api";
import { formatCurrency } from "@/lib/currency";
import { shortOrderCode } from "@/lib/utils";
import { useAuth } from "@/context/auth-context";
import { loadGuestOrders } from "@/lib/guest-orders";
import { useOrderSettlement } from "@/hooks/use-order-settlement";
import { paymentPolicyEcho, primePaymentPolicyContext } from "@/lib/payment-policy-context";
import { useAsyncPaymentMethods } from "@/hooks/use-async-payment-methods";
import { isOrderStillPayable } from "@/lib/order-expiry";
import { usePayPayAvailability } from "@/hooks/use-paypay-availability";
import { PayPayQrPanel } from "@/components/paypay-qr-panel";
import { usePayPayOrphanWatch } from "@/hooks/use-paypay-orphan-watch";
import { isPayPayQrRequested, shouldShowPayMethodChooser } from "@/lib/paypay-qr";
import { isBelowStripeMinimum, stripeMinimumFor } from "@/lib/stripe-minimum";
import { shouldOfferCounterPay, shouldShowCounterPayQr } from "@/lib/counter-pay";

interface OrderForPay {
  id: string;
  code: string;
  status: string;
  /** plan-048 T2.5 — anchors the payment-context fetch for the policy echo. */
  branch_slug?: string | null;
  total: number;
  /** ISO 4217 currency the order was created in (from its branch). Money on
   *  this page formats with THIS, not the ambient selected-branch currency,
   *  so a JPY order never renders as USD when another branch is selected. */
  currency: string;
  /** #815 — the CHARGE currency (branch shop_order_settings.currency_code, JPY
   *  default). Drives the Stripe Elements currency, which MUST equal the
   *  PaymentIntent currency; may differ from the display `currency` above. */
  charge_currency?: string;
  is_fully_paid: boolean;
  payment_due_at: string | null;
  order_type: string;
}

type State =
  | { kind: "loading" }
  | { kind: "ok"; order: OrderForPay }
  | { kind: "forbidden" }
  | { kind: "ineligible"; reason: string }
  | { kind: "error"; message: string };

type PayMethod = "online" | "counter" | "paypay";

export default function PayExistingOrderPage() {
  const t = useTranslations("guestOrders");
  const tCommon = useTranslations("common");
  const tPayPay = useTranslations("paypay");
  const { id } = useParams<{ id: string }>();
  const searchParams = useSearchParams();
  const router = useRouter();
  const { isLoggedIn, isLoading: authLoading } = useAuth();

  // plan-054 — checkout hands the customer over with `?method=paypay`. The
  // hint only preselects; it never grants anything the guest-order gate below
  // would otherwise refuse.
  const paypayRequested = isPayPayQrRequested(searchParams.get("method"));

  // The hinted arrival has already answered "how do you want to pay?" — the
  // radios stay off unless PayPay turns out to be unpayable and the customer
  // takes the not-available card's way out. See `shouldShowPayMethodChooser`.
  const [methodOptOut, setMethodOptOut] = useState(false);
  const chooserVisible = shouldShowPayMethodChooser({
    hintedMethod: searchParams.get("method"),
    optedOut: methodOptOut,
  });

  const [state, setState] = useState<State>({ kind: "loading" });
  const [submitting, setSubmitting] = useState(false);
  const [payError, setPayError] = useState<string | null>(null);
  // #1125 option B — konbini voucher printed / bank transfer initiated: the
  // guest finishes at the store; the webhook settles the order later.
  const [awaitingAsync, setAwaitingAsync] = useState(false);
  const [method, setMethod] = useState<PayMethod>(paypayRequested ? "paypay" : "online");

  const stripeCardRef = useRef<StripeCardSectionHandle>(null);
  const { config: stripeConfig, loading: stripeConfigLoading } = useStripeConfig();

  // Which branch to ask about PayPay. Resolved once the order loads (see the
  // effect below) rather than read during render, because the fallback source
  // is localStorage, which does not exist on the server.
  const [branchSlug, setBranchSlug] = useState<string | null>(null);
  const {
    paypayEnabled,
    loading: paypayLoading,
    counter: counterPaySettings,
  } = usePayPayAvailability(branchSlug);

  // #1125 option B — must be read at the top level (Elements is configured
  // from it before the early returns below) and must key on the SAME slug the
  // prime used: `order.branch_slug`, not the PayPay slug above, which falls
  // back to the localStorage pointer and can hold a placeholder.
  const asyncMethodsEnabled = useAsyncPaymentMethods(
    state.kind === "ok" ? state.order.branch_slug : null,
  );

  // #1427 — can this order be put on a card at all? Same (amount, currency)
  // pairing the card frame itself uses below: `order.total` against the CHARGE
  // currency, because that is what becomes the PaymentIntent. Answering it here
  // instead of waiting for Stripe's `onLoadError` is the whole point — the
  // customer sees WHICH choice is unavailable, on the choice, rather than
  // picking "pay online" and finding a warning where the card form should be.
  const stripeChargeCurrency = state.kind === "ok" ? (state.order.charge_currency ?? "JPY") : null;
  const stripeBelowMinimum =
    state.kind === "ok" && isBelowStripeMinimum(state.order.total, stripeChargeCurrency);
  const stripeMinimum = stripeMinimumFor(stripeChargeCurrency);
  const stripeMinimumLabel =
    stripeMinimum !== undefined && stripeChargeCurrency
      ? formatCurrency(stripeMinimum, stripeChargeCurrency)
      : null;

  // Never leave a disabled choice selected. `online` is the default, so a
  // too-small order would otherwise open on a radio the customer cannot use.
  //
  // Derived, NOT an effect that rewrites `method`: the order total arrives
  // after mount, so a state-writing effect would render the dead selection once
  // and then move it under the customer (and it trips
  // `react-hooks/set-state-in-effect`). Falling back at read time means the
  // screen is never in the impossible state to begin with.
  //
  // It falls back to the COUNTER, not to PayPay, even where PayPay is
  // available: `PayPayQrPanel` mints a code on mount and minting invalidates
  // any outstanding one, so auto-selecting it would spend a real QR — with its
  // own countdown — on a customer who never asked for PayPay. The counter QR is
  // inert, and PayPay stays one deliberate tap away in the group above.
  // #2806 — chào trả tại quầy hay không là CỜ CỦA CHI NHÁNH, không còn suy ra
  // từ trạng thái cổng (#2545). Xem `lib/counter-pay.ts`.
  const counterPayOffered = shouldOfferCounterPay(counterPaySettings);

  // Đường lùi "đơn dưới mức tối thiểu của thẻ" (xem chú thích ngay trên) chỉ
  // còn chạy khi counter thật sự có trên màn.
  //
  // Trước #2806 vế `stripeBelowMinimum` được OR thẳng vào `counterPayOffered`,
  // vì khi đó counter chỉ vắng mặt ở chi nhánh CÓ cổng online — tức luôn có một
  // đích khác để đi. Nay quán tắt được counter một cách tường minh, và ép nó
  // hiện lại sẽ đẩy khách tới một cái quầy mà quán vừa nói là không thu tiền.
  //
  // Nên cờ của quán thắng, và `effectiveMethod` phải đi theo: nó KHÔNG BAO GIỜ
  // được trỏ vào một radio không render. Khách ở chi nhánh đã tắt counter, đơn
  // dưới mức tối thiểu thẻ, sẽ đứng lại ở `online` cùng câu báo "thẻ cần tối
  // thiểu …" — một ngõ cụt, nhưng là ngõ cụt do quán tự chọn và nói đúng lý do,
  // thay vì một radio disabled không giải thích gì.
  const effectiveMethod: PayMethod =
    stripeBelowMinimum && method === "online" && counterPayOffered ? "counter" : method;

  // Auth gate: a GUEST must own the order via its localStorage pointer — that
  // pointer is the only thing standing between a stranger and someone else's
  // order screen. A logged-in customer needs no pointer: they reached this
  // screen from their own BE-backed order list.
  //
  // This used to bounce logged-in customers to /account/orders/{id}, which
  // shows a receipt and no way to pay — so an unpaid takeaway order placed
  // while signed in had no settle path at all in the /account namespace. That
  // was invisible while /account/orders had no Pay button; it stops being
  // invisible now that its cards carry one.
  useEffect(() => {
    if (authLoading) return;

    const pointer = isLoggedIn
      ? undefined
      : loadGuestOrders().find((o) => o.id === id);
    if (!isLoggedIn && !pointer) {
      setState({ kind: "forbidden" });
      return;
    }

    let cancelled = false;
    apiFetch<{ data: OrderForPay }>(`/api/v1/customer/orders/${id}`, {
      silent401: true,
    })
      .then((res) => {
        if (cancelled) return;
        const o = res.data;
        // plan-054 T6.10 — the gate the /orders/[id] "Pay now" CTA uses, now
        // literally the same function instead of two copies that had drifted.
        if (!isOrderStillPayable(o)) {
          setState({ kind: "ineligible", reason: t("payIneligible") });
          return;
        }
        // plan-048 T2.5 — prime the policy identity as soon as we know the
        // order's branch; echoed on the intent body for drift logging.
        primePaymentPolicyContext(o.branch_slug);
        // plan-054 — the same slug drives the PayPay availability probe. The
        // order payload is preferred over the localStorage pointer: `shop` on
        // the pointer is written with a `|| "default"` fallback by
        // /order-success, so it can be a placeholder rather than a real slug.
        setBranchSlug(o.branch_slug || pointer?.shop || null);
        setState({ kind: "ok", order: o });
      })
      .catch((err) => {
        if (cancelled) return;
        if (err instanceof ApiError) {
          setState({ kind: "error", message: `${err.status} ${err.message}` });
        } else {
          setState({ kind: "error", message: t("fetchError") });
        }
      });
    return () => {
      cancelled = true;
    };
  }, [authLoading, isLoggedIn, id, router, t]);

  const handlePay = async () => {
    if (state.kind !== "ok") return;
    setPayError(null);
    setSubmitting(true);
    try {
      // Local card validation before hitting Stripe — fails fast on missing
      // fields without burning an idempotency key.
      // Optional chaining on validate() is `undefined` when Elements is gone —
      // not an error — so we would mint a PaymentIntent and leave it Incomplete
      // (ORD-2026-0237). Bail before the POST.
      if (!stripeCardRef.current) {
        setPayError(t("cardPaymentUnavailable"));
        setSubmitting(false);
        return;
      }
      const validate = await stripeCardRef.current.validate();
      if (validate.error) {
        setPayError(validate.error);
        setSubmitting(false);
        return;
      }

      const intentRes = await apiFetch<{
        data: { client_secret: string; payment_intent_id: string };
      }>(`/api/v1/customer/orders/${state.order.id}/full-payment-intent`, {
        method: "POST",
        body: JSON.stringify(paymentPolicyEcho(state.order.branch_slug)),
      });

      const returnUrl = `${window.location.origin}/order-success?${new URLSearchParams(
        {
          id: state.order.id,
          code: state.order.code,
          type: "takeaway",
          stripe_return: "1",
        },
      ).toString()}`;

      const confirmRes = await stripeCardRef.current?.confirm(
        intentRes.data.client_secret,
        returnUrl,
      );
      if (!confirmRes?.succeeded && !confirmRes?.pending) {
        setPayError(confirmRes?.error ?? t("paymentFailed"));
        setSubmitting(false);
        return;
      }

      // Sync payment row server-side immediately (webhook backs up). For an
      // async method (#1125) this records the awaiting-payment placeholder.
      try {
        await apiFetch(`/api/v1/customer/orders/${state.order.id}/confirm-payment`, {
          method: "POST",
          body: JSON.stringify({ payment_intent_id: intentRes.data.payment_intent_id }),
        });
      } catch (syncErr) {
        console.warn(
          "[Stripe] confirm-payment sync failed; webhook will reconcile",
          syncErr,
        );
      }

      if (confirmRes?.pending) {
        // #1125 option B — stay on an awaiting screen: the voucher/instructions
        // were just shown by Stripe; `order.paid` realtime (below) redirects to
        // success whenever the money lands.
        setAwaitingAsync(true);
        setPayError(null);
        setSubmitting(false);
        return;
      }

      router.replace(
        `/order-success?${new URLSearchParams({
          id: state.order.id,
          code: state.order.code,
          type: "takeaway",
          stripe_return: "1",
        }).toString()}`,
      );
    } catch (err) {
      setPayError(err instanceof Error ? err.message : t("paymentFailed"));
      setSubmitting(false);
    }
  };

  // plan-050 — đơn được trả đủ bằng ĐƯỜNG NÀO cũng đưa khách sang màn success:
  // thẻ online ở trên, hoặc thu ngân/kiosk quét QR "thanh toán tại quầy".
  //
  // `paused` CHỈ theo `submitting` — tuyệt đối không gộp `awaitingAsync`.
  // `awaitingAsync` bật lên rồi không bao giờ tắt (không có `setAwaitingAsync(false)`
  // ở đâu cả), nên gộp vào là đóng băng vĩnh viễn đúng cái màn cần poll nhất:
  // Konbini / chuyển khoản (#1125) tiền về sau hàng giờ và màn này dựa hoàn
  // toàn vào việc đọc lại trạng thái để thoát.
  //
  // `enabled` phải tự tắt khi đã trả xong: `state` ở trang này chỉ được set
  // trong effect mount, không bao giờ đổi lại, nên nếu chỉ dựa vào `state.kind`
  // thì trang poll mãi. Cờ `settled` dưới đây là điều kiện dừng.
  //
  // `stripe_return=1` trên URL đích: nó là cờ "đơn này đã trả xong" mà
  // /order-success đọc, chứ không phải "vừa về từ Stripe" (handlePay ở trên
  // dùng y hệt). Thiếu nó thì màn success hiện lại QR "thanh toán tại quầy"
  // cho người vừa trả tiền xong — lời mời trả lần hai.
  const [settled, setSettled] = useState(false);

  // plan-054 — the PayPay panel settles through THIS, not through a second
  // mechanism of its own. Cloud runs `syncLedgerCacheAndSettleIfPaid` for a
  // PayPay payment exactly as it does for card and counter, so the watcher
  // below already sees the order flip; the panel's own status poll just calls
  // the same handler the moment IT is the one that noticed.
  const goToSuccess = () => {
    if (state.kind !== "ok") return;
    setSettled(true);
    // `shop` phải đi kèm: /order-success ghi lại guest-order pointer bằng
    // `shop || "default"`, nên thiếu nó là ghi đè slug chi nhánh thật thành
    // "default" → link "Xem thực đơn" ở /orders trỏ sai và nút "Đặt lại" bỏ
    // chạy vì thiếu shop slug.
    const shop = loadGuestOrders().find((o) => o.id === state.order.id)?.shop;
    router.replace(
      `/order-success?${new URLSearchParams({
        id: state.order.id,
        code: state.order.code,
        type: "takeaway",
        stripe_return: "1",
        ...(shop ? { shop } : {}),
      }).toString()}`,
    );
  };

  useOrderSettlement(id, {
    enabled: state.kind === "ok" && !settled,
    paused: submitting,
    onPaid: goToSuccess,
  });

  // godx-tempo#1737 — switching the method radio away from PayPay unmounts the
  // QR panel, and the comment on `switchMethodWarning` below has always said
  // what that costs: "the switch really does abandon the QR on screen". The
  // code stays scannable at PayPay for the rest of its ~5 minutes with nothing
  // watching it. Keep asking the status endpoint — which is what BOOKS the
  // money — so a scan on the abandoned code settles here rather than waiting on
  // the fifteen-minute sweeper.
  const [paypayOrphanedAtMs, setPaypayOrphanedAtMs] = useState<number | null>(null);
  usePayPayOrphanWatch({
    orderId: state.kind === "ok" ? state.order.id : null,
    orphanedAtMs: settled ? null : paypayOrphanedAtMs,
    onResolved: () => setPaypayOrphanedAtMs(null),
    onPaid: goToSuccess,
  });

  // ─── Render ──────────────────────────────────────────────────────────

  if (authLoading || state.kind === "loading") {
    return <FullPageLoader />;
  }

  if (state.kind === "forbidden" || state.kind === "error" || state.kind === "ineligible") {
    return (
      <Frame
        backLabel={tCommon("back")}
        onBack={() => router.back()}
        title={t("payTitle")}
      >
        <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col items-center justify-center gap-4 px-4 py-12">
          <div className="flex size-16 items-center justify-center rounded-full bg-amber-50">
            <AlertCircle className="size-9 text-amber-500" />
          </div>
          <p className="max-w-sm text-center text-sm leading-relaxed text-neutral-600">
            {state.kind === "ineligible"
              ? state.reason
              : state.kind === "error"
                ? state.message
                : t("forbiddenMessage")}
          </p>
          <Button variant="outline" onClick={() => router.replace(`/orders/${id}`)}>
            <ArrowLeft className="size-4" />
            {t("backToList")}
          </Button>
        </main>
      </Frame>
    );
  }

  const { order } = state;
  // Format money in the ORDER's own currency (not the ambient selected branch).
  const fmt = (v: number) => formatCurrency(v, order.currency);

  // QR the counter kiosk scans to settle this order (kiosk resolveQrCode
  // fast-path reads `orderCode` and pulls the full order to pay).
  const counterQr = JSON.stringify({ orderCode: order.code });
  // #2806 — built either way: hiding the QR is a display decision, and the
  // kiosk's `resolveQrCode` fast-path is unchanged behind it.
  const counterQrShown = shouldShowCounterPayQr(counterPaySettings);

  return (
    <Frame
      backLabel={tCommon("back")}
      onBack={() => router.replace(`/orders/${id}`)}
      title={t("payTitle")}
    >
      <main className="mx-auto w-full max-w-2xl flex-1 space-y-4 px-4 py-4 md:px-6 md:py-6">
        {/* Amount-due hero — clean white card matching the rest of the page,
            with a subtle emerald chip on the order code as the only accent.
            Rides with the chooser: on the handover arrival the QR panel below
            states the same amount and the same order code, so keeping this
            would only be the number twice and the QR a scroll away. */}
        {chooserVisible && (
          <div className="overflow-hidden rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm">
            <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-neutral-500">
              <Receipt className="size-4 text-neutral-400" />
              <span>{t("orderCode")}</span>
              <span className="ml-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700">
                #{shortOrderCode(order.code)}
              </span>
            </div>
            <p className="mt-3 text-4xl font-extrabold tracking-tight text-neutral-900 tabular-nums">
              {fmt(order.total)}
            </p>
          </div>
        )}

        {/* Method chooser — radio-card style matching the checkout page's
            "Hình thức thanh toán" section (green border + tint when selected). */}
        {chooserVisible && (
          <section className="rounded-xl border border-neutral-200 bg-white p-3">
            <div className="mb-3 flex items-center gap-2 text-base font-semibold text-neutral-900">
              <CreditCard className="size-4 text-neutral-600" />
              <span>{t("payMethodTitle")}</span>
            </div>
            <RadioGroup
              value={effectiveMethod}
              onValueChange={(v) => setMethod(v as PayMethod)}
            >
              <div className="space-y-2">
                {/* #1427 — Stripe and PayPay are both ONLINE payment; the flat
                    list used to sit them beside each other as if PayPay were a
                    third category. They are nested under one "pay online"
                    heading now.

                    plan-054 — PayPay renders only where the QR flow actually
                    works (`paypayEnabled`). Offering it on an unconfigured
                    branch would hand the customer a button that can only 4xx.
                    The checkout `qr_pay` radio stays unconditional — that one
                    already works by hand (§10.2). Which means the group can
                    hold a single child, and a one-item nested list is just
                    noise: below, that case collapses back to the flat row this
                    screen has always shown. */}
                {(() => {
                  // One row, two homes: nested under the "pay online" heading
                  // when PayPay shares that group, standing alone otherwise. The
                  // label follows — inside the group the heading already says
                  // "online", so the row names the processor; alone it keeps the
                  // wording this screen has always used.
                  const stripeRow = (
                    <div
                      className="flex items-center space-x-2 rounded-lg px-3"
                      style={{
                        backgroundColor:
                          effectiveMethod === "online" ? "#2D8A390D" : "transparent",
                        border:
                          effectiveMethod === "online"
                            ? "1px solid #2D8A39"
                            : "1px solid #E5E7EB",
                        minHeight: "50px",
                        opacity: stripeBelowMinimum ? 0.55 : 1,
                      }}
                    >
                      <RadioGroupItem
                        value="online"
                        id="pay-method-online"
                        disabled={stripeBelowMinimum}
                      />
                      <Label
                        htmlFor="pay-method-online"
                        className={`flex-1 py-2 font-medium ${
                          stripeBelowMinimum ? "cursor-not-allowed" : "cursor-pointer"
                        }`}
                      >
                        <span className="block">
                          {paypayEnabled ? t("payMethodStripe") : t("payMethodOnline")}
                        </span>
                        {/* The order in the #1427 report was ¥1 — under the card
                            minimum. The customer used to learn that only after
                            picking card, from a warning standing where the form
                            should have been. Say it on the choice instead. */}
                        {stripeBelowMinimum && stripeMinimumLabel && (
                          <span className="mt-0.5 block text-xs font-normal leading-snug text-amber-700">
                            {t("payMethodStripeBelowMinimum", { min: stripeMinimumLabel })}
                          </span>
                        )}
                      </Label>
                    </div>
                  );

                  if (!paypayEnabled) return stripeRow;

                  return (
                    <div className="rounded-lg border border-[#E5E7EB]">
                      <div className="flex items-center gap-2 px-3 py-2 text-sm font-semibold text-neutral-700">
                        <Globe className="size-3.5 text-neutral-500" />
                        <span>{t("payMethodOnline")}</span>
                      </div>
                      <div className="space-y-2 px-2 pb-2">
                        {stripeRow}
                        <div
                          className="flex items-center space-x-2 rounded-lg px-3"
                          style={{
                            backgroundColor:
                              effectiveMethod === "paypay" ? "#2D8A390D" : "transparent",
                            border:
                              effectiveMethod === "paypay"
                                ? "1px solid #2D8A39"
                                : "1px solid #E5E7EB",
                            height: "50px",
                          }}
                        >
                          <RadioGroupItem value="paypay" id="pay-method-paypay" />
                          <Label
                            htmlFor="pay-method-paypay"
                            className="flex flex-1 cursor-pointer items-center gap-2 font-medium"
                          >
                            <span className="flex-1">{t("payMethodPayPay")}</span>
                            <PayPayBrandIcon />
                          </Label>
                        </div>
                      </div>
                    </div>
                  );
                })()}
                {/* #2545 — lối thoát, không phải một lựa chọn. */}
                {counterPayOffered && (
                  <div
                    className="flex items-center space-x-2 rounded-lg px-3"
                    style={{
                      backgroundColor: effectiveMethod === "counter" ? "#2D8A390D" : "transparent",
                      border: effectiveMethod === "counter" ? "1px solid #2D8A39" : "1px solid #E5E7EB",
                      height: "50px",
                    }}
                  >
                    <RadioGroupItem value="counter" id="pay-method-counter" />
                    <Label htmlFor="pay-method-counter" className="flex-1 cursor-pointer font-medium">
                      {t("payMethodCounter")}
                    </Label>
                  </div>
                )}
              </div>
            </RadioGroup>

            {/* Switching away unmounts the QR panel, and coming back MINTS —
                which invalidates the outstanding code server-side. So the switch
                really does abandon the QR on screen, and it used to do it in
                silence, mid-scan. Rendered only where a QR panel is actually
                mounted (`paypayEnabled`), so it never appears next to the
                settle-by-hand `qr_pay` path. */}
            {effectiveMethod === "paypay" && paypayEnabled && (
              <p className="mt-2 px-1 text-xs leading-relaxed text-neutral-500">
                {tPayPay("switchMethodWarning")}
              </p>
            )}
          </section>
        )}

        {effectiveMethod === "paypay" ? (
          paypayLoading ? (
            <div className="flex items-center justify-center rounded-2xl border border-neutral-200 bg-white py-10 shadow-sm">
              <Loader2 className="size-6 animate-spin text-primary" />
            </div>
          ) : paypayEnabled ? (
            <PayPayQrPanel
              orderId={order.id}
              orderCode={order.code}
              amount={order.total}
              currency={order.currency}
              onPaid={goToSuccess}
              onAbandoned={() => setPaypayOrphanedAtMs(Date.now())}
            />
          ) : (
            /* Landed with ?method=paypay on a branch that has no PayPay QR
               (turned off between checkout and here, or the hint was typed).
               Say so and point at the methods that do work — never a blank.
               On the handover arrival this CTA is also the ONLY control on
               screen, so it must re-open the chooser as well as switch: the
               hint is in the URL and would otherwise keep the radios hidden
               through the very failure that needs them. */
            <div className="flex flex-col items-center gap-4 rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm">
              <div className="flex size-14 items-center justify-center rounded-full bg-amber-50">
                <AlertCircle className="size-8 text-amber-500" />
              </div>
              <p className="max-w-xs text-center text-sm leading-relaxed text-neutral-600">
                {tPayPay("notAvailable")}
              </p>
              <Button
                variant="outline"
                onClick={() => {
                  setMethodOptOut(true);
                  setMethod("online");
                }}
              >
                {tPayPay("backToOrder")}
              </Button>
            </div>
          )
        ) : effectiveMethod === "online" ? (
          <>
        {/* Stripe Elements card frame */}
        <div className="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm">
          <div className="mb-4 flex items-center gap-2">
            <CreditCard className="size-4 text-neutral-500" />
            <h2 className="text-sm font-semibold text-neutral-700">
              {t("cardSectionTitle")}
            </h2>
          </div>
          {/* #1703 — `stripeConfig` resolving is NOT the same as a usable key:
              the endpoint answers 200 with an empty `publishable_key` whenever
              the backend has no STRIPE_KEY. Handing "" to loadStripe() throws
              inside Elements and takes the whole pay screen down, so an empty
              key must fall through to the unavailable notice, not the spinner
              (which would spin forever) and not the card frame. */}
          {stripeConfig?.publishable_key ? (
            <StripeCardSection
              ref={stripeCardRef}
              amount={order.total}
              // #815 — the CHARGE currency (not the global stripeConfig.currency,
              // nor the display order.currency) so Elements matches the PaymentIntent.
              currency={order.charge_currency ?? "JPY"}
              publishableKey={stripeConfig.publishable_key}
              // #1125 option B — show Konbini/銀行振込 tabs when the branch
              // allows, AND build Elements for the matching method set.
              showMethodTabs={asyncMethodsEnabled}
            />
          ) : stripeConfigLoading ? (
            <div className="flex items-center justify-center py-8">
              <Loader2 className="size-6 animate-spin text-primary" />
            </div>
          ) : (
            <p className="py-4 text-xs text-red-500">
              {t("cardPaymentUnavailable")}
            </p>
          )}

          {/* Security hint — reuses Stripe locked-padlock affordance */}
          <p className="mt-4 flex items-center gap-1.5 text-xs text-neutral-500">
            <Lock className="size-3" />
            {t("cardEncryptedHint")}
          </p>
        </div>

        {awaitingAsync && (
          <div className="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <Loader2 className="mt-0.5 size-4 shrink-0 animate-spin" />
            <div>
              <p className="font-medium">{t("asyncAwaitingTitle")}</p>
              <p className="mt-1 text-xs">{t("asyncAwaitingBody")}</p>
            </div>
          </div>
        )}

        {payError && (
          <div className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <AlertCircle className="mt-0.5 size-4 shrink-0" />
            <p className="font-medium">{payError}</p>
          </div>
        )}

        {/* Primary CTA — solid emerald, same flavour as /orders/[id]
            switch-to-pay button so the two screens feel like one flow. */}
        <button
          onClick={handlePay}
          // #1703 — same distinction as the card frame above: a resolved config
          // carrying an empty key still has no card form to confirm against.
          disabled={!stripeConfig?.publishable_key || submitting}
          style={{ backgroundColor: "#2D8336" }}
          className="flex w-full items-center justify-center gap-3 rounded-2xl px-6 py-4 text-base font-bold text-white transition-colors active:translate-y-px disabled:cursor-not-allowed disabled:opacity-50"
        >
          {submitting ? (
            <>
              <Loader2 className="size-5 animate-spin" />
              <span className="tracking-wide">{t("paymentProcessing")}</span>
            </>
          ) : (
            <>
              <CreditCard className="size-5" />
              <span className="tracking-wide">
                {t("payAmountCta", { amount: fmt(order.total) })}
              </span>
              <ArrowRight className="size-5" />
            </>
          )}
        </button>

        {/* Footer trust strip */}
        <p className="text-center text-xs text-neutral-400">
          {t("poweredByStripe")}
        </p>
          </>
        ) : (
          /* Pay-at-counter. #2806 — the QR is the shop's call
             (`counter_pay_show_qr`); with it off the staff read the `#xxxx`
             order code below instead, so that code stops being supporting
             detail and becomes the only handle the guest has. */
          <div className="flex flex-col items-center gap-4 rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm">
            <p className="max-w-xs text-center text-sm leading-relaxed text-neutral-600">
              {counterQrShown ? t("counterQrHint") : t("counterHint")}
            </p>
            {counterQrShown && (
              <div className="rounded-2xl border border-neutral-100 p-3">
                <QRCodeSVG value={counterQr} size={220} fgColor="#000000" bgColor="#ffffff" />
              </div>
            )}
            <div className="text-center">
              <p className="text-2xl font-extrabold tabular-nums text-emerald-600">
                {fmt(order.total)}
              </p>
              <p className="mt-0.5 text-xs text-neutral-500">#{shortOrderCode(order.code)}</p>
            </div>
          </div>
        )}
      </main>
    </Frame>
  );
}

function Frame({
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
      <Header showLogo hideSwitcher hideOrderCta hideShadow />
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

function FullPageLoader() {
  return (
    <div className="flex min-h-screen flex-col bg-[#FAFAFA]">
      <Header showLogo hideSwitcher />
      <div className="flex flex-1 items-center justify-center">
        <Loader2 className="size-8 animate-spin text-primary" />
      </div>
    </div>
  );
}
