/**
 * SplitBillByItemsTab — plan-021 chia-theo-món tab.
 *
 * Single-phase UI (allocate + pay merged):
 *   LEFT  : "SỐ ĐẦU NGƯỜI" (read-only, from order.guest_count) +
 *           "SỐ NGƯỜI CHIA" stepper. Vertical stack of PersonPaymentCards
 *           — each card carries amount + items count + method `<Select>` +
 *           "Thu" button. Active card (where palette clicks land) has a
 *           blue ring. Footer shows "Đã thu (P/T người)" + "Còn lại".
 *   RIGHT : Header "Chọn món cho khách hàng #{N}" + scrollable item list.
 *           Each card: thumbnail · name · toppings · note · price · +/-
 *           counter showing the units assigned to the active person.
 *
 * Tapping Thu fires `onCreatePayment` with by-items metadata; the row
 * tracks idle / submitting / succeeded / failed locally. When the last
 * non-empty bill flips to succeeded the tab synthesises a
 * `SplitBillSessionResult` and calls `onAllRowsPaid` so the parent opens
 * `SplitBillReceiptDialog` exactly like the equal-mode flow.
 */

import { useEffect, useMemo, useRef, useState } from "react";
import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  DialogFooter,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@godxjp/ui";
import {
  BanknoteIcon,
  CheckCircle2Icon,
  MinusIcon,
  PlusIcon,
  XCircleIcon,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { ApiError } from "@/lib/api";
import { formatTime } from "@/lib/format-date";
import { useTranslation } from "@/providers/app-provider";
import { iconFor } from "../lib/payment-method-icon";
import type {
  CustomerOrder,
  CustomerOrderItem,
  ItemAllocation,
  PaymentMethod,
  PersonBill,
  SplitByItemsState,
} from "../types";
import { formatCurrency } from "../lib/totals";
import { perUnitPrice } from "../lib/line-total";
import { areAllByItemsBillsSettled, splitByItems } from "../lib/split-by-items";
import { collapseMirroredToppingName } from "../lib/topping-name";
import { orderItemDisplayName } from "../lib/order-item-name";
import { ItemThumb } from "./item-thumb";
import { CashTenderField } from "./cash-tender-field";
import { computeCashTender } from "../lib/cash-tender";
import { Spinner } from "@/components/ui/spinner";
import type { SplitBillSessionResult, SplitPaymentBody } from "./split-bill-even-tab";
import type { CashChangerSplitMetadata } from "@/services/workstation-cash-changer-service";

function byItemsSplitMetadata(
  bill: PersonBill,
  totalBills: number
): Extract<CashChangerSplitMetadata, { split_mode: "by_items" }> {
  return {
    split_mode: "by_items",
    bill_index: bill.index,
    total_bills: totalBills,
    label: bill.label,
    item_allocations: bill.itemsBreakdown.map((entry) => ({
      item_id: entry.item.id,
      units: entry.units,
    })),
  };
}

export interface SplitBillByItemsTabProps {
  order: CustomerOrder | null;
  /** Percent — e.g. 8 means 8 %. */
  taxRate: number;
  /** Percent — e.g. 5 means 5 %. */
  serviceRate: number;
  methods: PaymentMethod[];
  methodsLoading: boolean;
  /**
   * Same `onCreatePayment` the equal tab uses — the parent fires
   * `POST /shops/{shop}/orders/{order}/payments` and returns the created
   * payment id. By-items mode passes the bill's audit metadata in the
   * body so the row in `order_payments.metadata` carries
   * `{split_mode:'by_items', bill_index, total_bills, label, item_allocations}`.
   */
  onCreatePayment: (body: SplitPaymentBody) => Promise<{ id: string }>;
  /**
   * Fired when the last non-empty bill flips to `succeeded`. Parent
   * captures the snapshot and opens `SplitBillReceiptDialog`.
   */
  onAllRowsPaid?: (data: SplitBillSessionResult) => void;
  /**
   * Thu phần của MỘT hàng bằng máy 釣銭機 (#2958, khuôn từ #2946).
   *
   * Trả về payment do MÁY TRẠM tạo, hoặc `null` khi tiền không vào được sổ.
   * Hàng đi đường này **không bao giờ** chạm `onCreatePayment` — máy trạm là
   * người ghi duy nhất của luồng 釣銭機, nên một POST thứ hai ở đây là **thu
   * tiền khách hai lần**.
   *
   * Vắng prop ⇒ không nút nào hiện ra và tab chạy y như trước.
   */
  onCollectWithMachine?: (
    amount: number,
    metadata: CashChangerSplitMetadata
  ) => Promise<{ id: string; tendered?: number; change?: number } | null>;
  /** Máy có mặt và rảnh. Máy chỉ có MỘT nên một lượt thu khoá mọi hàng khác. */
  machineIdle?: boolean;
  onClose: () => void;
}

/** Min/max people guard — UI sanity, no business constraint. */
const MIN_PEOPLE = 1;
const MAX_PEOPLE = 20;

type PaymentRowStatus = "idle" | "submitting" | "succeeded" | "failed";

/**
 * Per-bill payment row state. Voided / refund tracking is intentionally
 * omitted; by-items mode is fire-and-forget and the staff-visible refund
 * flow lives in OrderPayment admin tooling.
 */
interface PaymentRowState {
  methodId: string | null;
  status: PaymentRowStatus;
  errorMessage: string | null;
  paymentId: string | null;
  paidAt: string | null;
  /** Stable per-attempt UUID — same retry-safety guarantee as equal mode. */
  idempotencyKey: string | null;
  /**
   * Cash this guest handed over, as typed. `null` = untouched → the bill's
   * exact total is tendered. Reset when the method changes so a cash figure
   * never rides onto a card row.
   */
  tenderRaw: string | null;
  /** What was actually posted — kept for the receipt screen. */
  tendered?: number;
  change?: number;
}

function newIdempotencyKey(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }
  return `key-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

function formatError(e: unknown): string {
  if (e instanceof ApiError) {
    return (e.body?.message as string | undefined) ?? `${e.status}: ${e.message}`;
  }
  if (e instanceof Error) return e.message;
  return "Unknown error";
}

function emptyPaymentRow(): PaymentRowState {
  return {
    methodId: null,
    status: "idle",
    errorMessage: null,
    paymentId: null,
    paidAt: null,
    idempotencyKey: null,
    tenderRaw: null,
  };
}

export function SplitBillByItemsTab({
  order,
  taxRate,
  serviceRate,
  methods,
  methodsLoading,
  onCreatePayment,
  onAllRowsPaid,
  onCollectWithMachine,
  machineIdle = false,
  onClose,
}: SplitBillByItemsTabProps) {
  const { t, locale } = useTranslation();

  // Filter to "live" items — voided lines never participate.
  const items: CustomerOrderItem[] = useMemo(
    () => (order?.items ?? []).filter((i) => i.status !== "voided"),
    [order?.items]
  );

  const guestCount = Math.max(1, Number(order?.guest_count ?? 2));
  const defaultPeople = Math.max(MIN_PEOPLE, Math.min(MAX_PEOPLE, guestCount));

  const [state, setState] = useState<SplitByItemsState>(() => ({
    people: defaultPeople,
    allocations: {},
  }));

  /** Active person idx — palette clicks land here. */
  const [activePersonIdx, setActivePersonIdx] = useState<number>(0);

  /** Per-person payment row state, keyed by bill index (0-based). */
  const [paymentRows, setPaymentRows] = useState<Record<number, PaymentRowState>>({});

  // Synchronous in-flight lock per person index — same pattern as the equal
  // tab so a double-click on Pay can't fire two concurrent POSTs before
  // setState propagates.
  const inFlightRef = useRef<Set<number>>(new Set());

  const result = useMemo(() => {
    if (!order) {
      return { bills: [], unassignedUnits: [], totalCheck: 0 };
    }
    return splitByItems({ order, taxRate, serviceRate, state });
  }, [order, taxRate, serviceRate, state]);

  const bills = result.bills;

  const hasNoValidItems = items.length === 0;

  // Per-item "remaining unassigned" map — drives the right-panel +/-
  // counter and the "fully assigned" disabled state.
  const remainingByItem = useMemo(() => {
    const map = new Map<string, number>();
    for (const item of items) {
      const qty = Math.max(1, Math.floor(Number(item.quantity)));
      const alloc = state.allocations[item.id];
      const assigned = alloc ? alloc.units.filter((u) => u !== null).length : 0;
      map.set(item.id, Math.max(0, qty - assigned));
    }
    return map;
  }, [items, state.allocations]);

  /** Units assigned to a specific person for a specific item. */
  function unitsForPerson(itemId: string, personIdx: number): number {
    const alloc = state.allocations[itemId];
    if (!alloc) return 0;
    return alloc.units.filter((u) => u === personIdx).length;
  }

  // -------------------------------------------------------------------------
  //  Allocation mutations
  // -------------------------------------------------------------------------

  function getOrCreateAlloc(prev: SplitByItemsState, item: CustomerOrderItem): ItemAllocation {
    const qty = Math.max(1, Math.floor(Number(item.quantity)));
    const existing = prev.allocations[item.id];
    if (existing) {
      if (existing.units.length !== qty) {
        const padded = Array.from({ length: qty }, (_, i) => existing.units[i] ?? null);
        return { itemId: item.id, units: padded };
      }
      return existing;
    }
    return { itemId: item.id, units: Array.from({ length: qty }, () => null) };
  }

  /** Add ONE unit of `item` to the currently-active person. */
  function addUnitToActive(item: CustomerOrderItem) {
    // Active person already paid — their bill is frozen.
    if (paymentRows[activePersonIdx]?.status === "succeeded") return;
    setState((prev) => {
      const alloc = getOrCreateAlloc(prev, item);
      const firstNullIdx = alloc.units.findIndex((u) => u === null);
      if (firstNullIdx === -1) return prev;
      if (activePersonIdx < 0 || activePersonIdx >= prev.people) return prev;
      const nextUnits = alloc.units.slice();
      nextUnits[firstNullIdx] = activePersonIdx;
      return {
        ...prev,
        allocations: {
          ...prev.allocations,
          [item.id]: { itemId: item.id, units: nextUnits },
        },
      };
    });
  }

  /** Remove ONE unit of `item` from the active person. */
  function removeUnitFromActive(item: CustomerOrderItem) {
    if (paymentRows[activePersonIdx]?.status === "succeeded") return;
    setState((prev) => {
      const alloc = getOrCreateAlloc(prev, item);
      const matchIdx = alloc.units.findIndex((u) => u === activePersonIdx);
      if (matchIdx === -1) return prev;
      const nextUnits = alloc.units.slice();
      nextUnits[matchIdx] = null;
      return {
        ...prev,
        allocations: {
          ...prev.allocations,
          [item.id]: { itemId: item.id, units: nextUnits },
        },
      };
    });
  }

  function handleAddPerson() {
    setState((prev) => ({
      ...prev,
      people: Math.min(MAX_PEOPLE, prev.people + 1),
    }));
  }

  function handleRemoveLastPerson() {
    setState((prev) => {
      if (prev.people <= MIN_PEOPLE) return prev;
      const targetIdx = prev.people - 1;
      // Refuse if the last person already has a payment in any state past
      // idle — keeping bill_index stable matters for the audit trail.
      const row = paymentRows[targetIdx];
      if (row && row.status !== "idle") return prev;
      // Refuse if they have allocations — staff has to clear first.
      const hasAssignment = Object.values(prev.allocations).some((a) =>
        a.units.includes(targetIdx)
      );
      if (hasAssignment) return prev;
      const next = { ...prev, people: prev.people - 1 };
      if (activePersonIdx >= next.people) {
        setActivePersonIdx(Math.max(0, next.people - 1));
      }
      return next;
    });
  }

  // -------------------------------------------------------------------------
  //  Payment mutations
  // -------------------------------------------------------------------------

  function setPaymentRow(personIdx: number, patch: Partial<PaymentRowState>) {
    setPaymentRows((prev) => ({
      ...prev,
      [personIdx]: { ...emptyPaymentRow(), ...prev[personIdx], ...patch },
    }));
  }

  /** Pay one row. Fires onCreatePayment with by-items metadata. */
  async function handlePayRow(personIdx: number) {
    if (inFlightRef.current.has(personIdx)) return;
    const row = paymentRows[personIdx] ?? emptyPaymentRow();
    if (row.status === "succeeded" || row.status === "submitting") return;
    const method = methods.find((m) => m.id === row.methodId);
    if (!method) return;
    const bill = bills.find((b) => b.index === personIdx);
    if (!bill || bill.isEmpty) return;

    // Cash rows carry what this guest actually handed over. Short / garbage
    // tenders are refused by both servers, so never post one.
    const tender = computeCashTender(row.tenderRaw, bill.total);
    if (method.requires_tendered && !tender.valid) return;
    const tenderedAmount = method.requires_tendered
      ? (tender.tendered ?? bill.total)
      : undefined;
    const changeAmount = method.requires_tendered ? tender.change : undefined;

    inFlightRef.current.add(personIdx);

    const idempotencyKey = row.idempotencyKey ?? newIdempotencyKey();
    setPaymentRow(personIdx, {
      status: "submitting",
      errorMessage: null,
      idempotencyKey,
    });

    try {
      const created = await onCreatePayment({
        payment_method_id: method.id,
        amount: bill.total,
        tendered_amount: tenderedAmount,
        idempotency_key: idempotencyKey,
        expected_total_amount: Number(order?.total_amount ?? 0),
        note: `${bill.label} — ${bill.itemsBreakdown
          .map(
            (e) =>
              `${orderItemDisplayName(e.item, e.item.product_sku_id)} × ${e.units}`,
          )
          .join(", ")}`,
        // Plan-021 by-items audit. `bill_index` is the 0-based person
        // index (may be sparse — empty bills are skipped from the loop).
        metadata: byItemsSplitMetadata(
          bill,
          bills.filter((candidate) => !candidate.isEmpty).length
        ),
      });
      const paidAt = formatTime(new Date(), locale, { withSeconds: false });
      setPaymentRow(personIdx, {
        status: "succeeded",
        errorMessage: null,
        paymentId: created.id,
        paidAt,
        tendered: tenderedAmount,
        change: changeAmount,
      });
      // NOTE: completion is NOT detected here. Firing onAllRowsPaid inside this
      // handler read a stale `paymentRows` closure AND ignored still-unassigned
      // units — so paying the first person (while later people's items weren't
      // assigned yet) fired the completion screen and closed the tab, stranding
      // the rest. It now fires from an effect off `allSettled` (single source of
      // truth, includes the unassigned-units guard, converges on committed
      // state even across concurrent row payments). See the effect below.
    } catch (e) {
      setPaymentRow(personIdx, {
        status: "failed",
        errorMessage: formatError(e),
      });
    } finally {
      inFlightRef.current.delete(personIdx);
    }
  }

  /**
   * Thu phần của hàng này bằng máy 釣銭機 (#2958).
   *
   * **Cố ý không gọi `onCreatePayment`** — xem docblock của prop. Việc chốt
   * "đã trả hết" thì KHÔNG làm ở đây: tab này chốt bằng effect trên
   * `allSettled` (lý do ở ghi chú trong `handlePayRow`), nên chỉ cần đóng dấu
   * hàng và effect đó tự bắn `onAllRowsPaid` — kể cả khi hàng cuối được thu
   * bằng máy.
   */
  async function handleCollectRowWithMachine(personIdx: number) {
    if (!onCollectWithMachine) return;
    if (inFlightRef.current.has(personIdx)) return;
    const row = paymentRows[personIdx] ?? emptyPaymentRow();
    if (row.status === "succeeded" || row.status === "submitting") return;
    // Máy đếm tiền mặt; nút không hiện cho hàng thẻ, đây là dây an toàn.
    const method = methods.find((m) => m.id === row.methodId);
    if (!method?.requires_tendered) return;
    const bill = bills.find((b) => b.index === personIdx);
    if (!bill || bill.isEmpty) return;

    inFlightRef.current.add(personIdx);
    setPaymentRow(personIdx, { status: "submitting", errorMessage: null });

    try {
      const collected = await onCollectWithMachine(
        bill.total,
        byItemsSplitMetadata(
          bill,
          bills.filter((candidate) => !candidate.isEmpty).length
        )
      );
      if (!collected) {
        // Gồm ca nặng nhất: máy ĐÃ nhận tiền mà ghi sổ hỏng (#2535 B3).
        // Đánh dấu đã trả là nói dối về một khoản chưa có trong sổ.
        setPaymentRow(personIdx, {
          status: "failed",
          errorMessage: t("pos.dialog.split_bill.machine_not_settled"),
        });
        return;
      }
      setPaymentRow(personIdx, {
        status: "succeeded",
        errorMessage: null,
        paymentId: collected.id,
        paidAt: formatTime(new Date(), locale, { withSeconds: false }),
        // Số MÁY đếm, không phải pos-web tính.
        tendered: collected.tendered,
        change: collected.change,
      });
    } catch (e) {
      setPaymentRow(personIdx, { status: "failed", errorMessage: formatError(e) });
    } finally {
      inFlightRef.current.delete(personIdx);
    }
  }

  // -------------------------------------------------------------------------
  //  Derived UI state
  // -------------------------------------------------------------------------

  const nonEmptyBills = bills.filter((b) => !b.isEmpty);
  const paidCount = nonEmptyBills.filter(
    (b) => paymentRows[b.index]?.status === "succeeded"
  ).length;
  const paidAmount = nonEmptyBills
    .filter((b) => paymentRows[b.index]?.status === "succeeded")
    .reduce((sum, b) => sum + b.total, 0);
  const unpaidBills = nonEmptyBills.filter(
    (b) => paymentRows[b.index]?.status !== "succeeded"
  );
  const remainingAmount = unpaidBills.reduce((sum, b) => sum + b.total, 0);
  // Single source of truth for "the whole order is split + paid" — drives BOTH
  // the footer done-button and the onAllRowsPaid effect below.
  const allSettled = areAllByItemsBillsSettled({
    bills,
    unassignedUnitsCount: result.unassignedUnits.length,
    isBillPaid: (idx) => paymentRows[idx]?.status === "succeeded",
  });
  const anySubmitting = Object.values(paymentRows).some((r) => r.status === "submitting");
  const isActivePersonPaid =
    paymentRows[activePersonIdx]?.status === "succeeded";

  // Fire onAllRowsPaid exactly once, the moment the ENTIRE order is settled —
  // off committed state, never mid-handler. The ref re-arms if the condition
  // regresses (defensive; by-items has no refund today). This is what closes
  // the premature-completion bug: while any unit is unassigned, `allSettled`
  // is false, so the first payment can't trigger the receipt screen.
  const allPaidFiredRef = useRef(false);
  useEffect(() => {
    if (!allSettled) {
      allPaidFiredRef.current = false;
      return;
    }
    if (allPaidFiredRef.current || !onAllRowsPaid) return;
    allPaidFiredRef.current = true;
    const guests: SplitBillSessionResult["guests"] = nonEmptyBills.map(
      (b, i) => {
        const row = paymentRows[b.index];
        const m = methods.find((mm) => mm.id === row?.methodId);
        return {
          index: i + 1,
          paymentId: row?.paymentId ?? "",
          methodName: m?.name ?? "",
          methodCode: m?.code ?? "",
          amount: b.total,
          paidAt: row?.paidAt ?? "",
          tendered: row?.tendered,
          change: row?.change,
          itemsBreakdown: b.itemsBreakdown.map((e) => ({
            name: orderItemDisplayName(e.item, e.item.product_sku_id),
            units: e.units,
            subtotal: e.subtotal,
          })),
        };
      },
    );
    onAllRowsPaid({
      mode: "by_items",
      guests,
      guestCount: guests.length,
      perGuestAmount: guests[0]?.amount ?? 0,
      totalAmount: guests.reduce((sum, g) => sum + g.amount, 0),
    });
  }, [allSettled, nonEmptyBills, paymentRows, methods, onAllRowsPaid]);

  // -------------------------------------------------------------------------
  //  Render — no valid items short-circuit
  // -------------------------------------------------------------------------

  if (hasNoValidItems) {
    return (
      <>
        <div className="flex-1 overflow-y-auto px-6 pt-2 pb-4">
          <Alert>
            <AlertDescription>{t("pos.split_bill.by_items.no_valid_items")}</AlertDescription>
          </Alert>
        </div>
        <DialogFooter className="bg-muted/20 grid shrink-0 grid-cols-1 gap-2 border-t px-6 py-3">
          <Button variant="outline" onClick={onClose} className="h-11 rounded-lg">
            {t("common.close")}
          </Button>
        </DialogFooter>
      </>
    );
  }

  // -------------------------------------------------------------------------
  //  Render — single-phase allocate + pay
  // -------------------------------------------------------------------------

  return (
    <>
      <div className="flex-1 overflow-y-auto px-6 pt-2 pb-4">
        <div className="grid grid-cols-1 gap-5 md:grid-cols-12">
          {/* LEFT — people headers, person payment cards, summary */}
          <div className="md:col-span-5 min-w-0 space-y-3">
            <div className="flex items-end justify-between gap-3">
              <div>
                <div className="text-muted-foreground text-[11px] font-semibold tracking-wider uppercase">
                  {t("pos.split_bill.by_items.guest_count_label")}
                </div>
                <div className="text-foreground text-xl font-bold tabular-nums">
                  {guestCount}
                </div>
              </div>
              <div className="flex flex-col items-end gap-1">
                <div className="text-muted-foreground text-[11px] font-semibold tracking-wider uppercase">
                  {t("pos.split_bill.by_items.split_count_label")}
                </div>
                <div className="flex items-center gap-1.5">
                  <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-8 rounded-md"
                    disabled={state.people <= MIN_PEOPLE || anySubmitting}
                    onClick={handleRemoveLastPerson}
                    aria-label={t("pos.split_bill.by_items.remove_person")}
                  >
                    <MinusIcon className="size-3.5" />
                  </Button>
                  <span className="w-6 text-center text-sm font-semibold tabular-nums">
                    {state.people}
                  </span>
                  <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-8 rounded-md"
                    disabled={state.people >= MAX_PEOPLE || anySubmitting}
                    onClick={handleAddPerson}
                    aria-label={t("pos.split_bill.by_items.add_person")}
                  >
                    <PlusIcon className="size-3.5" />
                  </Button>
                </div>
              </div>
            </div>

            {result.unassignedUnits.length > 0 && (
              <Badge variant="destructive" className="h-5 px-2 text-[10px] tabular-nums">
                {t("pos.split_bill.by_items.unassigned_count", {
                  count: result.unassignedUnits.length,
                })}
              </Badge>
            )}

            <ul className="space-y-2">
              {bills.map((bill) => (
                <PersonPaymentCard
                  key={bill.index}
                  bill={bill}
                  isActive={activePersonIdx === bill.index}
                  row={paymentRows[bill.index] ?? emptyPaymentRow()}
                  methods={methods}
                  methodsLoading={methodsLoading}
                  onActivate={() => setActivePersonIdx(bill.index)}
                  onMethodChange={(methodId) =>
                    setPaymentRow(bill.index, {
                      methodId,
                      errorMessage: null,
                      // Fresh attempt: discard previous key so a failed
                      // POST + method-change doesn't dedupe into the wrong row.
                      idempotencyKey: null,
                      // A tender typed for one method must not ride onto
                      // another (card rows record none at all).
                      tenderRaw: null,
                    })
                  }
                  onTenderChange={(next) =>
                    setPaymentRow(bill.index, { tenderRaw: next })
                  }
                  onPay={() => handlePayRow(bill.index)}
                  machineIdle={machineIdle}
                  onCollectWithMachine={
                    onCollectWithMachine
                      ? () => handleCollectRowWithMachine(bill.index)
                      : undefined
                  }
                />
              ))}
            </ul>

            {nonEmptyBills.length > 0 && (
              <div className="border-border/60 space-y-1.5 border-t pt-3 text-sm">
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">
                    {t("pos.split_bill.by_items.collected_summary", {
                      paid: paidCount,
                      total: nonEmptyBills.length,
                    })}
                  </span>
                  <span className="font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">
                    {formatCurrency(paidAmount)}
                  </span>
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-muted-foreground">
                    {t("pos.split_bill.by_items.remaining_summary", {
                      count: unpaidBills.length,
                    })}
                  </span>
                  <span className="text-foreground text-base font-bold tabular-nums">
                    {formatCurrency(remainingAmount)}
                  </span>
                </div>
              </div>
            )}
          </div>

          {/* RIGHT — item picker for active person */}
          <div className="md:col-span-7 min-w-0">
            <div
              className={cn(
                "mb-3 flex items-center justify-between gap-2 rounded-lg border px-3 py-2",
                isActivePersonPaid
                  ? "border-emerald-300 bg-emerald-50/60 dark:border-emerald-500/40 dark:bg-emerald-500/10"
                  : "border-primary/30 bg-primary/5"
              )}
            >
              <div className="flex items-center gap-2">
                <span
                  className={cn(
                    "flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold tabular-nums",
                    isActivePersonPaid
                      ? "bg-emerald-500 text-white"
                      : "bg-primary text-primary-foreground"
                  )}
                >
                  {activePersonIdx + 1}
                </span>
                <span className="text-sm font-semibold text-foreground">
                  {t("pos.split_bill.by_items.select_items_for", {
                    n: activePersonIdx + 1,
                  })}
                </span>
              </div>
              {isActivePersonPaid && (
                <Badge className="h-5 bg-emerald-100 px-1.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200">
                  <CheckCircle2Icon className="mr-1 size-3" />
                  {t("pos.dialog.split_bill.status_paid")}
                </Badge>
              )}
            </div>
            <ul className="space-y-2">
              {items.map((item) => {
                const qty = Math.max(1, Math.floor(Number(item.quantity)));
                const remain = remainingByItem.get(item.id) ?? qty;
                const assignedToActive = unitsForPerson(item.id, activePersonIdx);
                const canIncrement =
                  remain > 0 && !anySubmitting && !isActivePersonPaid;
                const canDecrement =
                  assignedToActive > 0 && !anySubmitting && !isActivePersonPaid;

                return (
                  <ItemPaletteCard
                    key={item.id}
                    item={item}
                    qtyTotal={qty}
                    remain={remain}
                    assignedToActive={assignedToActive}
                    canIncrement={canIncrement}
                    canDecrement={canDecrement}
                    onIncrement={() => addUnitToActive(item)}
                    onDecrement={() => removeUnitFromActive(item)}
                  />
                );
              })}
            </ul>
          </div>
        </div>
      </div>

      <DialogFooter className="bg-muted/20 grid shrink-0 grid-cols-2 gap-2 border-t px-6 py-3">
        <Button
          variant="outline"
          onClick={onClose}
          disabled={anySubmitting && !allSettled}
          className="h-11 rounded-lg"
        >
          {allSettled ? t("common.close") : t("common.cancel")}
        </Button>
        <Button
          onClick={onClose}
          disabled={!allSettled}
          className="h-11 rounded-lg text-base font-semibold"
        >
          {t("pos.split_bill.by_items.confirm_button")}
        </Button>
      </DialogFooter>
    </>
  );
}

// ---------------------------------------------------------------------------
//  PersonPaymentCard — left-side card per person with integrated method
//  picker + Pay button. Active card highlights with a blue ring.
// ---------------------------------------------------------------------------

interface PersonPaymentCardProps {
  bill: PersonBill;
  isActive: boolean;
  row: PaymentRowState;
  methods: PaymentMethod[];
  methodsLoading: boolean;
  onActivate: () => void;
  onMethodChange: (methodId: string) => void;
  onTenderChange: (next: string | null) => void;
  onPay: () => void;
  /** Vắng ⇒ không render nút máy. */
  onCollectWithMachine?: () => void;
  machineIdle: boolean;
}

function PersonPaymentCard({
  bill,
  isActive,
  row,
  methods,
  methodsLoading,
  onActivate,
  onMethodChange,
  onTenderChange,
  onPay,
  onCollectWithMachine,
  machineIdle,
}: PersonPaymentCardProps) {
  const { t } = useTranslation();
  const succeeded = row.status === "succeeded";
  const submitting = row.status === "submitting";
  const failed = row.status === "failed";
  const itemsCount = bill.itemsBreakdown.reduce((n, e) => n + e.units, 0);
  const selectedMethod = methods.find((m) => m.id === row.methodId);
  const needsTender = selectedMethod?.requires_tendered ?? false;
  const tender = computeCashTender(row.tenderRaw, bill.total);
  // Short / unparseable cash can never be collected — both servers refuse it.
  const canPay =
    !succeeded &&
    !submitting &&
    !!row.methodId &&
    !bill.isEmpty &&
    (!needsTender || tender.valid);

  return (
    <li
      data-slot="person-payment-card"
      role="button"
      tabIndex={0}
      onClick={onActivate}
      onKeyDown={(e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          onActivate();
        }
      }}
      className={cn(
        "flex cursor-pointer flex-col gap-2 rounded-xl border-2 p-3 transition-colors",
        "border-border bg-background",
        isActive &&
          !succeeded &&
          !failed &&
          "border-primary bg-primary/5 ring-primary/30 ring-4",
        succeeded &&
          "border-emerald-400 bg-emerald-50/70 dark:border-emerald-500/40 dark:bg-emerald-500/10",
        failed && "border-red-400 bg-red-50/70 dark:border-red-500/40 dark:bg-red-500/10",
        submitting && "border-primary/60 ring-primary/20 ring-2"
      )}
    >
      <header className="flex min-w-0 items-center justify-between gap-2">
        <div className="flex min-w-0 items-center gap-2">
          <span
            className={cn(
              "flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold tabular-nums",
              isActive && !succeeded
                ? "bg-primary text-primary-foreground"
                : succeeded
                  ? "bg-emerald-500 text-white"
                  : "bg-muted text-foreground"
            )}
          >
            {bill.index + 1}
          </span>
          <div className="flex min-w-0 flex-col leading-tight">
            <span className="truncate text-base font-bold tabular-nums">
              {formatCurrency(bill.total)}
            </span>
            <span
              className={cn(
                "text-muted-foreground truncate text-[11px] tabular-nums",
                bill.isEmpty && "italic"
              )}
            >
              {bill.isEmpty
                ? t("pos.split_bill.by_items.empty_person")
                : t("pos.split_bill.by_items.items_count", { count: itemsCount })}
            </span>
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-1.5">
          {succeeded && (
            <Badge className="h-5 bg-emerald-100 px-1.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200">
              <CheckCircle2Icon className="mr-1 size-3" />
              {t("pos.dialog.split_bill.status_paid")}
            </Badge>
          )}
          {failed && (
            <Badge variant="destructive" className="h-5 px-1.5 text-[10px] font-semibold">
              <XCircleIcon className="mr-1 size-3" />
              {t("pos.dialog.split_bill.status_failed")}
            </Badge>
          )}
          {isActive && !succeeded && !failed && (
            <Badge className="h-5 bg-primary/15 px-1.5 text-[10px] font-semibold text-primary">
              {t("pos.split_bill.by_items.active_marker")}
            </Badge>
          )}
        </div>
      </header>

      {!succeeded && (
        <div className="flex min-w-0 items-center gap-1.5">
          <Select
            value={row.methodId ?? undefined}
            onValueChange={(v) => {
              // Picking a method also activates this person so subsequent
              // item clicks land here, not on whichever card was active.
              onActivate();
              onMethodChange(v);
            }}
            disabled={submitting || methodsLoading || bill.isEmpty}
          >
            <SelectTrigger className="h-9 min-w-0 flex-1 rounded-md text-xs">
              <SelectValue
                placeholder={t("pos.split_bill.by_items.method_placeholder")}
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
          {/* Thu bằng máy 釣銭機 — chỉ hàng TIỀN MẶT, và chỉ khi máy rảnh.
              Máy chỉ có MỘT nên `machineIdle` khoá mọi hàng khác trong lúc
              một lượt thu đang chạy. */}
          {onCollectWithMachine && needsTender && (
            <Button
              type="button"
              variant="outline"
              className="h-9 shrink-0 rounded-md px-2 text-xs font-semibold"
              disabled={!machineIdle || succeeded || submitting || bill.isEmpty}
              onClick={onCollectWithMachine}
            >
              <BanknoteIcon className="size-4" />
              {t("pos.dialog.split_bill.collect_with_machine")}
            </Button>
          )}
          <Button
            type="button"
            className="h-9 shrink-0 rounded-md px-3 text-sm font-semibold"
            disabled={!canPay}
            onClick={onPay}
          >
            {submitting ? <Spinner /> : t("pos.dialog.split_bill.pay_row")}
          </Button>
        </div>
      )}

      {/* Cash handed over + change for THIS guest. Only once a
          `requires_tendered` method is picked, and only before it is paid —
          the settled figures move to the line below. */}
      {!succeeded && needsTender && !bill.isEmpty && (
        <div onClick={(e) => e.stopPropagation()} onKeyDown={(e) => e.stopPropagation()}>
          <CashTenderField
            amount={bill.total}
            value={row.tenderRaw}
            onChange={onTenderChange}
            disabled={submitting}
            guestIndex={bill.index + 1}
          />
        </div>
      )}

      {succeeded && row.tendered !== undefined && (
        // Stays visible after collection: the cashier still has to count this
        // change out, and the input that showed it is gone by now.
        <div className="text-muted-foreground flex items-center justify-between gap-2 text-[11px] tabular-nums">
          <span>
            {t("pos.split_bill.cash.tendered")}{" "}
            <span className="text-foreground font-semibold">
              {formatCurrency(row.tendered)}
            </span>
          </span>
          <span>
            {t("pos.split_bill.cash.change")}{" "}
            <span className="font-semibold text-emerald-700 dark:text-emerald-300">
              {formatCurrency(row.change ?? 0)}
            </span>
          </span>
        </div>
      )}

      {row.errorMessage && (
        <div className="text-xs text-red-700 dark:text-red-300">{row.errorMessage}</div>
      )}
    </li>
  );
}

// ---------------------------------------------------------------------------
//  ItemPaletteCard — right-side item card for the active person, with
//  thumbnail · name · toppings · note · price · +/- counter showing
//  the units currently assigned to the active person.
// ---------------------------------------------------------------------------

interface ItemPaletteCardProps {
  item: CustomerOrderItem;
  qtyTotal: number;
  /** Units still unassigned across all persons. Decreases as staff assigns. */
  remain: number;
  assignedToActive: number;
  canIncrement: boolean;
  canDecrement: boolean;
  onIncrement: () => void;
  onDecrement: () => void;
}

function ItemPaletteCard({
  item,
  qtyTotal,
  remain,
  assignedToActive,
  canIncrement,
  canDecrement,
  onIncrement,
  onDecrement,
}: ItemPaletteCardProps) {
  const { t } = useTranslation();
  const name = orderItemDisplayName(item, item.product_sku_id);
  const imageUrl =
    item.product_sku?.image_url ?? item.product_sku?.product?.image_url ?? null;
  const toppings = item.toppings ?? [];
  // Through the shared rule: `topping_subtotal` is ALREADY per unit, so there
  // is no division. Dividing by the line quantity here printed a per-unit price
  // BELOW what the guest is charged (¥1.033 on a ¥1.100 unit), and this card is
  // the number staff read aloud while allocating units between people — the
  // same figure `perUnitSubtotal` in `../lib/split-by-items.ts` uses to compute
  // the bills this card describes, so the two must come from one place.
  const perUnit = Math.round(perUnitPrice(item.unit_price, item.topping_subtotal));

  const fullyAssigned = remain === 0 && qtyTotal > 0;
  const isSelected = assignedToActive > 0;
  // When the item hasn't been picked for this person yet, the entire card
  // is the "add" affordance — click anywhere to assign the first unit.
  // After that, the inline +/- controls take over.
  const cardClickable = !isSelected && canIncrement;
  const handleCardClick = () => {
    if (cardClickable) onIncrement();
  };

  return (
    <li
      role={cardClickable ? "button" : undefined}
      tabIndex={cardClickable ? 0 : undefined}
      onClick={cardClickable ? handleCardClick : undefined}
      onKeyDown={
        cardClickable
          ? (e) => {
              if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                handleCardClick();
              }
            }
          : undefined
      }
      className={cn(
        "flex items-stretch gap-3 rounded-xl border-2 p-2.5 transition-colors",
        fullyAssigned && !isSelected && "opacity-60",
        isSelected
          ? "border-primary/60 bg-primary/5"
          : cardClickable
            ? "border-border/60 bg-background hover:border-primary/40 hover:bg-primary/5 cursor-pointer"
            : "border-border/60 bg-background"
      )}
    >
      <div className="bg-muted/60 size-16 shrink-0 overflow-hidden rounded-lg text-xl">
        <ItemThumb imageUrl={imageUrl} label={name} />
      </div>

      <div className="min-w-0 flex-1 space-y-0.5">
        <div className="flex items-start justify-between gap-2">
          <h4 className="text-foreground truncate text-sm font-bold leading-tight">
            {name}
          </h4>
          <span
            className={cn(
              "shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold tabular-nums",
              fullyAssigned
                ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200"
                : remain < qtyTotal
                  ? "bg-primary/15 text-primary"
                  : "bg-muted text-muted-foreground"
            )}
          >
            {fullyAssigned ? "Đã gán hết" : `Còn ${remain}/${qtyTotal}`}
          </span>
        </div>
        {toppings.length > 0 && (
          <ul className="text-muted-foreground space-y-0 text-xs leading-snug">
            {toppings.map((tp) => {
              const isRemove = tp.modifier_type === "remove";
              const extra = Number(tp.unit_price);
              const priceTag =
                !isRemove && extra > 0 ? ` (${formatCurrency(extra)})` : "";
              const prefix = isRemove ? "− " : "+ ";
              const tpName =
                tp.name != null
                  ? collapseMirroredToppingName(tp.name)
                  : (tp.topping_group_item_id ?? "");
              const qtySuffix = tp.quantity > 1 ? ` ×${tp.quantity}` : "";
              return (
                <li key={tp.id} className="truncate">
                  {prefix}
                  {tpName}
                  {priceTag}
                  {qtySuffix}
                </li>
              );
            })}
          </ul>
        )}
        {item.note && (
          <p className="truncate text-xs italic text-amber-600 dark:text-amber-400">
            Note: {item.note}
          </p>
        )}
        <div className="text-foreground pt-0.5 text-sm font-semibold tabular-nums">
          {formatCurrency(perUnit)}
        </div>
      </div>

      <div className="flex shrink-0 items-center gap-1 self-center">
        {isSelected ? (
          <>
            <Button
              type="button"
              variant="outline"
              size="icon"
              className="size-8 rounded-md"
              disabled={!canDecrement}
              onClick={onDecrement}
              aria-label={t("common.decrease")}
            >
              <MinusIcon className="size-4" />
            </Button>
            <span className="text-primary w-7 text-center text-base font-bold tabular-nums">
              {assignedToActive}
            </span>
            <Button
              type="button"
              variant="outline"
              size="icon"
              className="size-8 rounded-md"
              disabled={!canIncrement}
              onClick={onIncrement}
              aria-label={t("common.increase")}
            >
              <PlusIcon className="size-4" />
            </Button>
          </>
        ) : (
          <span
            className={cn(
              "flex size-9 items-center justify-center rounded-full",
              cardClickable
                ? "bg-primary/10 text-primary"
                : "bg-muted text-muted-foreground/60"
            )}
            aria-hidden
          >
            <PlusIcon className="size-4" />
          </span>
        )}
      </div>
    </li>
  );
}
