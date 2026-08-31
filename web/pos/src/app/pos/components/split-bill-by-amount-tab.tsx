/**
 * SplitBillByAmountTab — plan-038 T5.2.
 *
 * Third tab in SplitBillDialog. Lets the cashier allocate any amount per
 * person (Σ amounts must equal order.total — hard equality, decision Q3).
 * Each row carries a label + amount + method selector + Thu button.
 *
 * Differs from `by_items` in that there's no per-item allocation: the
 * receipt formatter renders only the row's label + amount, not the item
 * breakdown. Workstation slip prints "Người 1 — 100,000 đ" with no list.
 *
 * Slim, single-file component — mirrors the simpler equal-tab footprint
 * (~300 LoC) rather than the heavier by-items grid because there's no
 * item palette to manage.
 */

import { useCallback, useEffect, useMemo, useState } from "react";
import { BanknoteIcon, PlusIcon, Trash2Icon } from "lucide-react";
import { Button, Card, Input } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { ApiError } from "@/lib/api";
import { iconFor } from "../lib/payment-method-icon";
import {
  computeByAmountFooter,
  minorUnit,
  seedRow,
} from "@/lib/split-by-amount";
import type {
  CustomerOrder,
  PaymentMethod,
  PersonBillByAmount,
} from "../types";
import { formatCurrency } from "../lib/totals";
import { computeCashTender } from "../lib/cash-tender";
import { CashTenderField } from "./cash-tender-field";
import type {
  SplitBillSessionResult,
  SplitPaymentBody,
} from "./split-bill-even-tab";
import { Spinner } from "@/components/ui/spinner";
import type { CashChangerSplitMetadata } from "@/services/workstation-cash-changer-service";

function byAmountSplitMetadata(
  row: PersonBillByAmount
): Extract<CashChangerSplitMetadata, { split_mode: "by_amount" }> {
  return {
    split_mode: "by_amount",
    // UI indices are 1-based; audit metadata is canonical 0-based.
    bill_index: row.bill_index - 1,
    total_bills: row.total_bills,
    label: row.label,
    amount: row.amount,
  };
}

/**
 * Is this row's cash tender submittable? Non-cash rows are always fine; a cash
 * row must cover its own share. Mirrors the guard inside `submitRow`.
 */
function cashTenderOk(
  row: PersonBillByAmount,
  methods: PaymentMethod[],
  currency: string,
): boolean {
  const method = methods.find((m) => m.id === row.method_id);
  if (!method?.requires_tendered) return true;
  return computeCashTender(row.tenderRaw, row.amount, currency).valid;
}

export interface SplitBillByAmountTabProps {
  order: CustomerOrder | null;
  methods: PaymentMethod[];
  methodsLoading: boolean;
  /** Same onCreatePayment signature as the other tabs. Returns created
   * payment with at least `id`. */
  onCreatePayment: (body: SplitPaymentBody) => Promise<{ id: string }>;
  /** Fired when every row is paid. Parent opens SplitBillReceiptDialog. */
  onAllRowsPaid?: (data: SplitBillSessionResult) => void;
  /**
   * Thu phần của MỘT hàng bằng máy 釣銭機 (#2958, khuôn từ #2946).
   *
   * Hàng đi đường này **không bao giờ** chạm `onCreatePayment`: máy trạm là
   * người ghi duy nhất của luồng 釣銭機, nên một POST thứ hai ở đây là **thu
   * tiền khách hai lần**. Vắng prop ⇒ không nút nào hiện ra.
   */
  onCollectWithMachine?: (
    amount: number,
    metadata: CashChangerSplitMetadata
  ) => Promise<{ id: string; tendered?: number; change?: number } | null>;
  /** Máy có mặt và rảnh. Máy chỉ có MỘT nên một lượt thu khoá mọi hàng khác. */
  machineIdle?: boolean;
  onClose: () => void;
  /**
   * ISO 4217 currency of the shop (#555 L1) — drives the minor-unit
   * rounding of the split rows. From `ShopOrderSetting.currency_code`;
   * falls back to VND when the settings query hasn't resolved.
   */
  currencyCode?: string | null;
}

function formatError(e: unknown): string {
  if (e instanceof ApiError) {
    return (e.body?.message as string | undefined) ?? `${e.status}: ${e.message}`;
  }
  if (e instanceof Error) return e.message;
  return "Unknown error";
}

export function SplitBillByAmountTab({
  order,
  methods,
  methodsLoading,
  onCreatePayment,
  onAllRowsPaid,
  onCollectWithMachine,
  machineIdle = false,
  onClose,
  currencyCode,
}: SplitBillByAmountTabProps) {
  const { t } = useTranslation();
  // #555 L1 — currency follows ShopOrderSetting.currency_code (threaded from
  // page.tsx); the VND fallback only covers the pre-settings render.
  const currency = currencyCode ?? "VND";
  // Snapshot BOTH the outstanding balance AND the order total ONCE per dialog
  // session:
  //   - `outstanding` (remaining = total − already-paid) is what the rows must
  //     sum to. Balancing against the FULL total was the bug: on a partially-
  //     paid order (a prior deposit / an earlier split installment) every row
  //     overpaid the outstanding, so the backend 422'd each "Thu" with
  //     `overpayment_blocked` ("exceeds the outstanding order balance").
  //   - `orderTotalAmount` is sent as `expected_total_amount` for the backend's
  //     split-bill drift guard, which compares it against order.total_amount.
  // Snapshotting (not reading live) still fixes #536: as in-tab payments land,
  // live `remaining_amount` shrinks but the snapshot holds, so the footer never
  // drifts and later "Thu" buttons stay enabled. The equal tab already collects
  // against the remaining (its amounts come from GET /split-bill = remaining ÷
  // split_count) — this brings the by-amount tab to parity.
  const [snapshot, setSnapshot] = useState<{
    outstanding: number;
    total: number;
  } | null>(null);
  const outstanding =
    snapshot?.outstanding ??
    Number(order?.remaining_amount ?? order?.total_amount ?? 0);
  const orderTotalAmount = snapshot?.total ?? Number(order?.total_amount ?? 0);
  const alreadyPaid = Math.max(0, orderTotalAmount - outstanding);
  const partiallyPaid = alreadyPaid > 0.001;

  // Seed 2 rows so the dialog opens in a usable state. Labels follow the
  // i18n key "pos.split_bill.by_amount.person_label" → "Người 1" / "Người 2".
  const [rows, setRows] = useState<PersonBillByAmount[]>(() => [
    seedRow({
      billIndex: 1,
      totalBills: 2,
      defaultLabel: t("pos.split_bill.by_amount.person_label", { n: 1 }),
    }),
    seedRow({
      billIndex: 2,
      totalBills: 2,
      defaultLabel: t("pos.split_bill.by_amount.person_label", { n: 2 }),
    }),
  ]);

  // Reset rows AND re-snapshot the total when the dialog reopens against a
  // different order, so stale state from a previous flow doesn't leak in.
  useEffect(() => {
    const total = Number(order?.total_amount ?? 0);
    const remaining = Number(order?.remaining_amount ?? total);
    setSnapshot({ outstanding: remaining, total });
    setRows((prev) => prev.map((r) => ({ ...r, status: "draft" as const })));
    // Keyed on order identity only — re-snapshotting on a mid-split
    // total_amount refetch would reset already-paid rows. Split orders are
    // item-locked so the total is stable for the session.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [order?.id]);

  // Rows must sum to the OUTSTANDING balance, not the full order total.
  const footer = useMemo(
    () => computeByAmountFooter({ rows, orderTotal: outstanding, currency }),
    [rows, outstanding, currency],
  );

  const updateRow = useCallback(
    (idx: number, patch: Partial<PersonBillByAmount>) => {
      setRows((prev) => {
        const next = [...prev];
        next[idx] = { ...next[idx], ...patch };
        return next;
      });
    },
    [],
  );

  const addRow = useCallback(() => {
    setRows((prev) => {
      const totalBills = prev.length + 1;
      const fresh = seedRow({
        billIndex: totalBills,
        totalBills,
        defaultLabel: t("pos.split_bill.by_amount.person_label", {
          n: totalBills,
        }),
      });
      const next = prev.map((r) => ({ ...r, total_bills: totalBills }));
      return [...next, fresh];
    });
  }, [t]);

  const removeRow = useCallback((idx: number) => {
    setRows((prev) => {
      if (prev.length <= 2) return prev; // honour ≥ 2 minimum
      const next = prev
        .filter((_, i) => i !== idx)
        .map((r, i) => ({
          ...r,
          bill_index: i + 1,
          total_bills: prev.length - 1,
        }));
      return next;
    });
  }, []);

  /**
   * Đóng dấu MỘT hàng đã trả, rồi chốt "đã trả hết" nếu đó là hàng cuối.
   *
   * Dùng chung cho cả hai đường thu (`onCreatePayment` và máy 釣銭機): chúng
   * khác nhau ở chỗ TIỀN đi thế nào, không khác ở chỗ màn hình kể lại thế nào.
   * Hai bản sao của đoạn này sẽ lệch đúng ở ca "hàng cuối" hiếm gặp — và ca đó
   * chính là lúc màn biên lai phải mở ra.
   */
  const settleRow = useCallback(
    (idx: number, paymentId: string, tendered?: number, change?: number) => {
      const paidAt = new Date().toLocaleTimeString().slice(0, 5);
      // Store THIS row's own payment id + time — the receipt dialog keys its
      // per-guest select/print on paymentId, so it must be unique per row.
      updateRow(idx, { status: "paid", paymentId, paidAt, tendered, change });
      // After mutation completes, check if every row is paid → wrap. Project
      // the just-paid row (React state is async) with its id + time.
      const updatedRows = rows.map((r, i) =>
        i === idx
          ? { ...r, status: "paid" as const, paymentId, paidAt, tendered, change }
          : r,
      );
        if (updatedRows.every((r) => r.status === "paid") && onAllRowsPaid) {
          // SplitBillSessionResult is shaped for equal/by_items today.
          // by_amount rows reuse the equal mode shape — the receipt
          // dialog renders the per-guest amounts straight off `guests`.
          // The mode union will gain "by_amount" in a follow-up; until
          // then we report "even" so the existing receipt-dialog code
          // path continues to render correctly.
          onAllRowsPaid({
            mode: "even",
            guestCount: updatedRows.length,
            perGuestAmount: 0,
            totalAmount: updatedRows.reduce((s, r) => s + r.amount, 0),
            guests: updatedRows.map((r) => ({
              index: r.bill_index,
              methodName:
                methods.find((m) => m.id === r.method_id)?.name ?? "",
              methodCode:
                methods.find((m) => m.id === r.method_id)?.code ?? "",
              amount: r.amount,
              // Each guest's OWN id + time (was `created.id` / now() for all —
              // the bug that made selecting one guest select every guest).
              paidAt: r.paidAt ?? paidAt,
              paymentId: r.paymentId ?? "",
              tendered: r.tendered,
              change: r.change,
            })),
          });
        }
    },
    [methods, onAllRowsPaid, rows, updateRow],
  );

  /**
   * Thu phần của hàng này bằng máy 釣銭機 (#2958).
   *
   * **Cố ý không gọi `onCreatePayment`** — máy trạm đã ghi payment rồi; một
   * POST nữa là thu tiền khách hai lần. Việc đóng dấu hàng + chốt "đã trả hết"
   * đi qua `settleRow`, đúng đường mà `submitRow` dùng, nên thu hàng CUỐI bằng
   * máy vẫn mở màn biên lai.
   */
  const collectRowWithMachine = useCallback(
    async (idx: number) => {
      if (!onCollectWithMachine) return;
      const row = rows[idx];
      if (!order || !row || row.status === "paid" || row.status === "submitting") return;
      if (row.amount <= 0) return;
      // Máy đếm tiền mặt; nút không hiện cho hàng thẻ, đây là dây an toàn.
      const method = methods.find((m) => m.id === row.method_id);
      if (!method?.requires_tendered) return;

      updateRow(idx, { status: "submitting", errorMessage: null });
      try {
        const collected = await onCollectWithMachine(
          row.amount,
          byAmountSplitMetadata(row)
        );
        if (!collected) {
          // Gồm ca máy ĐÃ nhận tiền mà ghi sổ hỏng (#2535 B3) — đánh dấu đã
          // trả là nói dối về một khoản chưa có trong sổ.
          updateRow(idx, {
            status: "failed",
            errorMessage: t("pos.dialog.split_bill.machine_not_settled"),
          });
          return;
        }
        // Số MÁY đếm, không phải pos-web tính.
        settleRow(idx, collected.id, collected.tendered, collected.change);
      } catch (err) {
        updateRow(idx, { status: "failed", errorMessage: formatError(err) });
      }
    },
    [methods, onCollectWithMachine, order, rows, settleRow, t, updateRow],
  );

  const submitRow = useCallback(
    async (idx: number) => {
      const row = rows[idx];
      if (!order || row.status === "paid" || row.status === "submitting") return;
      if (!row.method_id || row.amount <= 0) return;
      const method = methods.find((m) => m.id === row.method_id);
      if (!method) return;
      // Cash rows carry what this guest actually handed over. A short or
      // unparseable tender is refused by both servers — never post one.
      const tender = computeCashTender(row.tenderRaw, row.amount, currency);
      if (method.requires_tendered && !tender.valid) return;
      const tenderedAmount = method.requires_tendered
        ? (tender.tendered ?? row.amount)
        : undefined;
      const changeAmount = method.requires_tendered ? tender.change : undefined;
      updateRow(idx, { status: "submitting", errorMessage: null });
      try {
        const created = await onCreatePayment({
          payment_method_id: method.id,
          amount: row.amount,
          // Cash & other `requires_tendered` methods must carry a tendered
          // amount ≥ the charge. It is what the guest actually handed over
          // (`../lib/cash-tender`), not the share — hard-coding the share here
          // is what made every cash slip print "thối 0". Omitting it entirely
          // 422s with "Tendered amount must be provided and must be >= payment
          // amount + tip amount."
          tendered_amount: tenderedAmount,
          idempotency_key: row.idempotency_key,
          // Drift guard compares this against order.total_amount — send the
          // full total, NOT the outstanding.
          expected_total_amount: orderTotalAmount,
          // Metadata bill_index is 0-based; helper is shared with the machine
          // path so the two writers cannot drift to different audit context.
          metadata: byAmountSplitMetadata(row),
        });
        settleRow(idx, created.id, tenderedAmount, changeAmount);
      } catch (err) {
        // Surface the failure inline under the row instead of only console —
        // the cashier saw "! Lỗi" with no reason before.
        updateRow(idx, { status: "failed", errorMessage: formatError(err) });
      }
    },
    [
      currency,
      methods,
      onCreatePayment,
      order,
      orderTotalAmount,
      rows,
      // `onAllRowsPaid` không còn ở đây: nó đã chuyển vào `settleRow` cùng
      // khối chốt "đã trả hết".
      // `settleRow` đóng gói `rows` — bỏ nó khỏi đây là giữ một closure cũ
      // trên đường tiền, và ca lộ ra sẽ là "hàng cuối đã trả mà màn biên lai
      // không mở" vì bản `rows` nó thấy còn thiếu hàng vừa trả.
      settleRow,
      updateRow,
    ],
  );

  const unit = minorUnit(currency);
  const driftCopy =
    footer.drift === 0
      ? null
      : t("pos.split_bill.by_amount.footer.drift", {
          amount: formatCurrency(Math.abs(footer.drift) / unit),
        });

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      {rows.length < 2 && (
        <div className="mx-6 my-3 rounded-md border border-amber-400 bg-amber-50 px-3 py-2 text-xs text-amber-900">
          {t("pos.split_bill.by_amount.min_rows_hint")}
        </div>
      )}

      {/* Scrollable row list */}
      <div className="flex-1 overflow-y-auto px-6 py-4">
        <div className="space-y-3">
          {rows.map((row, idx) => (
            <Card key={row.idempotency_key} className="space-y-3 p-4">
              <div className="flex items-center justify-between gap-2">
                <Input
                  value={row.label}
                  onChange={(e) => updateRow(idx, { label: e.target.value })}
                  className="max-w-[200px]"
                />
                <span className="text-xs text-muted-foreground">
                  {row.bill_index} / {row.total_bills}
                </span>
                <Button
                  variant="ghost"
                  size="icon"
                  onClick={() => removeRow(idx)}
                  disabled={rows.length <= 2 || row.status === "paid"}
                  aria-label={t("common.remove")}
                >
                  <Trash2Icon className="size-4" />
                </Button>
              </div>

              <div className="grid grid-cols-[1fr_auto] items-end gap-3">
                <Input
                  type="number"
                  inputMode="decimal"
                  step={unit === 1 ? 1 : 0.01}
                  min={0}
                  value={row.amount > 0 ? String(row.amount) : ""}
                  onChange={(e) =>
                    updateRow(idx, {
                      amount: Number(e.target.value) || 0,
                      errorMessage: null,
                    })
                  }
                  disabled={row.status === "paid"}
                  placeholder={t("pos.split_bill.by_amount.amount_label")}
                  className="text-lg font-bold tabular-nums"
                />
                <span className="text-sm font-semibold text-muted-foreground">
                  {formatCurrency(row.amount)}
                </span>
              </div>

              {/* Method picker row */}
              <div className="flex flex-wrap gap-2">
                {methodsLoading && (
                  <span className="text-xs text-muted-foreground">…</span>
                )}
                {methods.map((m) => {
                  const Icon = iconFor(m.code);
                  const active = row.method_id === m.id;
                  return (
                    <button
                      key={m.id}
                      type="button"
                      disabled={row.status === "paid"}
                      onClick={() =>
                        updateRow(idx, {
                          method_id: m.id,
                          errorMessage: null,
                          // A tender typed for one method must not ride onto
                          // another (card rows record none at all).
                          tenderRaw: null,
                        })
                      }
                      className={`flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-xs ${
                        active
                          ? "border-primary bg-primary/10 font-semibold"
                          : "border-border hover:border-primary/40"
                      } ${row.status === "paid" ? "opacity-50" : ""}`}
                    >
                      <Icon className="size-4" />
                      {m.name}
                    </button>
                  );
                })}
              </div>

              {/* Cash handed over + change for THIS guest. Only once a
                  `requires_tendered` method is picked and before it is paid —
                  the settled figures move to the line below. */}
              {(() => {
                const selected = methods.find((m) => m.id === row.method_id);
                if (row.status === "paid" || !selected?.requires_tendered) return null;
                return (
                  <CashTenderField
                    amount={row.amount}
                    value={row.tenderRaw ?? null}
                    onChange={(next) => updateRow(idx, { tenderRaw: next })}
                    disabled={row.status === "submitting"}
                    currency={currency}
                    guestIndex={row.bill_index}
                  />
                );
              })()}

              {row.status === "paid" && row.tendered !== undefined && (
                // Stays visible after collection: the cashier still has to
                // count this change out, and the input that showed it is gone.
                <div className="flex items-center justify-between gap-2 text-[11px] tabular-nums text-muted-foreground">
                  <span>
                    {t("pos.split_bill.cash.tendered")}{" "}
                    <span className="font-semibold text-foreground">
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

              {/* Thu button */}
              <div className="flex justify-end gap-2">
                {/* Thu bằng máy 釣銭機 — chỉ hàng TIỀN MẶT, chỉ khi máy rảnh.
                    Cùng rào `footer.balanced` với nút Thu: các số do thu ngân
                    gõ, chưa khớp tổng thì đừng để máy nuốt tiền thật. */}
                {onCollectWithMachine &&
                  methods.find((m) => m.id === row.method_id)?.requires_tendered && (
                    <Button
                      type="button"
                      variant="outline"
                      onClick={() => void collectRowWithMachine(idx)}
                      disabled={
                        !machineIdle ||
                        row.status === "paid" ||
                        row.status === "submitting" ||
                        row.amount <= 0 ||
                        !footer.balanced
                      }
                      className="h-10"
                    >
                      <BanknoteIcon className="size-4" />
                      {t("pos.dialog.split_bill.collect_with_machine")}
                    </Button>
                  )}
                <Button
                  type="button"
                  onClick={() => submitRow(idx)}
                  disabled={
                    row.status === "paid" ||
                    row.status === "submitting" ||
                    !row.method_id ||
                    row.amount <= 0 ||
                    !footer.balanced ||
                    // Short / unparseable cash can never be collected — both
                    // the workstation and Cloud refuse tendered < amount.
                    !cashTenderOk(row, methods, currency)
                  }
                  className="h-10 min-w-[120px]"
                >
                  {row.status === "submitting" ? (
                    <>
                      <Spinner className="mr-1.5 size-4" />
                      …
                    </>
                  ) : row.status === "paid" ? (
                    <>✓ {t("pos.dialog.split_bill.status_paid")}</>
                  ) : row.status === "failed" ? (
                    <>! {t("pos.dialog.split_bill.status_failed")}</>
                  ) : (
                    t("pos.dialog.split_bill.pay_row")
                  )}
                </Button>
              </div>

              {row.status === "failed" && row.errorMessage && (
                <div className="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-300">
                  {row.errorMessage}
                </div>
              )}
            </Card>
          ))}
        </div>

        <Button
          variant="outline"
          onClick={addRow}
          className="mt-4 w-full"
          disabled={rows.some((r) => r.status === "paid")}
        >
          <PlusIcon className="mr-1.5 size-4" />
          {t("pos.split_bill.by_amount.add_person")}
        </Button>
      </div>

      {/* Footer summary */}
      <div className="border-t bg-muted/30 px-6 py-3">
        <div className="flex items-center justify-between text-sm">
          <span>{t("pos.split_bill.by_amount.footer.allocated")}</span>
          <span className="font-bold tabular-nums">
            {formatCurrency(footer.sumAllocated)}
          </span>
        </div>
        <div className="flex items-center justify-between text-sm">
          <span>{t("pos.split_bill.by_amount.footer.to_collect")}</span>
          <span className="font-bold tabular-nums">
            {formatCurrency(outstanding)}
            {footer.balanced ? (
              <span className="ml-2 text-emerald-600">✓</span>
            ) : (
              <span className="ml-2 text-red-600">✗</span>
            )}
          </span>
        </div>
        {partiallyPaid && (
          <div className="mt-0.5 flex items-center justify-end gap-3 text-xs tabular-nums text-muted-foreground">
            <span>
              {t("pos.split_bill.by_amount.footer.total")}:{" "}
              {formatCurrency(orderTotalAmount)}
            </span>
            <span>
              {t("pos.split_bill.by_amount.footer.paid")}:{" "}
              {formatCurrency(alreadyPaid)}
            </span>
          </div>
        )}
        {driftCopy && (
          <div className="mt-1 text-right text-xs font-semibold text-red-600">
            {driftCopy}
          </div>
        )}
        <div className="mt-2 flex justify-end">
          <Button variant="ghost" onClick={onClose}>
            {t("common.close")}
          </Button>
        </div>
      </div>
    </div>
  );
}
