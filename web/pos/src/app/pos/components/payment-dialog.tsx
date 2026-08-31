/**
 * PaymentDialog — resolver-backed checkout + settle-outstanding-debt flow.
 *
 * Payment methods come from GET `/api/v1/pos/effective-payment-options`.
 * Only options where `effective && client.supports_pos_checkout` are shown
 * (plan-047 T6.1 / F6). When none are available, checkout is blocked with a
 * manager-facing message (F8).
 *
 * When the order has a customer_id, the dialog queries
 * `/customers/{id}/outstanding` for orders in `paying` status with
 * paid < total. Staff sees a reminder with a checkbox per old debt;
 * checking one adds its owed amount to the tendered-requirement and
 * makes the dialog fire an EXTRA payment against that old order —
 * never merged into the current order's payment (per plan-007 NOTES
 * "outstanding-debt settlement").
 *
 * Partial-payment (shortfall) rule: tendered < grand total is ONLY
 * accepted when the order has a customer_id. Walk-in orders
 * (customer_id=null) must be paid in full — without a customer there
 * is no way to resurface the balance on a later visit. The confirm
 * button stays disabled and a red banner explains why.
 */

import { useEffect, useMemo, useState } from "react";
import {
  Alert,
  AlertDescription,
  Button,
  Checkbox,
  Dialog,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Skeleton,
} from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { HelpButton } from "@/help/help-button";
import { AlertCircleIcon, UsersIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import { useCashChanger } from "../hooks/use-cash-changer";
import { CashChangerOverlay } from "./cash-changer-overlay";
import { cashCollectedAndRecorded } from "@/services/workstation-cash-changer-service";
import { useCardTerminal } from "@/hooks/api/use-card-terminal";
import { cardTerminalAvailable } from "@/services/card-terminal-service";
import { useNetworkRequired } from "@/hooks/use-network-required";
import { useShopOrderSettings } from "@/hooks/api/use-shop-order-settings";
import { OrderTotalsSummary } from "./order-totals-summary";
import { CouponField } from "./coupon-field";
import { couponMayBeChanged } from "../lib/coupon";
import {
  usePaymentTerminals,
  useTenderCategories,
  useTenderTypes,
} from "@/hooks/api/use-till";
import {
  buildTenderBrandGroups,
  tenderCategoryLabel,
  tenderDisplayName,
} from "@/app/shift/tender-terminals";
import { iconFor } from "../lib/payment-method-icon";
import { DebtChargeButton } from "./debt-charge-button";
import type { CustomerOrder, EffectivePaymentOption } from "../types";
import { formatCurrency } from "../lib/totals";
import {
  paymentOptionsState,
} from "../lib/effective-payment-options";

/** Stable empty reference so the memo below doesn't re-fire every render. */
const EMPTY_OPTIONS: EffectivePaymentOption[] = [];

export interface PaymentBody {
  payment_method_id: string;
  /**
   * plan-047 — the effective-option the cashier picked. Optional because not
   * every payment rides the gateway-option path: the debt CTA
   * (`DebtChargeButton`) resolves its method straight from
   * `/pos/payment-methods` and has no option envelope to quote. Backend
   * matches this shape — `gateway_option_id` is `nullable` and
   * `policy_revision` is `required_with:gateway_option_id`
   * (`OrderPaymentStoreRequest`), so the two travel together or not at all.
   */
  gateway_option_id?: string;
  gateway_connection_id?: string | null;
  policy_revision?: number;
  amount: number;
  tendered_amount?: number;
  /**
   * Stable per-step UUID. Backend dedups: if a payment with this key
   * already exists for the order, the existing one is returned instead
   * of inserting a duplicate. Lets retries after network glitches stay
   * idempotent so we don't get the "exceeds outstanding balance" error
   * because of orphan pending rows.
   */
  idempotency_key?: string;
  /**
   * #1156 — the brand/scheme the cashier tapped on the physical terminal
   * (`till_tender_types.tender_key`, e.g. "paypay" / "credit" / "id").
   * Purely attributional: it feeds per-brand 精算 reconciliation and never
   * changes any amount. Optional — the "あとで / 不明" path omits it, and a
   * payment is NEVER blocked on the choice.
   */
  tender_key?: string;
}

function newIdempotencyKey(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }
  return `key-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

export interface PaymentDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Shop slug — needed to invalidate the order after a P400 terminal charge. */
  shopSlug?: string;
  order: CustomerOrder | null;
  options: EffectivePaymentOption[];
  optionsLoading: boolean;
  /**
   * Error from the effective-options query, if the last fetch failed. Kept
   * separate from `options` on purpose: an empty list because the request never
   * landed is a CONNECTION fault the cashier can retry, while an empty list from
   * a successful response is a POLICY fault only a manager can fix. Collapsing
   * the two sent cashiers to the wrong person (#1511 follow-up).
   */
  optionsError?: unknown;
  /** Refetch the options — wired to the retry button on the error card. */
  onRetryOptions?: () => void;
  /** Immutable policy revision from the effective-options envelope. */
  policyRevision: number;
  /** Customer's unpaid orders from previous visits — excludes `order`. */
  outstanding: CustomerOrder[];
  outstandingLoading: boolean;
  /**
   * ISO 4217 currency of the shop (#555 L1) — selects the quick-tender
   * denomination tiles. From `ShopOrderSetting.currency_code`.
   */
  currencyCode?: string | null;
  /**
   * Fire one payment row. Parent orchestrates cache invalidation. The
   * dialog calls this once per settled debt PLUS once for the current
   * order, all using the same payment method.
   */
  onCreatePayment: (orderId: string, body: PaymentBody) => Promise<void>;
  /**
   * Twin of `onCreatePayment` that returns the created payment id, which the
   * "Ghi nợ" CTA needs in order to print the debt slip for the row it just
   * created. Omit to hide the CTA entirely.
   */
  onCreateDebtPayment?: (
    orderId: string,
    body: PaymentBody,
  ) => Promise<{ id: string }>;
  /** Fired after a debt payment is recorded. The slip is deliberately NOT
   *  printed here — the parent shows the result with an explicit print action. */
  onDebtCharged?: (paymentId: string, amount: number, orderId: string) => void;
  /**
   * Fired AFTER every payment in the session posted successfully and BEFORE
   * the dialog auto-closes. Parent uses this to capture the session metadata
   * (orderIds, amounts, tendered) so it can show a `PaymentReceiptDialog`
   * confirmation screen — closing the order tab is then deferred until the
   * staff dismisses that receipt.
   *
   * Optional: when omitted, PaymentDialog falls back to its old behaviour
   * (just close on success, parent's own onOpenChange handler decides what
   * to do next).
   */
  onPaymentSuccess?: (data: PaymentSessionResult) => void;
  /**
   * Optional — switch to the SplitBillDialog when staff toggles "Chia bill".
   * The handler should close this PaymentDialog and open the split flow.
   * Hidden when not provided (e.g. orders with no guest_count > 1).
   */
  onSwitchToSplit?: () => void;
}

/**
 * Snapshot of every transaction posted during one PaymentDialog session,
 * passed to `onPaymentSuccess` so the parent can render a receipt screen.
 */
export interface PaymentSessionResult {
  /** Posted transactions in chronological order (debts first, current order last). */
  entries: Array<{
    orderId: string;
    /** Immutable order code shown to staff/customer (e.g. ORD-2026-2426). */
    orderCode: string;
    amount: number;
    /** True when this row settled an old debt rather than the active order. */
    isDebt: boolean;
  }>;
  /** Sum of `entries[].amount`. */
  totalPaid: number;
  /** Cash handed over by the customer (>= totalPaid; difference is change). */
  tendered: number;
}

/**
 * Common cash denominations per currency — clicking adds to the tendered
 * total (#555 L1: the old single VND list rendered ₫50.000 tiles on JPY/USD
 * shops). Unknown currencies fall back to VND, matching the rest of pos-web.
 */
const QUICK_AMOUNTS_BY_CURRENCY: Record<string, number[]> = {
  VND: [50000, 100000, 200000, 500000],
  JPY: [1000, 2000, 5000, 10000],
  USD: [5, 10, 20, 50],
  EUR: [5, 10, 20, 50],
};

/**
 * Card / transfer tiles were previously hard-disabled (plan-008). Plan 047
 * T6.1 renders only resolver-backed options where the server marks
 * `client.supports_pos_checkout`.
 */

function optionIconCode(option: EffectivePaymentOption): string {
  return option.legacy_payment_method_code ?? option.rail;
}

/**
 * #1156 — the 決済端末 tile: the one method whose money actually rides a
 * physical multi-brand terminal, so it gets the brand sub-choice step.
 */
function isCardTerminalOption(option: EffectivePaymentOption): boolean {
  return (
    option.legacy_payment_method_code === "card_terminal" ||
    option.method_type === "card_terminal"
  );
}

/**
 * Plan-048 T1.2 — internal tenders (cash / standalone card terminal) are
 * appended to the effective-options list from the INTERNAL catalog
 * (`source: "internal_catalog"`, `connection_id: null`) because the
 * fail-closed policy resolver can never surface connection-less options.
 * They are recordTender-only: the backend contract records them WITHOUT
 * gateway identity (`Plan048CloudOnlyPosInternalTenderTest` asserts
 * `gateway_option_id` null), and echoing the catalog option id into
 * `gateway_option_id` trips `PaymentPolicySubmissionValidator` with
 * PAYMENT_OPTION_DISABLED on any branch whose ownership projection is
 * unresolved. So: never send gateway identity for internal options.
 */
function isInternalTenderOption(option: EffectivePaymentOption): boolean {
  return option.provider === "internal" || option.source === "internal_catalog";
}

function formatQuickLabel(amount: number): string {
  if (amount >= 1_000_000) return `${amount / 1_000_000}M`;
  if (amount >= 1_000) return `${amount / 1_000}k`;
  return String(amount);
}

function remainingOf(order: CustomerOrder): number {
  return Number(order.remaining_amount ?? order.total_amount ?? 0);
}

function formatError(e: unknown): string {
  if (e instanceof ApiError) {
    const errors = e.body?.errors as Record<string, string[]> | undefined;
    if (errors) {
      return Object.entries(errors)
        .map(([field, msgs]) => `${field}: ${msgs.join(", ")}`)
        .join(" · ");
    }
    return (e.body?.message as string | undefined) ?? `${e.status}: ${e.message}`;
  }
  if (e instanceof Error) return e.message;
  return "Unknown error";
}

export function PaymentDialog({
  open,
  onOpenChange,
  shopSlug,
  order,
  options,
  optionsLoading,
  optionsError,
  onRetryOptions,
  policyRevision,
  outstanding,
  outstandingLoading,
  onCreatePayment,
  onCreateDebtPayment,
  onDebtCharged,
  onPaymentSuccess,
  onSwitchToSplit,
  currencyCode,
}: PaymentDialogProps) {
  const quickAmounts =
    QUICK_AMOUNTS_BY_CURRENCY[(currencyCode ?? "VND").toUpperCase()] ??
    QUICK_AMOUNTS_BY_CURRENCY.VND;
  const { t, locale } = useTranslation();
  // Đọc thẳng từ hook thay vì nhận qua prop: cùng query key nên TanStack dedupe,
  // không phát sinh request nào — và `page.tsx` đang NẰM ĐÚNG TRÊN trần 926 dòng
  // (`page-size-budget.arch.test.ts`), thêm một prop ở đó là đỏ ngay.
  const shopOrderSettings = useShopOrderSettings(shopSlug ?? "");
  const network = useNetworkRequired();
  const {
    phase: terminalPhase,
    message: terminalMessage,
    charge: chargeTerminal,
    cancel: cancelTerminal,
    reset: resetTerminal,
  } = useCardTerminal(shopSlug ?? "", order?.id ?? "");
  // Gated on whether a workstation exists, NOT on the Cloud/LAN toggle: the
  // charge now goes straight to the workstation like every other LAN device, so
  // Cloud mode is irrelevant to the P400. What genuinely blocks it is having no
  // workstation to drive it — the terminal is behind NAT and Cloud has no route
  // that can reach it.
  const terminalReachable = cardTerminalAvailable();
  // 釣銭機 over the LAN workstation bridge (#1804). Same shape as the card
  // terminal above: a device the POS drives but does not own.
  const cashChanger = useCashChanger(shopSlug ?? "", order?.customer_id);
  useEffect(() => {
    if (!open) resetTerminal();
  }, [open, resetTerminal]);
  const [optionId, setOptionId] = useState<string | null>(null);
  /**
   * #1156 — brand sub-choice for the 決済端末 method. `null` = the "あとで /
   * 不明" path (no `tender_key` sent). NEVER blocks confirm.
   */
  const [tenderKey, setTenderKey] = useState<string | null>(null);
  const [tendered, setTendered] = useState<string>("");
  const [exactAmount, setExactAmount] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  /**
   * Master toggle for the outstanding-debt card. true → include all
   * debts in this transaction (added to grandTotal + fired as separate
   * payments). false → skip — staff still gets to settle the current
   * order, debts roll over to the customer's next visit.
   *
   * Default is `true` so the typical "settle now" flow is one click.
   * Reset to true on dialog close (handled below in the open-effect).
   */
  const [includeDebts, setIncludeDebts] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  /** Orders where the payment DID post — used to narrow the retry on partial failure. */
  const [settledOk, setSettledOk] = useState<Set<string>>(new Set());
  /**
   * Stable idempotency key per orderId step within this dialog session.
   * Lives across retries so the backend can dedup network-glitch retries
   * (request committed server-side but client got an error). Reset when
   * the dialog closes.
   */
  const [stepKeys, setStepKeys] = useState<Map<string, string>>(new Map());

  const currentRemaining = useMemo(
    () => (order ? remainingOf(order) : 0),
    [order],
  );

  // Outstanding debts are bundled with the current payment when
  // `includeDebts` is true (default). When staff unchecks the toggle on
  // the debt card, debts are skipped here and stay on the customer's
  // outstanding list for the next visit.
  const settleDebts = includeDebts ? outstanding : [];

  const debtTotal = settleDebts.reduce((s, o) => s + remainingOf(o), 0);
  const grandTotal = currentRemaining + debtTotal;

  // One decision, made once: loading / error / empty / ready. The grid, the
  // alerts and the confirm guard all read it, so they can never disagree about
  // whether a payment method exists.
  const optionsState = useMemo(
    () =>
      paymentOptionsState({
        loading: optionsLoading,
        error: optionsError,
        options,
      }),
    [optionsLoading, optionsError, options],
  );
  const checkoutOptions = useMemo(
    () => (optionsState.kind === "ready" ? optionsState.options : EMPTY_OPTIONS),
    [optionsState],
  );

  // #1156 — brand sub-choice data for the 決済端末 method. Tender list =
  // the branch's EFFECTIVE vocabulary (`/pos/till/tender-types`, per-branch
  // activation applied server-side), restricted to the union of registered
  // terminals' `accepts` once that endpoint ships (see usePaymentTerminals).
  // Queries are cheap + cached 5min; enabled only with a shop context.
  const tenderTypesQuery = useTenderTypes(shopSlug ?? "");
  const tenderCategoriesQuery = useTenderCategories(shopSlug ?? "");
  const terminalsQuery = usePaymentTerminals(shopSlug ?? "");
  const brandGroups = useMemo(
    () =>
      buildTenderBrandGroups(
        tenderTypesQuery.data ?? [],
        tenderCategoriesQuery.data ?? [],
        terminalsQuery.data ?? [],
        // The four seeded headings are system vocabulary, not shop-authored
        // text — translate them so the whole dialog is in one language.
        (category) => tenderCategoryLabel(category, t),
      ),
    [
      tenderTypesQuery.data,
      tenderCategoriesQuery.data,
      terminalsQuery.data,
      t,
    ],
  );

  const hasCheckoutOptions = checkoutOptions.length > 0;

  useEffect(() => {
    if (!open) {
      setOptionId(null);
      setTenderKey(null);
      setTendered("");
      setExactAmount(false);
      setSubmitting(false);
      setErrorMessage(null);
      setSettledOk(new Set());
      setStepKeys(new Map());
      setIncludeDebts(true);
    }
  }, [open]);

  const selectedOption =
    checkoutOptions.find((option) => option.id === optionId) ?? null;
  const requiresTendered = selectedOption?.client?.requires_tendered ?? false;

  const tenderedNumber = exactAmount ? grandTotal : parseFloat(tendered);
  const tenderedValid = !Number.isNaN(tenderedNumber) && tenderedNumber > 0;
  const change =
    requiresTendered && tenderedValid
      ? Math.max(0, tenderedNumber - grandTotal)
      : 0;
  /**
   * What the customer STILL owes after this cash handover. Computed for
   * display only — each OrderPayment row still records amount <= tendered.
   * Debts are paid FIRST (oldest first), so a partial tendered settles
   * old debt fully before applying to the current order's remaining.
   */
  const shortfall =
    requiresTendered && tenderedValid
      ? Math.max(0, grandTotal - tenderedNumber)
      : 0;

  /**
   * Only orders attached to a registered customer can carry unpaid balance
   * to the next visit — we need a customer_id to resurface the debt via
   * `GET /customers/{id}/outstanding`. Walk-in orders (customer_id=null)
   * MUST be paid in full; otherwise there is no one to bill later.
   */
  const hasCustomer = !!order?.customer_id;
  const shortfallBlocksConfirm =
    requiresTendered && tenderedValid && shortfall > 0 && !hasCustomer;

  // A method that doesn't require tendering (e.g. `card_terminal` — the bank's
  // card reader already took the amount) short-circuits the tendered clause, so
  // confirm is live the moment the tile is picked, with nothing typed.
  const canConfirm =
    !!order &&
    hasCheckoutOptions &&
    !!selectedOption &&
    (!requiresTendered || tenderedValid) &&
    !shortfallBlocksConfirm;

  // Verifone P400 (VescaJS) card terminal — an alternative to a payment tile.
  // The workstation drives the terminal + records the payment; on approval we
  // just close (queries were already invalidated by the hook).
  async function handleTerminalCharge() {
    if (!order) return;
    const approved = await chargeTerminal();
    if (approved) onOpenChange(false);
  }

  async function handleConfirm() {
    if (!order || !selectedOption || !canConfirm) return;

    const legacyMethodId =
      selectedOption.legacy_payment_method_id ?? selectedOption.id;

    // Plan: old debts first (oldest first), current order last. Partial
    // tendered → we distribute whatever cash was handed over across the
    // sequence. Each step gets min(remainingTendered, orderOwed). When
    // remainingTendered hits 0, we stop — remaining orders stay unpaid.
    // This way a crash halfway leaves the CURRENT order still open for
    // retry (rather than the reverse which would leave old debt unpaid).
    const totalForPayments = requiresTendered ? tenderedNumber : grandTotal;
    let remainingBudget = totalForPayments;

    const sequence: Array<{ orderId: string; amount: number; tendered: number }> =
      [];
    const steps: Array<{ orderId: string; owed: number }> = [
      ...settleDebts.map((d) => ({ orderId: d.id, owed: remainingOf(d) })),
      { orderId: order.id, owed: currentRemaining },
    ];
    for (const step of steps) {
      if (remainingBudget <= 0) break;
      const amount = Math.min(remainingBudget, step.owed);
      if (amount <= 0) continue;
      sequence.push({ orderId: step.orderId, amount, tendered: amount });
      remainingBudget -= amount;
    }

    /**
     * The cash handover is ONE event, but it can produce SEVERAL payment
     * rows (old debts first, current order last). The server derives
     * `change = tendered − amount − tip` PER ROW, so sending the full
     * handover on every row would hand back the change once per row —
     * a three-order settlement would report triple the change and break
     * the shift's cash reconciliation.
     *
     * So: every row tenders exactly what it consumes, and the surplus —
     * the notes that actually go back into the customer's hand — rides
     * the LAST row, which is the current order and the one the customer's
     * receipt is printed for.
     *
     * Without this the row tendered `amount`, change came out 0, and the
     * printed slip claimed the customer handed over the exact total no
     * matter what the cashier typed (¥2,000 on a ¥1,793 bill printed as
     * tendered ¥1,793 / change ¥0).
     *
     * Sums to zero surplus by construction when tendered ≤ owed (partial
     * cash on a customer account) and when the "exact amount" toggle is
     * on, so those paths are unchanged. The 0.001 epsilon mirrors the
     * server's own float guard in OrderPaymentService.
     */
    if (requiresTendered && sequence.length > 0) {
      const consumed = sequence.reduce((sum, p) => sum + p.amount, 0);
      const surplus = tenderedNumber - consumed;
      if (surplus > 0.001) {
        sequence[sequence.length - 1]!.tendered += surplus;
      }
    }

    // Skip entries that already posted in a previous attempt (partial
    // failure → staff hits "retry" → we don't double-charge).
    const toFire = sequence.filter((p) => !settledOk.has(p.orderId));

    setErrorMessage(null);
    setSubmitting(true);

    const newOk = new Set(settledOk);
    const newKeys = new Map(stepKeys);
    const failures: Array<{ orderId: string; orderCode: string; message: string }> = [];

    for (const step of toFire) {
      const target =
        step.orderId === order.id
          ? order
          : outstanding.find((o) => o.id === step.orderId);
      const orderCode = target?.order_code ?? step.orderId.slice(0, 8);
      let key = newKeys.get(step.orderId);
      if (!key) {
        key = newIdempotencyKey();
        newKeys.set(step.orderId, key);
      }
      // Internal tenders carry NO gateway identity (see isInternalTenderOption).
      const internal = isInternalTenderOption(selectedOption);
      try {
        await onCreatePayment(step.orderId, {
          payment_method_id: legacyMethodId,
          gateway_option_id: internal ? undefined : selectedOption.id,
          gateway_connection_id: internal
            ? undefined
            : selectedOption.connection_id,
          policy_revision: internal ? undefined : policyRevision,
          amount: step.amount,
          tendered_amount: requiresTendered ? step.tendered : undefined,
          idempotency_key: key,
          // #1156 — brand attribution for the terminal method. One physical
          // tap covers the whole sequence (debts + current order), so every
          // step carries the same key. Omitted on skip / other methods.
          tender_key:
            isCardTerminalOption(selectedOption) && tenderKey
              ? tenderKey
              : undefined,
        });
        newOk.add(step.orderId);
      } catch (e) {
        failures.push({
          orderId: step.orderId,
          orderCode,
          message: formatError(e),
        });
        break; // don't continue after a failure — let staff retry
      }
    }

    setSettledOk(newOk);
    setStepKeys(newKeys);
    setSubmitting(false);

    if (failures.length === 0) {
      // Hand the parent a snapshot of every transaction we just posted so
      // it can show the post-payment receipt screen. Fired BEFORE close so
      // the parent can react synchronously (e.g. open the receipt dialog
      // before our `onOpenChange(false)` hits its own success-cleanup
      // handler).
      if (onPaymentSuccess) {
        const entries = sequence.map((s) => {
          const target =
            s.orderId === order.id
              ? order
              : outstanding.find((o) => o.id === s.orderId);
          return {
            orderId: s.orderId,
            orderCode: target?.order_code ?? s.orderId.slice(0, 8),
            amount: s.amount,
            isDebt: s.orderId !== order.id,
          };
        });
        onPaymentSuccess({
          entries,
          totalPaid: entries.reduce((sum, e) => sum + e.amount, 0),
          tendered: requiresTendered ? tenderedNumber : grandTotal,
        });
      }
      onOpenChange(false);
      return;
    }

    const first = failures[0];
    setErrorMessage(
      `${first.orderCode}: ${first.message}`,
    );
  }

  const hasOutstanding = !outstandingLoading && outstanding.length > 0;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[88vh] max-w-lg flex-col gap-0 rounded-2xl p-0 sm:max-w-lg sm:rounded-lg">
        <DialogHeader className="shrink-0 px-4 pb-2 pt-4 sm:px-6 sm:pt-6">
          <div className="flex items-center gap-1.5">
            <DialogTitle className="text-lg font-bold sm:text-xl">
              {t("pos.dialog.payment.title")}
            </DialogTitle>
            <HelpButton topic="payment" className="size-7" />
          </div>
          <DialogDescription className="text-xs sm:text-sm">
            {order ? (
              <>
                <span className="font-mono">{order.order_code}</span>
                <span className="mx-1.5 text-muted-foreground/60">•</span>
                <span className="font-semibold text-foreground tabular-nums">
                  {formatCurrency(currentRemaining)}
                </span>
              </>
            ) : (
              t("pos.dialog.payment.no_order")
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="flex-1 space-y-4 overflow-y-auto px-4 pb-4 pt-2 sm:px-6">
          {/* Bảng tách tiền — BẮT BUỘC kể từ luồng một-chạm.
              Thu ngân giờ vào thẳng đây từ giỏ hàng, không còn đi ngang màn
              soạn giảm giá, nên nếu không có khối này họ sẽ nhận tiền mà chưa
              từng nhìn thấy thuế và phí phục vụ của đơn.
              Mọi con số đến từ `orderTotals()` — cùng một hàm giỏ hàng dùng. */}
          {order && (
            <OrderTotalsSummary
              order={order}
              pricesIncludeTax={
                shopOrderSettings.data?.data?.prices_include_tax ?? false
              }
            />
          )}

          {/* Mã giảm giá — ĐÓNG lỗ mà luồng một-chạm mở ra.
              Thu ngân chạm "Tính tiền" xong khách mới đưa mã là kịch bản kẹt
              cứng trước đây: đơn đã sang `checkout`, ô nhập mã của giỏ biến
              mất, và không có route nào đưa đơn về `open`.
              Cả Cloud lẫn workstation đều còn cho áp/gỡ mã ở `checkout` —
              `couponMayBeChanged` là bản sao có chủ đích của hai allowlist đó,
              và nó ẩn ô này khi đơn đã sang `paying` (đã nhận một phần tiền,
              đổi tổng là làm sai khoản đã thu). */}
          {order && shopSlug && couponMayBeChanged(order) && (
            <div className="rounded-xl border bg-muted/30 px-3.5 py-3 text-sm">
              <CouponField shopSlug={shopSlug} order={order} />
            </div>
          )}

          {/* Switch to split-bill — only when parent provided the handler. */}
          {onSwitchToSplit && (
            <button
              type="button"
              onClick={onSwitchToSplit}
              disabled={submitting}
              className={cn(
                "flex w-full items-start gap-3 rounded-xl border bg-muted/30 p-3.5 text-left transition-colors",
                "hover:bg-muted/50",
                "disabled:cursor-not-allowed disabled:opacity-50",
              )}
            >
              <Checkbox
                checked={false}
                aria-hidden
                tabIndex={-1}
                className="mt-0.5 pointer-events-none"
              />
              <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <div className="flex items-center gap-1.5 text-sm font-semibold text-foreground">
                  <UsersIcon className="size-4" />
                  {t("pos.dialog.payment.split_bill_label")}
                </div>
                <p className="text-xs leading-snug text-muted-foreground">
                  {t("pos.dialog.payment.split_bill_desc")}
                </p>
              </div>
            </button>
          )}

          {outstandingLoading && <Skeleton className="h-24 w-full rounded-xl" />}

          {/* Outstanding-debt summary card — replaces the underpayment info
              card whenever the customer has unsettled balance from previous
              orders. All debts are auto-included in this transaction (no
              per-debt checkbox); the staff sees the aggregated total here
              + a "+ Nợ cũ" line in the totals breakdown below. */}
          {hasOutstanding ? (
            <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-4">
              <div className="flex items-start gap-2.5">
                <AlertCircleIcon className="mt-0.5 size-5 shrink-0 text-destructive" />
                <div className="min-w-0 flex-1 space-y-2.5">
                  <div className="space-y-0.5">
                    <div className="text-sm font-bold text-destructive">
                      {t("pos.dialog.payment.has_debt_title")}
                    </div>
                    <div className="text-xs text-muted-foreground">
                      {/* SĐT • Chi tiết: ORD-A, ORD-B + N nữa */}
                      {order?.customer?.phone && (
                        <>
                          <span>
                            {t("pos.dialog.payment.has_debt_phone")}:{" "}
                            <span className="font-medium text-foreground">
                              {order.customer.phone}
                            </span>
                          </span>
                          <span className="mx-1.5 text-muted-foreground/60">
                            •
                          </span>
                        </>
                      )}
                      <span>
                        {t("pos.dialog.payment.has_debt_detail")}:{" "}
                        <span className="font-mono font-medium text-foreground">
                          {outstanding.slice(0, 2).map((d) => d.order_code).join(", ")}
                          {outstanding.length > 2 && (
                            <span className="ml-1 font-sans text-muted-foreground">
                              {t("pos.dialog.payment.has_debt_more", {
                                count: outstanding.length - 2,
                              })}
                            </span>
                          )}
                        </span>
                      </span>
                    </div>
                  </div>
                  {/* Master toggle — staff can choose to skip these debts
                      and let them roll over to the customer's next visit.
                      When unchecked, the amount box dims so it's clear the
                      total below no longer includes this. */}
                  <label className="flex cursor-pointer items-center gap-2 text-sm font-medium text-foreground">
                    <Checkbox
                      checked={includeDebts}
                      disabled={submitting}
                      onCheckedChange={(c) => setIncludeDebts(!!c)}
                    />
                    <span>{t("pos.dialog.payment.has_debt_include")}</span>
                  </label>
                  <div
                    className={cn(
                      "flex items-center justify-between rounded-lg border bg-background px-3 py-2 transition-opacity",
                      !includeDebts && "opacity-40",
                    )}
                    aria-disabled={!includeDebts}
                  >
                    <span className="text-sm text-muted-foreground">
                      {t("pos.dialog.payment.has_debt_total")}
                    </span>
                    <span
                      className={cn(
                        "text-base font-bold tabular-nums text-foreground",
                        !includeDebts && "line-through",
                      )}
                    >
                      {formatCurrency(debtTotal)}
                    </span>
                  </div>
                  <p className="text-[11px] leading-snug text-muted-foreground">
                    {includeDebts
                      ? t("pos.dialog.payment.has_debt_hint")
                      : t("pos.dialog.payment.has_debt_skip_hint")}
                  </p>
                </div>
              </div>
            </div>
          ) : (
            /* No debts — but customer is registered: surface the "allow
                underpayment" info card so staff sees the option exists. */
            hasCustomer && (
              <div className="rounded-xl border border-primary/30 bg-primary/5 px-4 py-3">
                <div className="text-sm font-semibold text-primary">
                  {t("pos.dialog.payment.allow_debt_label")}
                </div>
                <p className="mt-0.5 text-xs leading-snug text-primary/80">
                  {t("pos.dialog.payment.allow_debt_desc")}
                </p>
              </div>
            )
          )}

          {/* Payment method grid — resolver-backed checkout-capable options only */}
          <div>
            <h3 className="mb-2.5 text-base font-bold text-foreground">
              {t("pos.dialog.payment.method_label")}
            </h3>
            {optionsState.kind === "loading" ? (
              <div className="grid grid-cols-2 gap-2.5">
                <Skeleton className="h-16 w-full" />
                <Skeleton className="h-16 w-full" />
                <Skeleton className="h-16 w-full" />
                <Skeleton className="h-16 w-full" />
              </div>
            ) : optionsState.kind === "error" ? (
              /* The request never landed — a connection fault the cashier can
                 retry. Distinct from the «nothing configured» card below, which
                 needs a manager: telling the cashier to call one over a dropped
                 LAN request wastes a shift. */
              <Alert variant="destructive">
                <AlertCircleIcon className="size-4" />
                <AlertDescription>
                  <span className="block font-semibold">
                    {t("pos.dialog.payment.options_error_title")}
                  </span>
                  <span className="mt-1 block text-sm">
                    {t("pos.dialog.payment.options_error_desc")}
                  </span>
                  {optionsError != null && (
                    <span className="mt-1 block text-xs opacity-80">
                      {formatError(optionsError)}
                    </span>
                  )}
                  {onRetryOptions && (
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      className="mt-2"
                      onClick={onRetryOptions}
                    >
                      {t("pos.dialog.payment.options_error_retry")}
                    </Button>
                  )}
                </AlertDescription>
              </Alert>
            ) : optionsState.kind === "empty" ? (
              <Alert variant="destructive">
                <AlertCircleIcon className="size-4" />
                <AlertDescription>
                  <span className="block font-semibold">
                    {t("pos.dialog.payment.no_effective_options_title")}
                  </span>
                  <span className="mt-1 block text-sm">
                    {t("pos.dialog.payment.no_effective_options_desc")}
                  </span>
                </AlertDescription>
              </Alert>
            ) : (
              <div className="grid grid-cols-2 gap-2.5">
                {checkoutOptions.map((option) => {
                  const Icon = iconFor(optionIconCode(option));
                  const picked = optionId === option.id;
                  return (
                    <button
                      key={option.id}
                      type="button"
                      onClick={() => {
                        setOptionId(option.id);
                        // Brand choice only applies to the terminal method;
                        // switching methods resets it.
                        if (!isCardTerminalOption(option)) setTenderKey(null);
                      }}
                      className={cn(
                        "flex h-16 w-full cursor-pointer items-center gap-3 rounded-xl border-2 px-4 text-left transition-all",
                        picked
                          ? "border-primary bg-primary/5 shadow-sm"
                          : "border-border bg-background hover:border-foreground/30 hover:bg-muted/40",
                      )}
                    >
                      <span
                        className={cn(
                          "flex size-9 shrink-0 items-center justify-center rounded-md",
                          picked
                            ? "text-primary"
                            : "text-muted-foreground",
                        )}
                      >
                        <Icon className="size-5" />
                      </span>
                      <span
                        className={cn(
                          "text-sm font-semibold",
                          picked ? "text-primary" : "text-foreground",
                        )}
                      >
                        {option.display_name}
                      </span>
                    </button>
                  );
                })}
              </div>
            )}
          </div>

          {/* #1156 — terminal brand sub-choice. When the cashier picked the
              決済端末 method, offer the brands the branch's terminal(s)
              accept, grouped card / QR / e-money. The chosen key rides the
              payment as `tender_key` (attribution only). Skippable — the
              "あとで / 不明" chip is the default and confirm NEVER blocks on
              this choice. */}
          {selectedOption &&
            isCardTerminalOption(selectedOption) &&
            brandGroups.length > 0 && (
              <div className="rounded-xl border bg-muted/20 p-3.5">
                <div className="mb-1 text-sm font-semibold text-foreground">
                  {t("pos.dialog.payment.tender_brand_label")}
                </div>
                <p className="mb-2.5 text-xs leading-snug text-muted-foreground">
                  {t("pos.dialog.payment.tender_brand_hint")}
                </p>
                <button
                  type="button"
                  onClick={() => setTenderKey(null)}
                  disabled={submitting}
                  className={cn(
                    "mb-2.5 h-9 w-full cursor-pointer rounded-lg border px-3 text-left text-sm font-medium transition-colors",
                    tenderKey === null
                      ? "border-primary bg-primary/5 text-primary"
                      : "border-border bg-background text-muted-foreground hover:bg-muted/40",
                  )}
                >
                  {t("pos.dialog.payment.tender_brand_skip")}
                </button>
                <div className="space-y-2.5">
                  {brandGroups.map((group) => (
                    <div key={group.categoryKey}>
                      <div className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                        {group.categoryName}
                      </div>
                      <div className="flex flex-wrap gap-1.5">
                        {group.tenders.map((tt) => {
                          const picked = tenderKey === tt.tender_key;
                          return (
                            <button
                              key={tt.tender_key}
                              type="button"
                              onClick={() => setTenderKey(tt.tender_key)}
                              disabled={submitting}
                              className={cn(
                                "h-9 cursor-pointer rounded-lg border px-3 text-sm font-medium transition-colors",
                                picked
                                  ? "border-primary bg-primary/10 text-primary"
                                  : "border-border bg-background text-foreground hover:bg-muted/40",
                              )}
                              aria-pressed={picked}
                            >
                              {tenderDisplayName(tt, locale)}
                            </button>
                          );
                        })}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}

          {/* Totals card */}
          {selectedOption && (
            <div className="space-y-2 rounded-xl bg-muted/40 px-4 py-3 text-sm">
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">
                  {t("pos.dialog.payment.current_order")}
                </span>
                <span className="font-semibold tabular-nums">
                  {formatCurrency(currentRemaining)}
                </span>
              </div>
              {/* Old-debt line: destructive tone with red-tinted strip
                  highlights it inside the otherwise neutral totals card. */}
              {debtTotal > 0 && (
                <div className="-mx-2 flex items-center justify-between rounded-lg border border-destructive/20 bg-destructive/5 px-3 py-1.5">
                  <span className="text-destructive">
                    {t("pos.dialog.payment.debt_row", {
                      count: settleDebts.length,
                    })}
                  </span>
                  <span className="font-semibold tabular-nums text-destructive">
                    {formatCurrency(debtTotal)}
                  </span>
                </div>
              )}
              <div className="flex items-baseline justify-between border-t border-border/60 pt-2">
                <span className="text-sm font-semibold text-foreground">
                  {t("pos.dialog.payment.total")}
                </span>
                <span className="text-xl font-bold tabular-nums text-primary">
                  {formatCurrency(grandTotal)}
                </span>
              </div>
            </div>
          )}

          {/* Tendered amount + quick presets */}
          {selectedOption && requiresTendered && (
            <div>
              <div className="mb-2 flex items-center justify-between">
                <h3 className="text-base font-bold text-foreground">
                  {t("pos.dialog.payment.tendered")}
                </h3>
                <label className="flex cursor-pointer items-center gap-2 text-sm">
                  <Checkbox
                    checked={exactAmount}
                    onCheckedChange={(c) => {
                      setExactAmount(!!c);
                      if (c) setTendered("");
                    }}
                  />
                  <span className="text-foreground">
                    {t("pos.dialog.payment.exact_amount")}
                  </span>
                </label>
              </div>
              <Input
                id="tendered-amount"
                type="number"
                min={0}
                step="1000"
                value={exactAmount ? String(grandTotal) : tendered}
                disabled={exactAmount}
                onChange={(e) => setTendered(e.target.value)}
                placeholder={String(grandTotal)}
                className="h-12 rounded-lg text-right text-lg font-semibold tabular-nums"
              />
              <div className="mt-2 grid grid-cols-4 gap-2">
                {quickAmounts.map((amt) => (
                  <button
                    key={amt}
                    type="button"
                    onClick={() => {
                      // Additive: clicking adds to the running tendered
                      // total — staff handing-over-bills workflow ("got
                      // 200k + 200k + 50k for a 423.390 đ order").
                      // "Đưa đủ" turns off automatically since the staff
                      // is now tendering manually.
                      if (exactAmount) {
                        setExactAmount(false);
                        setTendered(String(amt));
                        return;
                      }
                      const current = parseFloat(tendered);
                      const next =
                        Number.isFinite(current) && current > 0
                          ? current + amt
                          : amt;
                      setTendered(String(next));
                    }}
                    disabled={submitting}
                    className={cn(
                      "h-11 cursor-pointer rounded-lg bg-muted/60 text-sm font-semibold text-foreground transition-colors",
                      "hover:bg-muted",
                      "disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-muted/60",
                    )}
                  >
                    {formatQuickLabel(amt)}
                  </button>
                ))}
              </div>
              {tenderedValid && change > 0 && (
                <div className="mt-2 text-sm text-muted-foreground">
                  {t("pos.dialog.payment.change")}{" "}
                  <span className="font-semibold text-foreground">
                    {formatCurrency(change)}
                  </span>
                </div>
              )}
              {tenderedValid && shortfall > 0 && hasCustomer && (
                <div className="mt-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100">
                  {t("pos.dialog.payment.shortfall_warning", {
                    amount: formatCurrency(shortfall),
                  })}
                </div>
              )}
              {tenderedValid && shortfall > 0 && !hasCustomer && (
                <div className="mt-2 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-900 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-100">
                  {t("pos.dialog.payment.walkin_error", {
                    amount: formatCurrency(grandTotal),
                  })}
                </div>
              )}
            </div>
          )}

          {errorMessage && (
            <Alert variant="destructive">
              <AlertCircleIcon className="size-4" />
              <AlertDescription>{errorMessage}</AlertDescription>
            </Alert>
          )}

          {/* "Ghi nợ toàn bộ" — charge the whole remaining balance to the
              customer's account instead of collecting cash now. Sits outside
              the footer so the primary Huỷ/Xác nhận pair keeps its 1fr_2fr
              grid. The button disables itself (with an explanatory tooltip)
              for walk-ins, mirroring the backend's 422
              customer_required_for_debt. */}
          {onCreateDebtPayment && order && currentRemaining > 0 && (
            <div className="[&>button]:h-11 [&>button]:w-full [&>button]:rounded-lg">
              <DebtChargeButton
                mode="full"
                orderId={order.id}
                customerId={order.customer_id}
                amount={currentRemaining}
                shopSlug={shopSlug ?? ""}
                disabled={submitting}
                onCreatePayment={(body) => onCreateDebtPayment(order.id, body)}
                onCharged={(paymentId) => {
                  onOpenChange(false);
                  onDebtCharged?.(paymentId, currentRemaining, order.id);
                }}
              />
            </div>
          )}
        </div>

        {/* 釣銭機 — only when a workstation is paired. A shop without one has no
            LAN host for the driver at all (the machine is HTTP-only + IP
            allowlisted), so there is nothing to offer: Gate 8 ruling A is
            "want a cash recycler → install a workstation" (#1804). */}
        {/* `py-3`, KHÔNG phải `pt-3`: khối này nằm ngay trên khối máy quẹt
            thẻ, mà khối đó mở đầu bằng `border-t`. Chỉ đệm trên thì nút dán sát
            vào đường kẻ đó — đo được 0px. */}
        {order && cashChanger.available && requiresTendered && (
          <div className="shrink-0 px-4 py-3 sm:px-6">
            <Button
              variant="outline"
              onClick={() => void cashChanger.start(order.id)}
              // #2535 B7 — máy CHỈ thu cho đơn hiện tại. `cashChanger.start()`
              // gửi mỗi `order_id`, còn workstation đọc phần còn thiếu của
              // riêng đơn đó — không dòng payment nào được tạo cho các đơn nợ
              // đang tick, mà thu ngân thì vừa nhìn thấy `grandTotal` gồm cả
              // chúng ngay phía trên nút này. Khoá nút thay vì thu thiếu trong
              // im lặng: thiếu tiền mà tưởng đã tất toán là thứ chỉ lộ ra ở lần
              // khách sau quay lại.
              disabled={
                submitting ||
                cashChanger.busy ||
                !!cashChanger.session ||
                settleDebts.length > 0
              }
              className="h-11 w-full rounded-lg"
            >
              {t("pos.dialog.payment.cash_changer.button")}
            </Button>
            {settleDebts.length > 0 && (
              <p className="pt-1 text-sm text-muted-foreground">
                {t("pos.dialog.payment.cash_changer.debt_blocked")}
              </p>
            )}
            {cashChanger.error && (
              <p className="pt-1 text-sm text-destructive">{cashChanger.error}</p>
            )}
          </div>
        )}

        {/* `py-3`, KHÔNG phải `pt-3`.
            Đo trên trình duyệt thật (hộp thoại rộng 512px): khối này kết thúc ở
            y=570 và `DialogFooter` bắt đầu ở y=570 — nút "Quẹt thẻ (P400)" dán
            KHÍT vào `border-t` của chân hộp thoại, không một pixel thở. Chân
            hộp thoại dùng `py-3` cân đối, nên đệm dưới ở đây phải khớp. */}
        {order && (
          <div className="shrink-0 space-y-2 border-t px-4 py-3 sm:px-6">
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                onClick={handleTerminalCharge}
                disabled={
                  submitting || terminalPhase === "processing" || !terminalReachable
                }
                className="h-11 flex-1 rounded-lg"
              >
                {t("pos.dialog.payment.card_terminal")}
              </Button>
              {/* Its own topic: the reader is a separate device with its own
                  prerequisites, and its "outcome unknown" rule is the one a
                  cashier must not improvise on. */}
              <HelpButton topic="card-terminal" className="size-9 shrink-0" />
            </div>
            {!terminalReachable && (
              <p className="text-xs text-muted-foreground">
                {t("pos.dialog.payment.terminal_no_workstation")}
              </p>
            )}
            {/* No outcome came back, so the dialog stays open and the order
                stays open. Charging again on a hunch is how the same card gets
                taken twice. */}
            {terminalPhase === "unknown" && terminalMessage && (
              <p
                role="alert"
                className="rounded-md bg-amber-50 px-3 py-2 text-xs font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200"
              >
                {terminalMessage}
              </p>
            )}
          </div>
        )}

        <DialogFooter className="grid shrink-0 grid-cols-[1fr_2fr] gap-2 border-t bg-muted/20 px-4 py-3 sm:px-6">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={submitting}
            className="h-11 rounded-lg"
          >
            {t("common.cancel")}
          </Button>
          <Button
            onClick={handleConfirm}
            disabled={!canConfirm || submitting}
            /* #1501 — mất mạng thì KHOÁ HẲN, không xếp hàng. Một hành động
               tiền đồng bộ muộn một giờ là sai ở thời điểm nó chạy. */
            {...network.blockedProps}
            className="h-11 rounded-lg text-base font-semibold"
          >
            {submitting
              ? t("pos.dialog.payment.processing")
              : errorMessage
                ? t("common.retry")
                : t("pos.dialog.payment.confirm")}
          </Button>
        </DialogFooter>

        {cashChanger.session && (
          <CashChangerOverlay
            session={cashChanger.session}
            busy={cashChanger.busy}
            outcomeUnknown={cashChanger.outcomeUnknown}
            onCancel={() => void cashChanger.cancel()}
            onDismiss={() => {
              // #2535 B1 — đường 釣銭機 KHÔNG đi qua `handleConfirm`, mà
              // `onPaymentSuccess` chỉ được gọi ở trong đó. Hệ quả: thu xong
              // không màn biên lai nào mở ra, và màn biên lai là **cửa duy
              // nhất** dẫn tới nút "In biên lai" + `RedInvoiceDialog`. Đơn đã
              // đóng thì không mở lại được (`reopenOrder` chặn 409 khi đã thu
              // tiền), nên tờ giấy đó mất VĨNH VIỄN.
              //
              // `cashCollectedAndRecorded` chứ không phải `status === "finish"`:
              // workstation trả `finish` cả cho ca đã thu tiền mà ghi sổ hỏng,
              // và mở màn biên lai cho một khoản chưa vào sổ là in giấy cho một
              // giao dịch không tồn tại (#2535 B3).
              const session = cashChanger.session;
              const recorded = session !== null && cashCollectedAndRecorded(session);

              cashChanger.dismiss();

              // `!order` nằm trong rào này chứ không phải một khẳng định
              // non-null bên dưới: `order` là `Order | null`, và ba dòng dựng
              // `entries` đọc `order.id` / `order.order_code`. Thiếu nó thì
              // `tsc -b` đỏ (TS18047 ×3) — mà Amplify chạy `pnpm run build`,
              // tức `tsc -b && vite build`, nên deploy pos-web production hỏng.
              if (!recorded || !session || !order) {
                return;
              }

              // Máy trạm là NGƯỜI GHI DUY NHẤT của luồng này (xem
              // `web/pos/CLAUDE.md` §釣銭機) — pos-web không tạo payment ở đây,
              // chỉ chuyển tiếp kết quả để màn biên lai mở đúng như mọi phương
              // thức khác. Một dòng, không nợ: máy chỉ thu cho đơn hiện tại.
              onPaymentSuccess?.({
                entries: [
                  {
                    orderId: order.id,
                    orderCode: order.order_code ?? order.id.slice(0, 8),
                    amount: session.total,
                    isDebt: false,
                  },
                ],
                totalPaid: session.total,
                tendered: session.tendered,
              });
              onOpenChange(false);
            }}
          />
        )}

        {terminalPhase === "processing" && (
          <div className="fixed inset-0 z-[60] flex flex-col items-center justify-center gap-6 bg-background/95">
            <div className="absolute right-4 top-4">
              <HelpButton topic="card-terminal" />
            </div>
            <div className="h-10 w-10 animate-spin rounded-full border-4 border-muted border-t-primary" />
            <p className="text-lg font-semibold">
              {t("pos.dialog.payment.terminal_processing")}
            </p>
            <Button variant="outline" onClick={cancelTerminal} className="h-11 rounded-lg">
              {t("pos.dialog.payment.terminal_cancel")}
            </Button>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
