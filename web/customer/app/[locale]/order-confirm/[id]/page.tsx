"use client";

/**
 * Takeaway counter-pay confirmation step. Draft sống ở localStorage;
 * BE chỉ tạo order khi customer commit (POST /orders trong handleConfirm).
 * Cancel/timeout → removeCheckoutDraft, không có DB write.
 */

import { useEffect, useRef, useState } from "react";
import { useParams } from "next/navigation";

import { useRouter } from "@/i18n/routing";
import { useTranslations } from "next-intl";
import {
  AlertCircle,
  ArrowLeft,
  Check,
  Clock,
  Loader2,
  XCircle,
} from "lucide-react";

import Header from "@/components/Header";
import { CartItemOptionsList } from "@/components/cart-item-options";
import { Button } from "@/components/ui/button";
import { apiFetch, ApiError } from "@/lib/api";
import { useCurrency } from "@/lib/currency";
import { saveGuestOrder } from "@/lib/guest-orders";
import { driftUpdatesFromError } from "@/lib/price-drift";
import { TaxBreakdownLines } from "@/components/tax-breakdown-lines";
import { useCart } from "@/context/cart-context";
import {
  loadCheckoutDraft,
  removeCheckoutDraft,
  type CheckoutDraft,
} from "@/lib/checkout-draft";

type State =
  | { kind: "loading" }
  | { kind: "ok"; draft: CheckoutDraft }
  | { kind: "expired" }
  | { kind: "error"; message: string };

/**
 * A commit failed because of the pickup time (e.g. the chosen slot slipped
 * into the past, or the branch is closed at that time). Laravel returns 422
 * with an `errors.scheduled_pickup_time` entry; some paths return a flat
 * message. These are recoverable, so we route the customer back to the picker
 * rather than dead-ending on the error screen.
 */
function isPickupTimeError(err: unknown): boolean {
  if (!(err instanceof ApiError)) return false;
  if (err.status === 422) {
    const errors = err.body?.errors as Record<string, unknown> | undefined;
    if (errors && ("scheduled_pickup_time" in errors || "pickup_type" in errors)) {
      return true;
    }
  }
  const msg = (err.body?.message as string | undefined) ?? err.message;
  return typeof msg === "string" && /pickup/i.test(msg);
}

export default function OrderConfirmPage() {
  const t = useTranslations("orderConfirm");
  const tCommon = useTranslations("common");
  // Money-breakdown labels are shared with the checkout summary (#39) so both
  // screens phrase 小計 / 割引 / 手数料 / 消費税 the same way.
  const tCheckout = useTranslations("checkout");
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { format: fmt } = useCurrency();
  const { clearCart, items, applyServerPrices } = useCart();

  const [state, setState] = useState<State>({ kind: "loading" });
  const [submitting, setSubmitting] = useState<"none" | "confirm" | "cancel">(
    "none",
  );
  const [secondsLeft, setSecondsLeft] = useState(0);
  const [showCancelModal, setShowCancelModal] = useState(false);
  const cancelledByCountdownRef = useRef(false);

  const backToMenuUrl =
    state.kind === "ok" && state.draft.shop_slug
      ? `/takeaway/${state.draft.shop_slug}`
      : "/menus";

  useEffect(() => {
    const draft = loadCheckoutDraft(id);
    if (!draft) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setState({ kind: "expired" });
      return;
    }
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setState({ kind: "ok", draft });
  }, [id]);

  useEffect(() => {
    if (state.kind !== "ok") return;
    const dueMs = new Date(state.draft.expires_at).getTime();
    const tick = () => {
      const left = Math.max(0, Math.floor((dueMs - Date.now()) / 1000));
      setSecondsLeft(left);
      if (left === 0 && !cancelledByCountdownRef.current) {
        cancelledByCountdownRef.current = true;
        removeCheckoutDraft(state.draft.id);
        router.replace(backToMenuUrl);
      }
    };
    tick();
    const handle = window.setInterval(tick, 1000);
    return () => window.clearInterval(handle);
  }, [state, router, backToMenuUrl]);

  const handleConfirm = async () => {
    if (state.kind !== "ok" || submitting !== "none") return;
    setSubmitting("confirm");
    const draft = state.draft;
    try {
      const res = await apiFetch<{ data: { id: string; code: string } }>(
        `/api/v1/customer/branches/${draft.shop_slug}/orders`,
        {
          method: "POST",
          headers: { "Idempotency-Key": draft.id },
          body: JSON.stringify({
            items: draft.items.map((it) => ({
              product_sku_id: it.product_sku_id,
              quantity: it.qty,
              // #1715 — giá màn này đang hiện cho khách. Draft là ảnh chụp phía
              // client từ lúc rời checkout, nên nó CHÍNH LÀ con số cần được bảo
              // vệ: server từ chối (409) nếu giá vừa giải ra cao hơn.
              expected_unit_price: it.unit_price,
              // #1768 — forward per-item note. Draft trước đây không mang note
              // nên đường counter-pay (default trên màn checkout) vứt hẳn ghi
              // chú của khách; giờ draft giữ, ta forward vào BE.
              ...(it.note ? { note: it.note } : {}),
              ...(it.toppings && it.toppings.length > 0
                ? { toppings: it.toppings }
                : {}),
            })),
            customer_name: draft.customer_name,
            customer_phone: draft.customer_phone,
            customer_email: draft.customer_email,
            customer_takeaway_name: draft.customer_name,
            customer_takeaway_phone: draft.customer_phone,
            customer_takeaway_email: draft.customer_email,
            note: draft.note,
            payment_method: draft.payment_method,
            coupon_code: draft.coupon_code,
            downgrade_exclusive_promotions: draft.downgrade_promos,
            ...(draft.pickup_type
              ? {
                  pickup_type: draft.pickup_type,
                  scheduled_pickup_time: draft.scheduled_pickup_time,
                }
              : {}),
          }),
          silent401: true,
        },
      );
      saveGuestOrder({
        id: res.data.id,
        code: res.data.code,
        shop: draft.shop_slug,
      });
      removeCheckoutDraft(draft.id);
      clearCart();
      router.replace(
        `/order-success?${new URLSearchParams({
          id: res.data.id,
          code: res.data.code,
          type: "takeaway",
          shop: draft.shop_slug,
        }).toString()}`,
      );
    } catch (err) {
      // Pickup-time rejections are recoverable — but only on the checkout page,
      // the one screen that has the pickup-time picker. Bounce the customer
      // back there (cart + picked time are still in context) with a re-pick
      // flag so they can choose a new slot inline, instead of dead-ending on
      // the error screen whose only exit is "back to menus".
      if (isPickupTimeError(err)) {
        removeCheckoutDraft(draft.id);
        router.replace("/checkout?repick=pickup");
        return;
      }
      // #1715 — giá vừa đổi (khung giờ ưu đãi đóng trong lúc khách đứng ở màn
      // xác nhận). Sửa giá trong giỏ theo con số server vừa trả, rồi đưa khách
      // về /checkout — CÙNG cách thoát mà lỗi giờ nhận hàng đang dùng ở trên.
      //
      // Không tự dựng lại khối tiền tại chỗ: draft chốt cả coupon, thuế và phí
      // phục vụ, mà phép tính đó sống ở checkout (và coupon phần trăm còn phải
      // preview lại qua API). Tính lại ở đây là chép bộ máy tiền lần thứ tư và
      // chắc chắn lệch với ba bản kia.
      //
      // Ghép theo VỊ TRÍ: draft dựng từ giỏ theo đúng thứ tự, và product-modal
      // chặn thêm món khi đang có draft mở, nên hai mảng còn khớp chỉ số.
      const repriced = driftUpdatesFromError(err, items.map((i) => i.id));
      if (repriced) {
        applyServerPrices(repriced);
        removeCheckoutDraft(draft.id);
        router.replace("/checkout?repriced=1");
        return;
      }
      const msg =
        err instanceof ApiError
          ? ((err.body?.message as string) ?? t("commitFailed"))
          : t("commitFailed");
      setState({ kind: "error", message: msg });
      setSubmitting("none");
    }
  };

  const openCancelModal = () => {
    if (state.kind !== "ok" || submitting !== "none") return;
    setShowCancelModal(true);
  };

  const handleCancel = () => {
    if (state.kind !== "ok" || submitting !== "none") return;
    setShowCancelModal(false);
    setSubmitting("cancel");
    removeCheckoutDraft(state.draft.id);
    router.replace(backToMenuUrl);
  };

  if (state.kind === "loading") {
    return <FullPageLoader />;
  }

  if (state.kind === "expired" || state.kind === "error") {
    const message =
      state.kind === "expired" ? t("expiredMessage") : state.message;
    return (
      <Frame
        backLabel={tCommon("back")}
        onBack={() => router.replace(backToMenuUrl)}
        title={t("title")}
      >
        <main className="mx-auto flex w-full max-w-2xl flex-1 flex-col items-center justify-center gap-4 px-4 py-12">
          <div className="flex size-16 items-center justify-center rounded-full bg-amber-50">
            <AlertCircle className="size-9 text-amber-500" />
          </div>
          <p className="max-w-sm text-center text-sm leading-relaxed text-neutral-600">
            {message}
          </p>
          <Button variant="outline" onClick={() => router.replace(backToMenuUrl)}>
            <ArrowLeft className="size-4" />
            {t("backToMenus")}
          </Button>
        </main>
      </Frame>
    );
  }

  const { draft } = state;
  const minutes = Math.floor(secondsLeft / 60);
  const seconds = secondsLeft % 60;
  const totalSeconds = Math.max(
    1,
    Math.round(
      (new Date(draft.expires_at).getTime() -
        new Date(draft.created_at).getTime()) /
        1000,
    ),
  );
  const fraction = Math.max(0, Math.min(1, secondsLeft / totalSeconds));
  // #39 — breakdown fields are optional on the draft; legacy drafts fall back
  // to Σ line subtotals (which is exactly what `total` held back then).
  const itemCount = draft.items.reduce((s, it) => s + it.qty, 0);
  const itemsSubtotal =
    draft.subtotal ?? draft.items.reduce((s, it) => s + it.subtotal, 0);
  const discountAmount = draft.discount_amount ?? 0;
  const serviceCharge = draft.service_charge ?? 0;
  const tone =
    secondsLeft <= 30
      ? { bg: "bg-red-500", text: "text-red-600", track: "bg-red-100", border: "border-red-200" }
      : secondsLeft <= 60
        ? { bg: "bg-amber-500", text: "text-amber-600", track: "bg-amber-100", border: "border-amber-200" }
        : { bg: "bg-emerald-500", text: "text-emerald-600", track: "bg-emerald-100", border: "border-emerald-200" };
  const mm = String(minutes).padStart(2, "0");
  const ss = String(seconds).padStart(2, "0");

  return (
    <Frame
      backLabel={tCommon("back")}
      onBack={() => router.replace(backToMenuUrl)}
      title={t("title")}
    >
      <main className="mx-auto w-full max-w-2xl flex-1 space-y-3 px-3 pb-24 pt-3 md:space-y-4 md:px-6 md:pb-6 md:pt-6">
        {/* Hero countdown — flip-digit boxes + linear progress bar */}
        <div
          className={`sticky top-[6.25rem] z-20 -mx-3 overflow-hidden border ${tone.border} bg-white px-4 py-5 shadow-md md:top-14 md:mx-0 md:rounded-2xl md:px-5 md:py-6`}
        >
          <div
            className={`flex items-center justify-center gap-2 text-[11px] font-semibold uppercase tracking-[0.18em] ${tone.text} md:text-xs`}
          >
            <Clock className="size-3.5" />
            <span>{t("countdownLabel")}</span>
          </div>

          {/* Digit boxes */}
          <div className="mt-4 flex items-center justify-center gap-1.5 md:gap-2">
            <DigitBox value={mm[0]} tone={tone.bg} />
            <DigitBox value={mm[1]} tone={tone.bg} />
            <span className={`pb-1 text-3xl font-extrabold ${tone.text} md:text-4xl`}>:</span>
            <DigitBox value={ss[0]} tone={tone.bg} />
            <DigitBox value={ss[1]} tone={tone.bg} />
          </div>

          {/* Linear progress bar */}
          <div className="mt-4 flex items-center gap-3">
            <div
              className={`relative h-1.5 flex-1 overflow-hidden rounded-full ${tone.track}`}
            >
              <div
                className={`h-full ${tone.bg} transition-[width] duration-1000 ease-linear`}
                style={{ width: `${Math.round(fraction * 100)}%` }}
              />
            </div>
            <span
              className={`shrink-0 text-xs font-bold tabular-nums ${tone.text}`}
            >
              {Math.round(fraction * 100)}%
            </span>
          </div>

          <p
            className="mt-3 text-center text-xs font-semibold leading-relaxed md:text-sm"
            style={{ color: "#F95C39" }}
          >
            {t("subtitle")}
          </p>
        </div>

        {/* Không hiển thị mã đơn hàng ở bước này (#39): draft chưa được ghi
            vào DB nên `draft.code` chỉ là mã random phía client — khách thấy
            sẽ tưởng đã đặt xong. Mã thật chỉ có sau khi commit (order-success). */}
        <div className="space-y-3 rounded-2xl border border-neutral-200 bg-white p-3 shadow-sm">
          {draft.items.map((item) => (
            <div key={item.id} className="rounded-2xl border border-neutral-200 p-3">
              <div className="flex gap-3">
                {item.image_url ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img
                    src={item.image_url}
                    alt={item.name ?? ""}
                    className="size-14 shrink-0 rounded-xl object-cover md:size-16"
                  />
                ) : (
                  <div className="size-14 shrink-0 rounded-xl bg-neutral-100 md:size-16" />
                )}
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-semibold leading-snug text-neutral-900 md:text-base">
                    {item.name}
                  </p>
                  {/* Selected options + toppings, with names (#435). Falls back
                      to the plain variant string for drafts saved before the
                      option_lines field shipped. */}
                  {item.option_lines && item.option_lines.length > 0 ? (
                    <CartItemOptionsList lines={item.option_lines} />
                  ) : item.variant ? (
                    <p className="mt-0.5 text-xs text-neutral-500">{item.variant}</p>
                  ) : null}
                  {/* #1768 — per-item note ("Không hành", "Ít cay"). Trước đây
                      draft không mang note nên màn cuối trước lúc bấm "Xác
                      nhận đặt" không hiện lại yêu cầu của khách — với món
                      dị ứng thì mất hẳn cửa để phát hiện đã rơi. */}
                  {item.note ? (
                    <p className="mt-1 text-xs italic text-neutral-600">
                      “{item.note}”
                    </p>
                  ) : null}
                  <p className="mt-1 text-sm font-semibold tabular-nums text-emerald-700">
                    {fmt(item.subtotal)}
                  </p>
                </div>
                <p className="self-start whitespace-nowrap text-sm font-semibold text-neutral-700">
                  x{item.qty}
                </p>
              </div>
            </div>
          ))}
          {/* Money breakdown (#39) — repeats the checkout summary so the total
              here is the amount actually payable, tax included. Legacy drafts
              (saved before the breakdown fields shipped) only carry `total`,
              so every line below degrades to just the total row. */}
          <div className="space-y-1.5 border-t border-neutral-200 pt-3">
            <div className="flex items-center justify-between gap-3 text-xs md:text-sm">
              <span className="text-neutral-500">
                {tCheckout("subtotal", { count: itemCount })}
              </span>
              <span className="font-medium tabular-nums text-neutral-700">
                {fmt(itemsSubtotal)}
              </span>
            </div>

            {discountAmount > 0 && (
              <div className="flex items-center justify-between gap-3 text-xs md:text-sm">
                <span className="text-neutral-500">
                  {draft.coupon_code
                    ? tCheckout("discountCoupon", { code: draft.coupon_code })
                    : tCheckout("discount")}
                </span>
                <span className="font-medium tabular-nums text-emerald-600">
                  −{fmt(discountAmount)}
                </span>
              </div>
            )}

            {serviceCharge > 0 && (
              <div className="flex items-center justify-between gap-3 text-xs md:text-sm">
                <span className="text-neutral-500">
                  {tCheckout("serviceChargeWithRate", {
                    rate: draft.service_charge_rate ?? 0,
                  })}
                </span>
                <span className="font-medium tabular-nums text-neutral-700">
                  {fmt(serviceCharge)}
                </span>
              </div>
            )}

            {/* plan-043 — per-rate lines (8%対象 / 10%対象), same component the
                checkout summary uses so both screens read identically. */}
            <TaxBreakdownLines
              breakdown={draft.tax_breakdown}
              isTaxIncluded={draft.prices_include_tax}
              format={fmt}
              namespace="checkout"
              className="space-y-1"
            />
          </div>

          <div className="flex items-baseline justify-between border-t border-neutral-200 pt-3">
            <span className="text-sm font-semibold text-neutral-700">
              {t("total")}
              {draft.prices_include_tax ? (
                <span className="ml-1 text-[11px] font-normal text-neutral-500">
                  ({tCheckout("taxIncludedBadge")})
                </span>
              ) : null}
            </span>
            <span className="text-lg font-extrabold tabular-nums tracking-tight text-neutral-900 md:text-xl">
              {fmt(draft.total)}
            </span>
          </div>
        </div>

        <div className="fixed inset-x-0 bottom-0 z-40 space-y-2 border-t border-neutral-200 bg-white/95 px-3 py-3 backdrop-blur md:static md:border-0 md:bg-transparent md:px-0 md:pb-0 md:pt-2">
          <button
            onClick={handleConfirm}
            disabled={submitting !== "none"}
            className="group relative flex w-full items-center justify-center gap-3 overflow-hidden rounded-2xl bg-[#2D8336] px-6 py-4 text-base font-bold text-white shadow-lg shadow-[#2D8336]/30 transition-colors hover:bg-[#26702E] active:translate-y-px disabled:cursor-not-allowed disabled:opacity-50"
          >
            {submitting === "confirm" ? (
              <Loader2 className="size-5 animate-spin" />
            ) : (
              <Check className="size-5" />
            )}
            <span className="tracking-wide">{t("confirmButton")}</span>
          </button>
          <button
            onClick={openCancelModal}
            disabled={submitting !== "none"}
            className="flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-red-300 bg-white px-6 py-3 text-sm font-semibold text-red-600 transition-all hover:bg-red-50 active:translate-y-px disabled:cursor-not-allowed disabled:opacity-50"
          >
            {submitting === "cancel" ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <XCircle className="size-4" />
            )}
            <span>{t("cancelButton")}</span>
          </button>
          <p className="text-center text-[13px] leading-relaxed text-neutral-500 md:text-xs md:text-neutral-400">
            {t("footerHint")}
          </p>
        </div>
      </main>

      {showCancelModal && (
        <CancelConfirmModal
          title={t("cancelConfirmTitle")}
          message={t("cancelConfirmModal")}
          confirmLabel={t("cancelConfirmYes")}
          dismissLabel={t("cancelConfirmNo")}
          onConfirm={handleCancel}
          onDismiss={() => setShowCancelModal(false)}
        />
      )}
    </Frame>
  );
}

function CancelConfirmModal({
  title,
  message,
  confirmLabel,
  dismissLabel,
  onConfirm,
  onDismiss,
}: {
  title: string;
  message: string;
  confirmLabel: string;
  dismissLabel: string;
  onConfirm: () => void;
  onDismiss: () => void;
}) {
  return (
    <div
      className="fixed inset-0 z-[60] flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm animate-in fade-in duration-150"
      onClick={onDismiss}
    >
      <div
        className="w-full max-w-sm overflow-hidden rounded-2xl bg-white shadow-2xl animate-in zoom-in-95 fade-in duration-200"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex flex-col items-center gap-3 px-5 pt-6 pb-4 text-center">
          <div className="flex size-14 items-center justify-center rounded-full bg-red-50">
            <XCircle className="size-7 text-red-500" />
          </div>
          <h2 className="text-lg font-bold text-neutral-900">{title}</h2>
          <p className="text-sm leading-relaxed text-neutral-600">{message}</p>
        </div>
        <div className="grid grid-cols-2 gap-2 border-t border-neutral-200 p-3">
          <button
            type="button"
            onClick={onDismiss}
            className="flex h-11 items-center justify-center rounded-xl border border-neutral-200 bg-white text-sm font-semibold text-neutral-700 transition-colors hover:bg-neutral-50 active:translate-y-px"
          >
            {dismissLabel}
          </button>
          <button
            type="button"
            onClick={onConfirm}
            className="flex h-11 items-center justify-center rounded-xl bg-red-500 text-sm font-semibold text-white transition-colors hover:bg-red-600 active:translate-y-px"
          >
            {confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}

function DigitBox({ value, tone }: { value: string; tone: string }) {
  return (
    <div
      className={`relative flex h-12 w-9 items-center justify-center overflow-hidden rounded-lg ${tone} text-white shadow-sm md:h-14 md:w-11`}
    >
      <span
        key={value}
        className="block text-3xl font-extrabold tabular-nums leading-none md:text-4xl"
        style={{ animation: "flipIn 320ms cubic-bezier(0.4, 0, 0.2, 1)" }}
      >
        {value}
      </span>
    </div>
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
