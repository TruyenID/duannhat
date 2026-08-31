/**
 * SplitBillEvenTab — body + footer of the "Chia đều" tab inside
 * SplitBillDialog. Owns its row state, snapshot persistence, drift
 * recovery, refund/print row actions and the all-rows-paid callback.
 *
 * Extracted from split-bill-dialog.tsx in plan-021 T3.1 so the dialog
 * can host a second tab (SplitBillByItemsTab) without duplicating the
 * Dialog/Content shell. Behaviour intentionally unchanged.
 *
 * Historical context for the equal-split flow (kept verbatim):
 *
 * Backend `GET /split-bill` chỉ trả về phép tính (mảng số tiền per
 * person). Việc thu tiền vẫn đi qua `POST /payments` từng lần — mỗi
 * row ở đây = 1 OrderPayment riêng. Khi tất cả rows đã succeed,
 * paid_amount = total_amount → backend tự đóng order (cash auto-confirm)
 * hoặc giữ status `paying` (card/transfer chờ confirm).
 *
 * Quan trọng: snapshot `per_person_amounts` ngay lần đầu split-bill
 * load — không refetch sau mỗi payment, vì backend chia
 * `remaining/split_count` mỗi lần gọi → sẽ ra số khác nhau khi
 * paid_amount đã tăng. Sau khi snapshot, staff không đổi split_count
 * được nữa (lock input).
 *
 * Snapshot persistence (B1): the snapshot is also written to
 * localStorage keyed by order id, so a tab refresh / mid-shift restart
 * doesn't force staff to re-explain amounts to customers. Snapshot is
 * invalidated when `order.total_amount` drifts from the saved value
 * (item void / discount applied) — the backend `split_bill_total_drift`
 * 422 (B2) confirms this on the server side and the dialog prompts
 * staff to recalculate.
 */

import { useEffect, useMemo, useRef, useState } from "react";
import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  DialogFooter,
  Input,
  Label,
  Progress,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Skeleton,
} from "@godxjp/ui";
import {
  AlertCircleIcon,
  BanknoteIcon,
  CheckCircle2Icon,
  MergeIcon,
  MinusIcon,
  PlusIcon,
  PrinterIcon,
  Undo2Icon,
  XCircleIcon,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { ApiError } from "@/lib/api";
import { isRefundBlockedByWorkstationShift } from "@/lib/api-error";
import { formatTime } from "@/lib/format-date";
import { useTranslation } from "@/providers/app-provider";
import type { CustomerOrder, PaymentMethod } from "../types";
import { iconFor } from "../lib/payment-method-icon";
import { formatCurrency, getCurrencySymbol, getActiveCurrency } from "../lib/totals";
import { redistributeEqualSplit } from "../lib/equal-split";
import { computeCashTender } from "../lib/cash-tender";
import { CashTenderField } from "./cash-tender-field";
import { Spinner } from "@/components/ui/spinner";
import type { CashChangerSplitMetadata } from "@/services/workstation-cash-changer-service";

function evenSplitMetadata(
  billIndex: number,
  totalBills: number
): Extract<CashChangerSplitMetadata, { split_mode: "even" }> {
  return { split_mode: "even", bill_index: billIndex, total_bills: totalBills };
}

export interface SplitPaymentBody {
  payment_method_id: string;
  amount: number;
  /**
   * Cash the customer actually handed over for THIS row. Required (server-side)
   * whenever the method has `requires_tendered`; both the workstation LAN route
   * and Cloud derive `change = tendered − amount − tip` from it and refuse a
   * value below the charge. All three split tabs compute it through
   * `../lib/cash-tender` — they used to hard-code the row's own amount here,
   * which made every cash slip read "thối 0".
   */
  tendered_amount?: number;
  /**
   * Stable per-attempt UUID. The dialog generates one when staff first
   * starts paying a row and reuses it on retries so the backend can
   * deduplicate retries that happened because of a network glitch — the
   * second POST returns the same payment instead of creating a duplicate.
   * Resets if staff changes payment method on a failed row (treated as
   * a new attempt).
   */
  idempotency_key?: string;
  /**
   * The order total the snapshot was computed against. Backend rejects
   * with code `split_bill_total_drift` if `order.total_amount` has moved
   * since.
   */
  expected_total_amount?: number;
  /** Optional free-form note (by-items mode embeds the bill label + items). */
  note?: string;
  /**
   * Plan-021 — split-bill audit metadata. Equal mode populates
   * {split_mode: 'even', bill_index, total_bills}; by-items mode adds
   * `label` + `item_allocations`. Parent's `onCreatePayment` forwards it
   * to the backend as-is.
   */
  metadata?:
    | {
        split_mode: "even";
        bill_index: number;
        total_bills: number;
      }
    | {
        split_mode: "by_items";
        bill_index: number;
        total_bills: number;
        label: string;
        item_allocations: Array<{ item_id: string; units: number }>;
      }
    | {
        /** Plan-038 T5.2 — by-amount per person; no item allocation. */
        split_mode: "by_amount";
        bill_index: number;
        total_bills: number;
        label: string;
        amount: number;
      };
}

export interface SplitBillEvenTabProps {
  /**
   * Whether the parent dialog is currently open. Hydration effects (row
   * snapshot, persisted snapshot read) only run while `open === true`;
   * closing the dialog resets local state in the same effect.
   */
  open: boolean;
  /**
   * Forwarded close handler. The bottom "Đóng / Huỷ" button calls
   * `onOpenChange(false)` directly; the parent dialog should also drive
   * this via its scrim / Escape handler.
   */
  onOpenChange: (open: boolean) => void;
  order: CustomerOrder | null;
  methods: PaymentMethod[];
  methodsLoading: boolean;
  /** Snapshot of per-person amounts from `GET /split-bill`. */
  splitData: {
    per_person_amounts: string[];
    rounding_note: string | null;
    remaining_amount: string;
  } | null;
  splitLoading: boolean;
  splitError: string | null;
  /** Current split count input — controlled by parent so it can drive the query. */
  splitCount: number;
  onChangeSplitCount: (next: number) => void;
  /**
   * Fire one payment for the active order. Parent handles cache
   * invalidation. Returns the created payment so the dialog can record
   * the id for later refund. The dialog catches errors per-row and
   * surfaces them inline so other rows can still complete.
   */
  onCreatePayment: (body: SplitPaymentBody) => Promise<{ id: string }>;
  /**
   * Refund a previously settled row. Resets the row to idle state so
   * staff can re-collect from the right person/method. Returns nothing —
   * cache invalidation lives in the parent.
   */
  onRefundPayment: (paymentId: string) => Promise<void>;
  /**
   * Thu phần của MỘT hàng bằng máy 釣銭機 (#2946).
   *
   * Trả về payment do MÁY TRẠM tạo, hoặc `null` khi tiền không vào được sổ.
   * Hàng đi đường này **không bao giờ** chạm `onCreatePayment`: máy trạm là
   * người ghi duy nhất của luồng 釣銭機 (`web/pos/CLAUDE.md` §釣銭機), nên một
   * POST thứ hai ở đây là **thu tiền khách hai lần**.
   *
   * Vắng prop ⇒ không có nút nào hiện ra và tab chạy y như trước.
   */
  onCollectWithMachine?: (
    amount: number,
    metadata: CashChangerSplitMetadata
  ) => Promise<{ id: string; tendered?: number; change?: number } | null>;
  /** Máy có mặt và rảnh. Máy chỉ có MỘT nên một lượt thu khoá mọi hàng khác. */
  machineIdle?: boolean;
  /**
   * Print receipt for a single succeeded row via the LAN workstation
   * app. May be unavailable when no workstation is reachable — the
   * button surfaces failures inline. Optional: parents that don't wire
   * a workstation client can omit it and the print buttons are hidden.
   */
  onPrintRowReceipt?: (paymentId: string) => Promise<void>;
  /**
   * Cancel split-bill and switch to the regular PaymentDialog (single
   * payment for the whole order). Only callable while no row has been
   * settled — the parent should keep the button disabled otherwise.
   * Implementations should clear the snapshot before opening the
   * regular dialog so the fallback doesn't reopen the split flow.
   */
  onCancelSplit: () => void;
  /**
   * Fired in the same tick that the LAST `idle/failed` row transitions to
   * `succeeded` — i.e. the moment all guests have paid in full. Parent
   * uses this to capture the per-guest snapshot and open
   * `SplitBillReceiptDialog`. Optional: when omitted SplitBillDialog
   * keeps its old behaviour and the parent's `onOpenChange` close handler
   * decides what to do.
   */
  onAllRowsPaid?: (data: SplitBillSessionResult) => void;
}

/**
 * Snapshot of every settled guest passed to `onAllRowsPaid` so the parent
 * can render a receipt confirmation screen with selectable rows for print.
 */
export interface SplitBillSessionResult {
  /**
   * Plan-021 — which split mode produced this session. Undefined on the
   * legacy equal-only path. The receipt dialog uses this to decide
   * whether to render itemsBreakdown rows per guest.
   */
  mode?: "even" | "by_items";
  /** All settled rows in input order. */
  guests: Array<{
    /** 1-based seat number. */
    index: number;
    /** Server payment id — selection key for the print queue. */
    paymentId: string;
    /** Display name of the chosen method ("Tiền mặt", "Vietcombank"…). */
    methodName: string;
    /** Backend code (cash / card / transfer …) — picks the row icon. */
    methodCode: string;
    /** Amount this guest paid (per-row snapshot). */
    amount: number;
    /** HH:MM string captured at the moment the row succeeded. */
    paidAt: string;
    /**
     * Cash this guest handed over, and what they got back. Present only for
     * `requires_tendered` (cash) rows — a card row has neither. The receipt
     * screen prints both so the guest can check their change against paper.
     */
    tendered?: number;
    change?: number;
    /**
     * Plan-021 by-items mode — list of items this guest paid for.
     * Equal-mode rows leave this undefined.
     */
    itemsBreakdown?: Array<{ name: string; units: number; subtotal: number }>;
  }>;
  /** Original split count (number of guests configured). */
  guestCount: number;
  /** Per-guest amount from the snapshot. */
  perGuestAmount: number;
  /** Sum of guests[].amount. */
  totalAmount: number;
}

type RowStatus = "idle" | "submitting" | "succeeded" | "failed";

interface RowState {
  amount: number;
  methodId: string | null;
  status: RowStatus;
  errorMessage: string | null;
  /**
   * Idempotency key for this payment attempt. Generated on first Pay click,
   * preserved across retries (so backend dedups), regenerated when staff
   * picks a different method (genuinely new attempt).
   */
  idempotencyKey: string | null;
  /**
   * Payment id captured from the create response after a successful pay.
   * Used by the per-row refund button (C3) and per-row receipt print (C2).
   * Null until a payment lands.
   */
  paymentId: string | null;
  /**
   * HH:MM timestamp captured at the moment the row's payment lands.
   * Surfaced on the post-payment SplitBillReceiptDialog so staff can
   * see when each guest paid. Null until a payment succeeds.
   */
  paidAt: string | null;
  /**
   * Cash this guest handed over, as typed. `null` means untouched — the row
   * tenders its exact share. Only meaningful while the chosen method has
   * `requires_tendered`; reset whenever the method changes so a cash figure
   * can never ride along on a card row.
   */
  tenderRaw: string | null;
  /**
   * Snapshot of the tender/change actually posted, kept for the receipt
   * screen. Set alongside `paymentId`; undefined on non-cash rows.
   */
  tendered?: number;
  change?: number;
}

// ---------------------------------------------------------------------------
//  localStorage snapshot helpers (B1)
//
// Snapshot survives reload so staff doesn't have to re-explain amounts to
// customers mid-split. Invalidated when order.total_amount drifts since
// the saved value (paired with backend B2 drift detection).
// ---------------------------------------------------------------------------

const SNAPSHOT_VERSION = 1;
const snapshotKey = (orderId: string) => `pos:split-bill:${orderId}:v${SNAPSHOT_VERSION}`;

interface PersistedSnapshot {
  splitCount: number;
  perPersonAmounts: number[];
  totalAmount: number;
  createdAt: number;
}

function readSnapshot(orderId: string): PersistedSnapshot | null {
  if (typeof window === "undefined") return null;
  try {
    const raw = localStorage.getItem(snapshotKey(orderId));
    if (!raw) return null;
    const parsed = JSON.parse(raw) as Partial<PersistedSnapshot>;
    if (
      typeof parsed.splitCount !== "number" ||
      !Array.isArray(parsed.perPersonAmounts) ||
      typeof parsed.totalAmount !== "number"
    ) {
      return null;
    }
    return {
      splitCount: parsed.splitCount,
      perPersonAmounts: parsed.perPersonAmounts.map(Number),
      totalAmount: parsed.totalAmount,
      createdAt: parsed.createdAt ?? Date.now(),
    };
  } catch {
    return null;
  }
}

function writeSnapshot(orderId: string, snap: PersistedSnapshot): void {
  if (typeof window === "undefined") return;
  try {
    localStorage.setItem(snapshotKey(orderId), JSON.stringify(snap));
  } catch {
    // quota / private mode — silent. Snapshot persistence is best-effort.
  }
}

function clearSnapshot(orderId: string): void {
  if (typeof window === "undefined") return;
  try {
    localStorage.removeItem(snapshotKey(orderId));
  } catch {
    // ignore
  }
}

function newIdempotencyKey(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }
  // Fallback for environments without crypto.randomUUID — collision-resistant
  // enough for our scope (per-row, short-lived).
  return `key-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

function formatError(e: unknown): string {
  if (e instanceof ApiError) {
    return (e.body?.message as string | undefined) ?? `${e.status}: ${e.message}`;
  }
  if (e instanceof Error) return e.message;
  return "Unknown error";
}

export function SplitBillEvenTab({
  open,
  onOpenChange,
  order,
  methods,
  methodsLoading,
  splitData,
  splitLoading,
  splitError,
  splitCount,
  onChangeSplitCount,
  onCreatePayment,
  onRefundPayment,
  onCollectWithMachine,
  machineIdle = false,
  onPrintRowReceipt,
  onCancelSplit,
  onAllRowsPaid,
}: SplitBillEvenTabProps) {
  const { t, locale } = useTranslation();
  const [rows, setRows] = useState<RowState[] | null>(null);
  // Total amount the active snapshot was computed against. Used as the
  // `expected_total_amount` sent on each POST /payments so the backend
  // can detect drift (B2). Persisted alongside rows in localStorage (B1).
  const [snapshotTotalAmount, setSnapshotTotalAmount] = useState<number | null>(null);
  // Drift banner — set when the backend rejects a payment with code
  // `split_bill_total_drift`. Forces staff to acknowledge before continuing.
  const [driftError, setDriftError] = useState<{
    expected: number;
    actual: number;
  } | null>(null);
  // Synchronous in-flight lock per row index. setState updates are async, so
  // two near-simultaneous Pay clicks can both pass the `row.status` check
  // before either re-render lands. The ref blocks reentry inside the same
  // tick; the per-row idempotency_key handles retries that escape the UI
  // (genuine network glitches where the backend got the request anyway).
  const inFlightRef = useRef<Set<number>>(new Set());

  const orderId = order?.id ?? null;
  const orderTotalAmount = Number(order?.total_amount ?? 0);

  // Snapshot per_person_amounts on first load. Tries localStorage first
  // (B1) so reload-mid-split doesn't lose the per-person amounts staff
  // already announced. Falls back to splitData (calculator response).
  // Once a row has settled, the snapshot is locked — backend's shrinking
  // `remaining` would otherwise reshuffle row amounts.
  useEffect(() => {
    if (!open) {
      setRows(null);
      setSnapshotTotalAmount(null);
      setDriftError(null);
      return;
    }
    if (!orderId) return;

    // Try persisted snapshot first.
    const persisted = readSnapshot(orderId);
    const totalMatchesPersisted =
      persisted && Math.abs(persisted.totalAmount - orderTotalAmount) < 0.001;

    setRows((prev) => {
      // Once any row has been collected, freeze the snapshot — the
      // backend's shrinking `remaining` would otherwise reshuffle the
      // remaining row amounts and break the equal-share invariant staff
      // already announced to customers.
      if (prev?.some((r) => r.status === "succeeded")) return prev;

      // Persisted snapshot (B1) is for CROSS-SESSION recovery only —
      // first hydration after a tab refresh / dialog reopen. Within an
      // open session we must let `splitData` win so the splitCount
      // stepper actually re-shapes the rows. Without `prev === null`
      // here, the snapshot we just wrote (effect below, splitCount=N)
      // would re-hydrate the OLD N rows on every count change because
      // `totalMatchesPersisted` is true while the order total is stable.
      if (prev === null && persisted && totalMatchesPersisted) {
        return persisted.perPersonAmounts.map((a) => ({
          amount: Number(a),
          methodId: null,
          status: "idle" as RowStatus,
          errorMessage: null,
          idempotencyKey: null,
          paymentId: null,
          paidAt: null,
          tenderRaw: null,
        }));
      }

      if (!splitData) return prev ?? null;

      // Defensive: a cached / stale workstation response (or a future
      // schema drift) may arrive without `per_person_amounts`. Without
      // this guard the lazy `.map(...)` crashes the whole tab with a
      // useless "Cannot read properties of undefined (reading 'map')"
      // overlay — the user-reported regression after the LAN split-bill
      // handler was rewritten to match Cloud's decimal-string shape.
      // Treating an invalid shape as "not loaded yet" keeps the loading
      // skeleton up; the next refetch with a fresh response replaces
      // the state cleanly.
      if (!Array.isArray(splitData.per_person_amounts)) {
        if (import.meta.env.DEV) {
          // eslint-disable-next-line no-console
          console.warn(
            "[split-bill] response missing per_person_amounts — got shape:",
            splitData,
          );
        }
        return prev ?? null;
      }

      return splitData.per_person_amounts.map((a) => ({
        amount: Number(a),
        methodId: null,
        status: "idle" as RowStatus,
        errorMessage: null,
        idempotencyKey: null,
        paymentId: null,
        paidAt: null,
        tenderRaw: null,
      }));
    });

    // Snapshot total — anchored ONCE on first hydration (rows null →
    // not-null) and never overwritten afterwards. Drift detection (B2)
    // compares this against `order.total_amount` so it must stay
    // pinned to the moment the snapshot was created. Re-setting on
    // every splitData refetch would erase legitimate drift.
    setSnapshotTotalAmount((prev) => {
      if (prev !== null) return prev;
      if (persisted && totalMatchesPersisted) return persisted.totalAmount;
      if (splitData) return orderTotalAmount;
      return null;
    });
  }, [open, orderId, orderTotalAmount, splitData]);

  // Persist snapshot to localStorage so reload-mid-split is recoverable.
  // Only writes a "useful" snapshot — skip the initial null state and
  // any state without a valid total to anchor drift detection.
  useEffect(() => {
    if (!open || !orderId || !rows || rows.length === 0) return;
    if (snapshotTotalAmount === null) return;
    writeSnapshot(orderId, {
      splitCount: rows.length,
      perPersonAmounts: rows.map((r) => r.amount),
      totalAmount: snapshotTotalAmount,
      createdAt: Date.now(),
    });
  }, [open, orderId, rows, snapshotTotalAmount]);

  // Cross-session recovery: when we re-hydrate from a persisted snapshot
  // whose `splitCount` differs from the parent's current value (e.g.
  // staff bumped to 4 last shift, page refreshed, parent state reset to
  // 2), push the persisted count up so the stepper, the useSplitBill
  // query, and the rows all agree. Without this sync, splitData
  // refetched at the parent's stale count would clobber the restored
  // rows on the very next render.
  useEffect(() => {
    if (!open || !orderId) return;
    const persisted = readSnapshot(orderId);
    if (!persisted) return;
    if (Math.abs(persisted.totalAmount - orderTotalAmount) >= 0.001) return;
    if (persisted.splitCount === splitCount) return;
    onChangeSplitCount(persisted.splitCount);
    // Intentionally narrow deps: this should fire on (re)open only.
    // Adding splitCount/onChangeSplitCount would re-fire after the sync
    // call and risks an oscillation if the parent rejects the change.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, orderId]);

  const anySettled = !!rows?.some((r) => r.status === "succeeded");
  const allSettled = !!rows && rows.every((r) => r.status === "succeeded");
  const anySubmitting = !!rows?.some((r) => r.status === "submitting");

  // C1 progress: how many rows are settled, and how much that adds up to.
  const paidRowsCount = useMemo(
    () => (rows ?? []).filter((r) => r.status === "succeeded").length,
    [rows]
  );
  const paidRowsAmount = useMemo(
    () => (rows ?? []).filter((r) => r.status === "succeeded").reduce((s, r) => s + r.amount, 0),
    [rows]
  );
  const progressPct = rows && rows.length > 0 ? (paidRowsCount / rows.length) * 100 : 0;

  // Clear persisted snapshot once the whole order has been settled —
  // staying around would only confuse the next split on a different
  // order with the same id (id reuse impossible, but the leftover row
  // would re-hydrate stale state on accidental reopen).
  useEffect(() => {
    if (allSettled && orderId) {
      clearSnapshot(orderId);
    }
  }, [allSettled, orderId]);

  function setRow(index: number, patch: Partial<RowState>) {
    setRows((prev) => (prev ? prev.map((r, i) => (i === index ? { ...r, ...patch } : r)) : prev));
  }

  /**
   * Per-row amount edit. When staff manually sets row N's amount, the
   * remaining (order total − this row − any already-paid rows) is
   * redistributed equally across the OTHER unpaid rows. So adjusting
   * guest #1 to 200k automatically reshapes guests #2..#N to share the
   * rest. Rounding remainder lands on the LAST redistributed row so
   * Σ rows == order total exactly.
   *
   * Paid/submitting rows are locked (their amount can't change once it
   * went to the backend). The idempotency key is reset on every touched
   * row since its payload changed — backend treats it as a new attempt.
   */
  function handleAmountChange(index: number, raw: string) {
    const parsed = parseFloat(raw);
    const newAmount = Number.isFinite(parsed) && parsed >= 0 ? parsed : 0;

    setRows((prev) => {
      if (!prev) return prev;
      const target = prev[index];
      if (!target) return prev;
      if (target.status === "succeeded" || target.status === "submitting") return prev;

      // Currency-aware equal-split redistribution (pure, unit-tested in
      // ../lib/equal-split). Each unpaid share snaps to the currency minor
      // unit and the last row absorbs the rounding remainder so Σ shares
      // equals the remaining balance exactly. VND behaviour is unchanged.
      const amounts = redistributeEqualSplit({
        rows: prev.map((r) => ({
          amount: Number(r.amount || 0),
          locked: r.status === "succeeded" || r.status === "submitting",
        })),
        index,
        newAmount,
        orderTotal: orderTotalAmount,
        currency: getActiveCurrency(),
      });

      return prev.map((r, i) => {
        // Locked rows (paid / mid-submit) are frozen — keep them untouched.
        if (r.status === "succeeded" || r.status === "submitting") return r;
        // Edited + redistributed rows: apply the new amount and reset the
        // idempotency key (payload changed → backend treats it as a new
        // attempt) and any stale error message.
        return {
          ...r,
          amount: amounts[i]!,
          idempotencyKey: null,
          errorMessage: null,
        };
      });
    });
  }

  /**
   * Đánh dấu MỘT hàng đã trả, rồi bắn `onAllRowsPaid` nếu đó là hàng cuối.
   *
   * Dùng chung cho cả hai đường thu (`onCreatePayment` và máy 釣銭機) — chúng
   * khác nhau ở chỗ TIỀN đi thế nào, không khác ở chỗ màn hình kể lại thế nào,
   * và hai bản sao của đoạn này sẽ lệch đúng ở ca "hàng cuối" hiếm gặp.
   */
  function settleRow(index: number, paymentId: string, tendered?: number, change?: number) {
    if (!rows) return;
    const paidAt = formatTime(new Date(), locale, { withSeconds: false });
    setRow(index, {
      status: "succeeded",
      errorMessage: null,
      paymentId,
      paidAt,
      tendered,
      change,
    });

    // Detect "this was the last unpaid row" — if so, build the receipt
    // snapshot from the LOCAL rows array (the React setState above is
    // async; we can't trust `rows` to reflect the new value yet) and
    // hand it to the parent. Parent opens SplitBillReceiptDialog.
    if (onAllRowsPaid) {
      const projected = rows.map((r, i) =>
        i === index
          ? { ...r, status: "succeeded" as RowStatus, paymentId, paidAt, tendered, change }
          : r
      );
      const allDone = projected.every((r) => r.status === "succeeded");
      if (allDone) {
        const guests = projected.map((r, i) => {
          const m = methods.find((mm) => mm.id === r.methodId);
          return {
            index: i + 1,
            paymentId: r.paymentId ?? "",
            methodName: m?.name ?? "",
            methodCode: m?.code ?? "",
            amount: r.amount,
            paidAt: r.paidAt ?? "",
            tendered: r.tendered,
            change: r.change,
          };
        });
        onAllRowsPaid({
          guests,
          guestCount: projected.length,
          perGuestAmount: projected[0]?.amount ?? 0,
          totalAmount: projected.reduce((sum, r) => sum + r.amount, 0),
        });
      }
    }
  }

  /**
   * Thu phần của hàng này bằng máy 釣銭機 (#2946).
   *
   * ĐỌC KỸ TRƯỚC KHI SỬA: hàm này **cố ý không gọi `onCreatePayment`**. Khi máy
   * báo `finish`, máy trạm ĐÃ chèn payment (idempotent theo mã giao dịch
   * Glory), đã chạy lifecycle và đã xếp hàng sync UP — nó là người ghi duy
   * nhất của luồng này. Thêm một POST ở đây là **thu tiền khách hai lần**, và
   * tiền mặt đã vào máy thì không rollback được.
   */
  async function handleCollectRowWithMachine(index: number) {
    if (!rows || !onCollectWithMachine) return;
    if (inFlightRef.current.has(index)) return;
    const row = rows[index];
    if (!row) return;
    if (row.status === "succeeded" || row.status === "submitting") return;
    // Máy đếm tiền mặt. Một hàng thẻ đi qua đây là vô nghĩa, và nút cũng không
    // hiện ra cho nó — đây là dây an toàn cho cái khoá đó.
    const method = methods.find((m) => m.id === row.methodId);
    if (!method?.requires_tendered) return;
    if (row.amount <= 0) return;

    inFlightRef.current.add(index);
    setRow(index, { status: "submitting", errorMessage: null });

    try {
      const collected = await onCollectWithMachine(
        row.amount,
        evenSplitMetadata(index, rows.length)
      );
      if (!collected) {
        // Bao gồm ca nặng nhất: máy ĐÃ nhận tiền mà ghi sổ hỏng
        // (`cashCollectedButNotRecorded`). Đánh dấu hàng này là đã trả sẽ nói
        // dối về một khoản chưa có trong sổ — để nó `failed` và bắt người nhìn.
        setRow(index, {
          status: "failed",
          errorMessage: t("pos.dialog.split_bill.machine_not_settled"),
        });
        return;
      }
      // Số tiền khách đưa / tiền thối do MÁY đếm, không phải pos-web tính.
      settleRow(index, collected.id, collected.tendered, collected.change);
    } catch (e) {
      setRow(index, { status: "failed", errorMessage: formatError(e) });
    } finally {
      inFlightRef.current.delete(index);
    }
  }

  async function handlePayRow(index: number) {
    if (!rows) return;
    // Synchronous reentry guard — a second click that lands before
    // setState propagates would otherwise read the stale "idle" status
    // and fire a duplicate POST.
    if (inFlightRef.current.has(index)) return;
    const row = rows[index];
    if (!row) return;
    if (row.status === "succeeded" || row.status === "submitting") return;
    const method = methods.find((m) => m.id === row.methodId);
    if (!method) return;

    // Cash rows carry what the guest actually handed over. An invalid tender
    // (below the share, or unparseable) is a guaranteed server 422 — the Thu
    // button is already disabled for it, so this is the belt to that braces.
    const tender = computeCashTender(row.tenderRaw, row.amount);
    if (method.requires_tendered && !tender.valid) return;
    const tenderedAmount = method.requires_tendered
      ? (tender.tendered ?? row.amount)
      : undefined;
    const changeAmount = method.requires_tendered ? tender.change : undefined;

    inFlightRef.current.add(index);

    // Reuse the existing key on retry; mint a fresh one on the very first
    // attempt for this row+method pairing. The key flows through to the
    // backend so a retry after a network glitch returns the original
    // payment instead of inserting a duplicate row.
    const idempotencyKey = row.idempotencyKey ?? newIdempotencyKey();

    setRow(index, {
      status: "submitting",
      errorMessage: null,
      idempotencyKey,
    });

    try {
      const created = await onCreatePayment({
        payment_method_id: method.id,
        amount: row.amount,
        tendered_amount: tenderedAmount,
        idempotency_key: idempotencyKey,
        expected_total_amount: snapshotTotalAmount ?? undefined,
        // Plan-021 T3.7 — equal-mode audit metadata. The dialog knows the
        // bill index from the row position (0-based) and the total split
        // count from the rows array length.
        metadata: evenSplitMetadata(index, rows.length),
      });
      settleRow(index, created.id, tenderedAmount, changeAmount);
    } catch (e) {
      // B2: server-side drift detection. Backend embeds a structured code
      // in the 422 body so the dialog can surface a recoverable warning
      // instead of the generic "exceeds outstanding balance" error.
      if (e instanceof ApiError && e.body?.code === "split_bill_total_drift") {
        const expected = Number(e.body.expected_total_amount ?? 0);
        const actual = Number(e.body.actual_total_amount ?? 0);
        setDriftError({ expected, actual });
        setRow(index, { status: "failed", errorMessage: null });
      } else {
        setRow(index, { status: "failed", errorMessage: formatError(e) });
      }
    } finally {
      inFlightRef.current.delete(index);
    }
  }

  /** C3: refund a previously settled row, re-opening it for re-collection. */
  async function handleRefundRow(index: number) {
    if (!rows) return;
    const row = rows[index];
    if (!row || row.status !== "succeeded" || !row.paymentId) return;
    if (inFlightRef.current.has(index)) return;
    inFlightRef.current.add(index);
    setRow(index, { status: "submitting", errorMessage: null });
    try {
      await onRefundPayment(row.paymentId);
      // Reset to idle so staff can re-collect from the right person /
      // method. Mint a fresh idempotency_key on the next Pay attempt.
      setRow(index, {
        status: "idle",
        errorMessage: null,
        paymentId: null,
        idempotencyKey: null,
        paidAt: null,
        tenderRaw: null,
        tendered: undefined,
        change: undefined,
      });
    } catch (e) {
      // #2668 — the workstation still holds an open cashier shift, so Cloud
      // refuses the refund (409, frozen code). The cashier never chose Cloud:
      // apiFetch's auto-mode net retried a LAN network error against it. The
      // raw envelope message would read as an unexplained server error, so
      // say what actually has to happen instead.
      setRow(index, {
        status: "succeeded",
        errorMessage: isRefundBlockedByWorkstationShift(e)
          ? t("pos.refund.blocked_workstation_open_shift")
          : formatError(e),
      });
    } finally {
      inFlightRef.current.delete(index);
    }
  }

  /** C2: print receipt for a single succeeded row via workstation-app. */
  async function handlePrintRow(index: number) {
    if (!rows || !onPrintRowReceipt) return;
    const row = rows[index];
    if (!row || row.status !== "succeeded" || !row.paymentId) return;
    try {
      await onPrintRowReceipt(row.paymentId);
    } catch (e) {
      setRow(index, { errorMessage: formatError(e) });
    }
  }

  /** B2: drift recovery — clear snapshot and recompute from server. */
  function handleRecalculateAfterDrift() {
    if (!orderId) return;
    clearSnapshot(orderId);
    setRows(null);
    setSnapshotTotalAmount(null);
    setDriftError(null);
    // The next render's useEffect will re-snapshot from the freshest
    // splitData (which the parent's React Query refetched after the
    // 422). If splitData is stale, the parent must invalidate.
  }

  /** C4: switch from split-bill back to a single PaymentDialog. */
  function handleCancelSplit() {
    if (!orderId) return;
    if (anySettled) return; // guard at handler level too
    clearSnapshot(orderId);
    setRows(null);
    setSnapshotTotalAmount(null);
    onCancelSplit();
  }

  function handleSplitCountChange(next: number) {
    if (anySettled) return; // locked after first payment
    if (next < 2) return;
    if (next > 50) return; // sanity cap — UI gets unusable past this anyway
    onChangeSplitCount(next);
  }

  const orderRemaining = Number(order?.remaining_amount ?? 0);

  // Per-person amount surfaced in the hero stat. We read it from the first
  // row so it stays consistent with what staff actually announced — the
  // backend's rounding might split into N-1 equal + 1 leftover, so this is
  // a best-effort "headline" rate, not the exact value for everyone.
  const perPersonHero = rows?.[0]?.amount ?? 0;

  const remainingPeople = rows ? rows.length - paidRowsCount : 0;

  // Sum of all per-row amounts (paid + unpaid). When staff manually edits
  // amounts, this lets them see whether the split still adds up to the
  // order total. Tolerate small rounding noise (< 1đ).
  const sumOfRows = (rows ?? []).reduce((s, r) => s + Number(r.amount || 0), 0);
  const splitDiff = sumOfRows - orderTotalAmount;
  const splitMatches = Math.abs(splitDiff) < 1;

  return (
    <>
      <div className="flex-1 overflow-y-auto px-6 pt-2 pb-4">
        <div className="mx-auto max-w-2xl space-y-4">
          {/* === Hero header — two side-by-side cards === */}
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-5">
            {/* Per-person hero */}
            <div className="bg-primary/5 border-primary/20 sm:col-span-3 flex flex-col justify-center rounded-2xl border-2 px-5 py-4">
              <div className="text-primary text-[11px] font-semibold tracking-wider uppercase">
                {t("pos.dialog.split_bill.per_person")}
              </div>
              <div className="text-foreground mt-1 truncate text-3xl leading-none font-bold tabular-nums">
                {rows ? formatCurrency(perPersonHero) : "—"}
              </div>
              <div className="text-muted-foreground mt-1.5 text-xs">
                {t("pos.dialog.split_bill.split_among", {
                  count: rows?.length ?? splitCount,
                })}
              </div>
            </div>

            {/* Count stepper */}
            <div className="bg-muted/40 sm:col-span-2 flex flex-col items-start justify-center rounded-2xl border px-5 py-4">
              <Label
                htmlFor="split-count"
                className="text-muted-foreground text-[11px] font-semibold tracking-wider uppercase"
              >
                {t("pos.dialog.split_bill.count_label")}
              </Label>
              <div className="mt-1.5 inline-flex items-center gap-1.5">
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  className="size-9 rounded-md"
                  disabled={splitCount <= 2 || anySettled || anySubmitting}
                  onClick={() => handleSplitCountChange(splitCount - 1)}
                  aria-label={t("common.decrease")}
                >
                  <MinusIcon className="size-3.5" />
                </Button>
                <Input
                  id="split-count"
                  type="number"
                  min={2}
                  max={50}
                  value={String(splitCount)}
                  onChange={(e) => {
                    const n = parseInt(e.target.value, 10);
                    if (!Number.isNaN(n)) handleSplitCountChange(n);
                  }}
                  disabled={anySettled || anySubmitting}
                  className="bg-background h-9 w-12 [appearance:textfield] text-center text-lg font-bold tabular-nums shadow-none [&::-webkit-inner-spin-button]:m-0 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:m-0 [&::-webkit-outer-spin-button]:appearance-none"
                />
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  className="size-9 rounded-md"
                  disabled={splitCount >= 50 || anySettled || anySubmitting}
                  onClick={() => handleSplitCountChange(splitCount + 1)}
                  aria-label={t("common.increase")}
                >
                  <PlusIcon className="size-3.5" />
                </Button>
              </div>
            </div>
          </div>

          {/* === Inline notices === */}
          {anySettled && !allSettled && (
            <div className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100">
              {t("pos.dialog.split_bill.locked_after_payment")}
            </div>
          )}

          {driftError && (
            <Alert variant="destructive">
              <AlertCircleIcon className="size-4" />
              <AlertDescription className="space-y-2">
                <div>
                  {t("pos.dialog.split_bill.drift_warning", {
                    expected: formatCurrency(driftError.expected),
                    actual: formatCurrency(driftError.actual),
                  })}
                </div>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={handleRecalculateAfterDrift}
                >
                  {t("pos.dialog.split_bill.drift_recalculate")}
                </Button>
              </AlertDescription>
            </Alert>
          )}

          {splitError && (
            <Alert variant="destructive">
              <AlertCircleIcon className="size-4" />
              <AlertDescription>{splitError}</AlertDescription>
            </Alert>
          )}

          {splitData?.rounding_note && (
            <div className="bg-muted/40 text-muted-foreground rounded-lg border px-3 py-2 text-xs">
              {splitData.rounding_note}
            </div>
          )}

          {/* === Guest rows === */}
          {splitLoading || methodsLoading || !rows ? (
            <div className="space-y-2">
              <Skeleton className="h-28 w-full rounded-2xl" />
              <Skeleton className="h-28 w-full rounded-2xl" />
              <Skeleton className="h-28 w-full rounded-2xl" />
            </div>
          ) : (
            <ul className="space-y-2.5">
              {rows.map((row, i) => {
                const succeeded = row.status === "succeeded";
                const submitting = row.status === "submitting";
                const failed = row.status === "failed";
                const selectedMethod = methods.find((m) => m.id === row.methodId);
                const needsTender = selectedMethod?.requires_tendered ?? false;
                const tender = computeCashTender(row.tenderRaw, row.amount);
                // A short / unparseable tender can never be collected: both the
                // workstation and Cloud refuse `tendered < amount + tip`.
                const canPay =
                  !succeeded &&
                  !submitting &&
                  !!row.methodId &&
                  row.amount > 0 &&
                  (!needsTender || tender.valid);
                return (
                  <li
                    key={i}
                    className={cn(
                      "rounded-2xl border-2 p-3 transition-all",
                      succeeded
                        ? "border-emerald-300 bg-emerald-50/60 dark:border-emerald-500/40 dark:bg-emerald-500/10"
                        : failed
                          ? "border-red-300 bg-red-50/60 dark:border-red-500/40 dark:bg-red-500/10"
                          : submitting
                            ? "border-primary bg-primary/5 ring-primary/20 ring-4"
                            : "border-border/60 bg-background"
                    )}
                  >
                    {/* Header: avatar · label (left) · amount input (right) */}
                    <div className="flex items-center gap-3">
                      <span
                        className={cn(
                          "inline-flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-bold tabular-nums shadow-sm",
                          succeeded
                            ? "bg-emerald-500 text-white"
                            : failed
                              ? "bg-red-500 text-white"
                              : "bg-primary/15 text-primary"
                        )}
                      >
                        {succeeded ? (
                          <CheckCircle2Icon className="size-5" />
                        ) : failed ? (
                          <XCircleIcon className="size-5" />
                        ) : (
                          i + 1
                        )}
                      </span>

                      <div className="text-foreground min-w-0 flex-1 truncate text-sm font-semibold">
                        {t("pos.dialog.split_bill.person_label", { n: i + 1 })}
                      </div>

                      {succeeded ? (
                        <span className="text-emerald-700 dark:text-emerald-300 shrink-0 text-lg font-bold tabular-nums">
                          {formatCurrency(row.amount)}
                        </span>
                      ) : (
                        <div className="flex shrink-0 items-center gap-1">
                          <Input
                            type="number"
                            min={0}
                            step="1000"
                            value={String(row.amount)}
                            onChange={(e) => handleAmountChange(i, e.target.value)}
                            onFocus={(e) => e.target.select()}
                            disabled={submitting || anySubmitting}
                            className={cn(
                              "bg-background h-10 w-36 rounded-lg text-right text-lg font-bold tabular-nums",
                              "[appearance:textfield] [&::-webkit-inner-spin-button]:m-0 [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:m-0 [&::-webkit-outer-spin-button]:appearance-none"
                            )}
                            aria-label={t("pos.dialog.split_bill.row_amount_label", {
                              n: i + 1,
                            })}
                          />
                          <span className="text-muted-foreground text-sm font-semibold">
                            {getCurrencySymbol()}
                          </span>
                        </div>
                      )}
                    </div>

                    {/* Footer: method picker + Thu button (or refund/print actions) */}
                    {succeeded && row.paymentId ? (
                      <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-emerald-200/60 pt-2.5 dark:border-emerald-500/30">
                        <Badge
                          variant="secondary"
                          className="h-6 gap-1 bg-emerald-100 px-2 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200"
                        >
                          <CheckCircle2Icon className="size-3" />
                          {t("pos.dialog.split_bill.status_paid")}
                          {selectedMethod && (
                            <span className="text-emerald-700/70 dark:text-emerald-200/70">
                              · {selectedMethod.name}
                            </span>
                          )}
                          {row.paidAt && (
                            <span className="text-emerald-700/70 dark:text-emerald-200/70">
                              · {row.paidAt}
                            </span>
                          )}
                        </Badge>
                        {row.tendered !== undefined && (
                          // Stays on screen after the row is collected: the
                          // cashier still has to count this change out, and
                          // the input that showed it is gone by now.
                          <span className="text-muted-foreground w-full text-[11px] tabular-nums">
                            {t("pos.split_bill.cash.tendered")}{" "}
                            <span className="text-foreground font-semibold">
                              {formatCurrency(row.tendered)}
                            </span>
                            <span className="text-muted-foreground/50 mx-1.5">·</span>
                            {t("pos.split_bill.cash.change")}{" "}
                            <span className="font-semibold text-emerald-700 dark:text-emerald-300">
                              {formatCurrency(row.change ?? 0)}
                            </span>
                          </span>
                        )}
                        <div className="flex items-center gap-1">
                          {onPrintRowReceipt && (
                            <Button
                              type="button"
                              size="sm"
                              variant="ghost"
                              className="h-8 gap-1.5 px-2 text-xs"
                              onClick={() => handlePrintRow(i)}
                            >
                              <PrinterIcon className="size-3.5" />
                              {t("pos.dialog.split_bill.print_row")}
                            </Button>
                          )}
                          <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            className="h-8 gap-1.5 px-2 text-xs text-amber-700 hover:bg-amber-100 hover:text-amber-900 dark:text-amber-300 dark:hover:bg-amber-500/20"
                            onClick={() => handleRefundRow(i)}
                            disabled={anySubmitting}
                          >
                            <Undo2Icon className="size-3.5" />
                            {t("pos.dialog.split_bill.refund_row")}
                          </Button>
                        </div>
                      </div>
                    ) : (
                      <div className="mt-3 flex items-center gap-2">
                        <Select
                          value={row.methodId ?? undefined}
                          onValueChange={(v) =>
                            setRow(i, {
                              methodId: v,
                              errorMessage: null,
                              idempotencyKey: null,
                              // A tender typed for one method must not ride
                              // onto another (card rows record none at all).
                              tenderRaw: null,
                            })
                          }
                          disabled={submitting}
                        >
                          <SelectTrigger className="bg-background h-10 flex-1 rounded-lg">
                            <SelectValue
                              placeholder={t("pos.dialog.split_bill.method_placeholder")}
                            />
                          </SelectTrigger>
                          <SelectContent>
                            {methods
                              .filter((m) => m.is_active)
                              .map((m) => {
                                const Icon = iconFor(m.code);
                                return (
                                  <SelectItem key={m.id} value={m.id}>
                                    <span className="flex items-center gap-2">
                                      <Icon className="text-muted-foreground size-4 shrink-0" />
                                      <span>{m.name}</span>
                                    </span>
                                  </SelectItem>
                                );
                              })}
                          </SelectContent>
                        </Select>
                        {/* Thu bằng máy 釣銭機 — chỉ hàng TIỀN MẶT, và chỉ khi
                            máy rảnh. Máy chỉ có MỘT nên `machineIdle` tắt mọi
                            nút còn lại trong lúc một lượt thu đang chạy, thay
                            vì để máy trả 409 rồi hiện lỗi cho thu ngân. */}
                        {onCollectWithMachine && needsTender && (
                          <Button
                            type="button"
                            variant="outline"
                            className="h-10 shrink-0 rounded-lg px-3 text-sm font-semibold"
                            disabled={!machineIdle || succeeded || submitting || row.amount <= 0}
                            onClick={() => handleCollectRowWithMachine(i)}
                          >
                            <BanknoteIcon className="size-4" />
                            {t("pos.dialog.split_bill.collect_with_machine")}
                          </Button>
                        )}
                        <Button
                          type="button"
                          className="h-10 shrink-0 rounded-lg px-6 text-sm font-semibold shadow-sm"
                          disabled={!canPay}
                          onClick={() => handlePayRow(i)}
                        >
                          {submitting ? <Spinner /> : t("pos.dialog.split_bill.pay_row")}
                        </Button>
                      </div>
                    )}

                    {/* Cash handed over + change, for this guest only. Shown
                        only once a `requires_tendered` method is picked. */}
                    {!succeeded && needsTender && (
                      <CashTenderField
                        className="mt-2"
                        amount={row.amount}
                        value={row.tenderRaw}
                        onChange={(next) => setRow(i, { tenderRaw: next })}
                        disabled={submitting}
                        guestIndex={i + 1}
                      />
                    )}

                    {row.errorMessage && (
                      <div className="mt-2 flex items-center gap-1.5 text-xs text-red-700 dark:text-red-300">
                        <AlertCircleIcon className="size-3.5 shrink-0" />
                        <span>{row.errorMessage}</span>
                      </div>
                    )}
                  </li>
                );
              })}
            </ul>
          )}

          {/* === Bottom summary card === */}
          {rows && rows.length > 0 && (
            <div className="bg-muted/30 space-y-2 rounded-2xl border p-4">
              {!splitMatches && (
                <div
                  className={cn(
                    "flex items-center justify-between rounded-lg border px-3 py-2 text-xs font-medium",
                    splitDiff > 0
                      ? "border-red-300 bg-red-50 text-red-900 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-100"
                      : "border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100"
                  )}
                >
                  <span className="flex items-center gap-1.5">
                    <AlertCircleIcon className="size-3.5 shrink-0" />
                    {t("pos.dialog.split_bill.split_diff_label")}
                  </span>
                  <span className="font-semibold tabular-nums">
                    {splitDiff > 0 ? "+" : ""}
                    {formatCurrency(splitDiff)}
                  </span>
                </div>
              )}

              <div className="text-sm">
                <div className="flex items-baseline justify-between">
                  <span className="text-muted-foreground">
                    {t("pos.dialog.split_bill.split_total_label")}
                  </span>
                  <div className="text-right">
                    <span
                      className={cn(
                        "text-foreground text-base font-bold tabular-nums",
                        splitMatches && "text-emerald-600 dark:text-emerald-400"
                      )}
                    >
                      {formatCurrency(sumOfRows)}
                    </span>
                    <span className="text-muted-foreground ml-1.5 text-xs tabular-nums">
                      / {formatCurrency(orderTotalAmount)}
                    </span>
                  </div>
                </div>
              </div>

              <Progress value={progressPct} className="h-1.5" />

              <div className="text-sm">
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">
                    {t("pos.dialog.split_bill.progress_count", {
                      paid: paidRowsCount,
                      total: rows.length,
                    })}
                  </span>
                  <span className="font-semibold text-emerald-600 tabular-nums dark:text-emerald-400">
                    {formatCurrency(paidRowsAmount)}
                  </span>
                </div>
                <div className="mt-1 flex items-center justify-between">
                  <span className="text-muted-foreground">
                    {t("pos.dialog.split_bill.remaining_summary", {
                      count: remainingPeople,
                    })}
                  </span>
                  <span
                    className={cn(
                      "text-foreground text-base font-bold tabular-nums",
                      orderRemaining <= 0 && "text-emerald-600 dark:text-emerald-400"
                    )}
                  >
                    {formatCurrency(orderRemaining)}
                  </span>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>

      <DialogFooter className="bg-muted/20 grid shrink-0 grid-cols-2 gap-2 border-t px-6 py-3">
        {/* "Gộp lại" — primary action on the left to match the screenshot.
              Bails out of split-bill back to the regular PaymentDialog;
              only allowed while no row has been settled (once cash has
              been collected, we can't "uncollect" it without a refund). */}
        <Button
          onClick={handleCancelSplit}
          disabled={anySettled || anySubmitting || !rows}
          className="h-11 rounded-lg text-base font-semibold"
        >
          <MergeIcon className="size-4" />
          {t("pos.dialog.split_bill.cancel_split")}
        </Button>
        <Button
          variant="outline"
          onClick={() => onOpenChange(false)}
          disabled={anySubmitting}
          className="h-11 rounded-lg"
        >
          {allSettled ? t("common.close") : t("common.cancel")}
        </Button>
      </DialogFooter>
    </>
  );
}
