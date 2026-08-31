/**
 * SplitBillReceiptDialog — confirmation screen shown after every row in a
 * split-bill session has been collected.
 *
 * Plan-038 T6.2: clicking "In biên lai (N)" now actually fires N print
 * calls against the workstation, with 200ms gaps between calls (gives the
 * thermal printer cut/feed time). Per-row status indicator surfaces
 * pending → printing → printed/failed so the cashier sees progress live.
 */

import { useMemo, useState } from "react";
import { Button, Dialog, DialogTitle } from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { HelpButton } from "@/help/help-button";
import {
  CheckIcon,
  ChevronRightIcon,
  PrinterIcon,
  ReceiptTextIcon,
  XCircleIcon,
  XIcon,
} from "lucide-react";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { cn } from "@/lib/utils";
import { formatDateTime } from "@/lib/format-date";
import { useTranslation } from "@/providers/app-provider";
import { workstationPrintService } from "@/services/workstation-print-service";
import { formatCurrency } from "../lib/totals";
import { RedInvoiceDialog } from "./red-invoice-dialog";
import { RedInvoicePrintedBadge } from "./red-invoice-printed-badge";
import { useOrderPrintCounts } from "../hooks/use-order-print-counts";
import { isOnHoldError } from "../lib/on-hold";
import { Spinner } from "@/components/ui/spinner";

type RowPrintStatus = "idle" | "pending" | "printing" | "printed" | "failed";

const PRINT_GAP_MS = 200;

/** One settled split-bill row, rendered as a selectable card. */
export interface SplitBillReceiptGuest {
  /** 1-based seat index (Khách 1, 2, …). */
  index: number;
  /** Display name from the chosen payment method ("Tiền mặt", "Vietcombank"…). */
  methodName: string;
  /** Backend code (`cash`/`card`/`transfer`/…) — drives the row icon. */
  methodCode: string;
  /** Decimal VND amount this guest paid. */
  amount: number;
  /** Pre-formatted HH:MM time the payment landed. */
  paidAt: string;
  /** Server payment id — used as the selection key for the print queue. */
  paymentId: string;
  /**
   * Cash this guest handed over, and what they got back. Present only on
   * `requires_tendered` (cash) rows. Both are shown here and printed on the
   * guest's own slip — the whole point of collecting them per row.
   */
  tendered?: number;
  change?: number;
  /**
   * Plan-021 — present on by-items mode rows so the receipt prints
   * each guest's items above the amount block. Empty array on equal
   * mode (or by-items rows with no items, which shouldn't happen).
   */
  itemsBreakdown?: Array<{ name: string; units: number; subtotal: number }>;
}

export interface SplitBillReceiptData {
  /** Plan-021 — which split mode produced this receipt. */
  mode?: "even" | "by_items";
  /** Immutable order code (e.g. "ORD-2026-2843"). */
  orderCode: string;
  /** Comma-joined table names ("B-08") or null when no table assigned. */
  tableLabel: string | null;
  /** Configured `guest_count` on the order. */
  guestCount: number;
  /** Sum of all guests' amounts (= order.total_amount when fully paid). */
  totalAmount: number;
  /** Per-guest amount (snapshot taken when split-bill started). */
  perGuestAmount: number;
  /** Session finish timestamp — header subtitle. */
  paidAt: Date;
  /** All settled guests in chronological order. */
  guests: SplitBillReceiptGuest[];
  /** Outstanding amount (typically 0 because we only show this when fully paid). */
  remaining: number;
}

export interface SplitBillReceiptDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  data: SplitBillReceiptData;
  /** Plan-038 T6.2 — required for per-row print calls. When omitted, the
   * footer button degrades to legacy onComplete-only behaviour. */
  orderId?: string;
  /** Order's customer id — needed by the VAT invoice issue flow. */
  /**
   * Accepted for caller compatibility but no longer read: #1309 removed the
   * customer gate from the VAT-invoice form, so nothing in this dialog needs
   * it. Kept on the interface so the two call sites keep compiling.
   */
  customerId?: string | null;
  /** Order's customer name — pre-fills the red-invoice name field. */
  customerName?: string | null;
  /**
   * Fired after a VAT invoice (hoá đơn đỏ) is issued. Omit to hide every
   * invoice CTA.
   *
   * #1225 — an invoice is no longer necessarily order-level: each guest row has
   * its own action that issues for THAT payment.
   *
   * #1939 — and in THIS dialog that is now the only shape. The whole-order
   * action was removed: a split bill has no single payer to hand it to.
   */
  /* #1779 — formal invoice removed; red invoice prints directly. */
  /**
   * Fired when staff hits "Hoàn tất" (no selection). Parent invalidates
   * caches + closes the order tab here. Dialog auto-closes.
   */
  onComplete: (selectedPaymentIds: string[]) => void;
}

export function SplitBillReceiptDialog({
  open,
  onOpenChange,
  data,
  orderId,
  customerName,
  onComplete,
}: SplitBillReceiptDialogProps) {
  const { t, locale } = useTranslation();

  const timestamp = formatDateTime(data.paidAt, locale);

  const [redInvoiceOpen, setRedInvoiceOpen] = useState(false);
  // #1779 — the red invoice prints directly (no DB record). A guest's paymentId
  // scopes the slip to that payer's items + amount.
  //
  // #1939 — null now means "no guest chosen", NOT "whole order". RedInvoiceDialog
  // still treats an absent paymentId as the whole order, so the render below is
  // gated on this being non-null: in this dialog a whole-order slip is not a
  // shape that can be reached, rather than one we merely stopped offering.
  const [redInvoicePaymentId, setRedInvoicePaymentId] = useState<string | null>(
    null,
  );
  const { counts: printCounts, refresh: refreshRedInvoiceCounts } =
    useOrderPrintCounts(orderId, open);
  const redInvoiceCounts = printCounts.redInvoice;
  const [selected, setSelected] = useState<Set<string>>(() => new Set());
  // Plan-038 T6.2 — per-row print status, keyed by paymentId.
  const [printStatus, setPrintStatus] = useState<Record<string, RowPrintStatus>>(
    {},
  );
  const [running, setRunning] = useState(false);

  function toggle(paymentId: string) {
    if (running) return;
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(paymentId)) next.delete(paymentId);
      else next.add(paymentId);
      return next;
    });
  }

  const paidCount = data.guests.length;
  const totalCount = data.guestCount;
  const totalPaid = useMemo(
    () => data.guests.reduce((sum, g) => sum + g.amount, 0),
    [data.guests],
  );
  const selectedCount = selected.size;
  const remainingFmt = formatCurrency(data.remaining);

  function handleComplete() {
    onComplete(Array.from(selected));
    onOpenChange(false);
  }

  async function handlePrintSelected() {
    if (selectedCount === 0) {
      handleComplete();
      return;
    }
    if (!orderId || !workstationPrintService.enabled) {
      handleComplete();
      return;
    }
    setRunning(true);
    // Mark all selected as pending up front so the UI shows the queue
    // ahead of time (matches the screenshot-style progress affordance).
    const queue = Array.from(selected);
    setPrintStatus((prev) => {
      const next = { ...prev };
      for (const id of queue) next[id] = "pending";
      return next;
    });

    let failures = 0;
    for (const paymentId of queue) {
      setPrintStatus((prev) => ({ ...prev, [paymentId]: "printing" }));
      try {
        await workstationPrintService.printPaymentReceipt({
          orderId,
          paymentId,
          reprintReason: "manual reprint",
        });
        setPrintStatus((prev) => ({ ...prev, [paymentId]: "printed" }));
      } catch (err) {
        failures++;
        setPrintStatus((prev) => ({ ...prev, [paymentId]: "failed" }));
        // #2049 — đơn treo: BỎ QUA đúng khách này rồi ĐI TIẾP, không dừng cả
        // hàng đợi.
        //
        // Workstation chặn theo TỜ GIẤY chứ không theo số dư của đơn: phiếu
        // phần-của-một-khách nói "khách này đã trả X" và vẫn in được kể cả khi
        // đơn còn treo vì khách KHÁC được ghi nợ. Nên trên một đơn chia bill,
        // 409 ở đây chỉ có thể là khách được ghi nợ — phiếu đúng của họ là
        // PHIẾU GHI NỢ. Dừng cả hàng đợi vì một người như vậy sẽ cướp mất biên
        // lai của những khách đã trả tiền thật.
        if (isOnHoldError(err)) {
          toast.error(t("pos.on_hold.print_blocked"));
          continue;
        }
        if (err instanceof ApiError && err.status === 503) {
          toast.error(t("pos.kitchen.no_printer"));
          break;
        }
      }
      // Gap so the thermal printer has time to feed/cut between slips.
      await new Promise((r) => setTimeout(r, PRINT_GAP_MS));
    }
    setRunning(false);
    if (failures === 0) {
      toast.success(t("pos.kitchen.sent_n_items", { n: String(queue.length) }));
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className={cn(
          // Block + sticky pattern — same as PaymentReceiptDialog so the
          // modal hugs its content and never grows whitespace at the
          // bottom regardless of how many guest rows there are.
          "block max-h-[90vh] w-[95vw] !max-w-lg gap-0 overflow-y-auto rounded-2xl p-0"
        )}
      >
        {/* Header — green check + title + close X. */}
        <div className="bg-background sticky top-0 z-10 flex items-start gap-3 border-b px-5 pt-5 pb-4">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
            <CheckIcon className="size-6" strokeWidth={3} />
          </span>
          <div className="min-w-0 flex-1 space-y-0.5">
            <div className="flex items-center gap-1.5">
              <DialogTitle className="text-foreground text-lg font-bold tracking-tight">
                {t("pos.dialog.split_receipt.title")}
              </DialogTitle>
              <HelpButton topic="split-bill-receipt" className="size-7" />
            </div>
            <p className="text-muted-foreground text-xs tabular-nums">{timestamp}</p>
          </div>
          <button
            type="button"
            onClick={() => onOpenChange(false)}
            aria-label={t("common.close")}
            className="text-muted-foreground hover:bg-muted hover:text-foreground flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-md transition-colors"
          >
            <XIcon className="size-4" />
          </button>
        </div>

        {/* Top summary — grand total + order info on left, per-guest amount card on right. */}
        <div className="border-b px-5 py-4">
          <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,auto)] gap-4">
            <div className="min-w-0 space-y-1.5">
              <div className="text-foreground text-3xl font-bold tabular-nums">
                {formatCurrency(data.totalAmount)}
              </div>
              <div className="text-muted-foreground space-y-0.5 text-xs">
                <div>
                  {t("pos.dialog.split_receipt.order_code")}{" "}
                  <span className="text-foreground font-mono font-semibold">{data.orderCode}</span>
                </div>
                <div className="flex flex-wrap items-center gap-x-2">
                  {data.tableLabel && (
                    <span>
                      {t("pos.dialog.split_receipt.table")}{" "}
                      <span className="text-foreground font-semibold">{data.tableLabel}</span>
                    </span>
                  )}
                  <span className="text-muted-foreground/50">·</span>
                  <span>
                    {t("pos.dialog.split_receipt.guest_count", {
                      count: totalCount,
                    })}
                  </span>
                </div>
              </div>
              <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                <CheckIcon className="size-3.5" strokeWidth={3} />
                {t("pos.dialog.split_receipt.paid_progress", {
                  paid: paidCount,
                  total: totalCount,
                })}
              </span>
            </div>
            <div className="bg-muted/40 shrink-0 rounded-xl px-4 py-3 text-right">
              <div className="text-muted-foreground text-[10px] font-medium tracking-wide uppercase">
                {t("pos.dialog.split_receipt.per_guest")}
              </div>
              <div className="text-foreground mt-1 text-xl font-bold tabular-nums">
                {formatCurrency(data.perGuestAmount)}
              </div>
            </div>
          </div>
        </div>

        {/* Guest rows + bottom totals. */}
        <div className="px-5 py-4">
          <div className="text-muted-foreground mb-2.5 text-[10px] font-medium tracking-wide uppercase">
            {t("pos.dialog.split_receipt.list_title", { count: totalCount })}
          </div>
          <ul className="space-y-2">
            {data.guests.map((g) => {
              const isSelected = selected.has(g.paymentId);
              return (
                <li key={g.paymentId}>
                  {/* ONE card per guest. The invoice action used to be a second
                      free-floating box below this one, so two guests rendered as
                      four stacked rectangles of equal weight and the eye could
                      not tell which action belonged to which person. The border
                      now lives on this wrapper and the action is a footer strip
                      inside it, so each guest reads as a single object. */}
                  <div
                    className={cn(
                      "bg-card overflow-hidden rounded-xl border-2 transition-colors",
                      isSelected
                        ? "border-primary"
                        : "border-border/60 hover:border-primary/40"
                    )}
                  >
                    <button
                      type="button"
                      onClick={() => toggle(g.paymentId)}
                      aria-pressed={isSelected}
                      className={cn(
                        "flex w-full cursor-pointer items-center gap-3 px-4 py-3 text-left transition-colors",
                        isSelected && "bg-primary/5"
                      )}
                    >
                      {/* Index circle — number stays visible; status badge
                          overlaps the bottom-right (selected→✓ tick;
                          printing→spinner; printed→solid ✓; failed→✗). */}
                      <span className="bg-muted text-muted-foreground relative flex size-9 shrink-0 items-center justify-center rounded-full font-bold">
                        <span className="text-sm">{g.index}</span>
                        {(() => {
                          const status = printStatus[g.paymentId];
                          if (status === "printing") {
                            return (
                              <span className="absolute -right-0.5 -bottom-0.5 flex size-4 items-center justify-center rounded-full bg-blue-500 text-white shadow-sm">
                                <Spinner className="size-2.5" strokeWidth={3} />
                              </span>
                            );
                          }
                          if (status === "printed") {
                            return (
                              <span className="absolute -right-0.5 -bottom-0.5 flex size-4 items-center justify-center rounded-full bg-emerald-500 text-white shadow-sm">
                                <CheckIcon className="size-3" strokeWidth={3} />
                              </span>
                            );
                          }
                          if (status === "failed") {
                            return (
                              <span className="absolute -right-0.5 -bottom-0.5 flex size-4 items-center justify-center rounded-full bg-red-500 text-white shadow-sm">
                                <XCircleIcon className="size-3" strokeWidth={3} />
                              </span>
                            );
                          }
                          if (isSelected) {
                            return (
                              <span className="absolute -right-0.5 -bottom-0.5 flex size-4 items-center justify-center rounded-full bg-emerald-500 text-white shadow-sm">
                                <CheckIcon className="size-3" strokeWidth={3} />
                              </span>
                            );
                          }
                          return null;
                        })()}
                      </span>
                      <div className="min-w-0 flex-1">
                        <div className="text-foreground truncate text-sm font-bold">
                          {t("pos.dialog.split_receipt.guest_label", {
                            index: g.index,
                          })}
                        </div>
                        {/* Method name + time — plain text, no icon (matches
                            screenshot layout: "Tiền mặt · 17:42"). */}
                        <div className="text-muted-foreground mt-0.5 truncate text-xs">
                          {g.methodName}
                          <span className="text-muted-foreground/50 mx-1.5">·</span>
                          <span className="tabular-nums">{g.paidAt}</span>
                        </div>
                        {/* Cash handed over + change back, for this guest
                            alone. Absent on card / transfer rows. */}
                        {g.tendered !== undefined && (
                          <div className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-2 text-[11px] tabular-nums">
                            <span>
                              {t("pos.dialog.split_receipt.tendered")}{" "}
                              <span className="text-foreground font-semibold">
                                {formatCurrency(g.tendered)}
                              </span>
                            </span>
                            <span className="text-muted-foreground/50">·</span>
                            <span>
                              {t("pos.dialog.split_receipt.change")}{" "}
                              <span className="font-semibold text-emerald-700 dark:text-emerald-300">
                                {formatCurrency(g.change ?? 0)}
                              </span>
                            </span>
                          </div>
                        )}

                        {/* Plan-021 by-items mode — list each item the
                            guest paid for, with × units, so the printed
                            receipt makes sense without the order. */}
                        {data.mode === "by_items" &&
                          g.itemsBreakdown &&
                          g.itemsBreakdown.length > 0 && (
                            <ul className="text-muted-foreground mt-1 space-y-0.5 text-[11px]">
                              {g.itemsBreakdown.map((e, idx) => (
                                <li
                                  key={idx}
                                  className="flex items-center justify-between gap-2 tabular-nums"
                                >
                                  <span className="truncate">
                                    {e.name}
                                    <span className="text-muted-foreground/70 ml-1">× {e.units}</span>
                                  </span>
                                  <span>{formatCurrency(e.subtotal)}</span>
                                </li>
                              ))}
                            </ul>
                          )}
                      </div>
                      <span className="text-foreground shrink-0 text-sm font-bold tabular-nums">
                        {formatCurrency(g.amount)}
                      </span>
                    </button>

                    {/* #1779 — this guest's own hoá đơn đỏ, printed DIRECTLY (no
                        DB record). Kept OUT of the row button above rather than
                        inside it: a <button> cannot nest another button, and the
                        row button owns receipt selection.

                        #1939 — a footer strip on the guest's own card, shaped as
                        a LIST ROW rather than a button-in-a-box: icon tile ·
                        label · status · chevron. That shape is why it stopped
                        looking crude — a bordered rectangle holding two words of
                        centred text reads as an empty container, whereas a row
                        that spans the card reads as somewhere to go.

                        - the whole strip is the hit area (h-12, 48px), not a
                          smaller control sitting inside padding. Biggest possible
                          target for a cashier tapping on a tablet, and the
                          design system's upper POS control height.
                        - a plain <button>, not <Button>: this is a row, not a
                          standard control, and it sidesteps `h-element` (32px)
                          fighting the height class through tailwind-merge.
                        - the chevron is the affordance that says "this opens
                          something" — the previous versions gave no hint that a
                          form appears before anything prints.
                        - the guest index stays in the label. Split guests often
                          owe the SAME amount, so the printed figure cannot tell
                          you afterwards whose slip it was. */}
                    {workstationPrintService.enabled && orderId && (
                      <button
                        type="button"
                        onClick={() => {
                          setRedInvoicePaymentId(g.paymentId);
                          setRedInvoiceOpen(true);
                        }}
                        className="border-border/60 bg-muted/30 hover:bg-muted/70 group flex h-12 w-full cursor-pointer items-center gap-2.5 border-t px-3 text-left transition-colors"
                      >
                        <span className="bg-background border-border/60 text-muted-foreground group-hover:text-foreground group-hover:border-primary/40 flex size-7 shrink-0 items-center justify-center rounded-md border transition-colors">
                          <ReceiptTextIcon className="size-3.5" />
                        </span>
                        <span className="text-foreground min-w-0 flex-1 truncate text-xs font-medium">
                          {t("pos.invoice.issue_for_guest", { index: g.index })}
                        </span>
                        {/* #1875 — PER GUEST. This is the whole point: on a split
                            bill one payer can already have paper while the others
                            do not, and an order-level badge would smear that into
                            "someone here has been printed". */}
                        <RedInvoicePrintedBadge
                          counts={redInvoiceCounts}
                          paymentId={g.paymentId}
                        />
                        <ChevronRightIcon className="text-muted-foreground/50 group-hover:text-muted-foreground size-4 shrink-0 transition-colors" />
                      </button>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>

          {/* Totals card. */}
          <div className="bg-muted/20 mt-4 grid grid-cols-2 gap-4 rounded-xl border px-4 py-3">
            <div>
              <div className="text-muted-foreground text-[11px]">
                {t("pos.dialog.split_receipt.collected", {
                  paid: paidCount,
                  total: totalCount,
                })}
              </div>
              <div className="mt-0.5 text-base font-bold text-emerald-600 tabular-nums dark:text-emerald-400">
                {formatCurrency(totalPaid)}
              </div>
            </div>
            <div className="text-right">
              <div className="text-muted-foreground text-[11px]">
                {t("pos.dialog.split_receipt.remaining")}
              </div>
              <div className="text-foreground mt-0.5 text-base font-bold tabular-nums">
                {remainingFmt}
              </div>
            </div>
          </div>
        </div>

        {/* #1939 — the whole-order hoá đơn đỏ CTA that used to sit here is GONE.
            On a split bill the order was deliberately divided among payers who
            settled separately, so the correct paper is one invoice per guest —
            and those live on each row above. A full-width button was the largest
            tap target on the screen while being the wrong action in this
            context: one mis-tap handed a single customer a tax document
            covering the whole table. The whole-order slip still exists where it
            IS correct — PaymentReceiptDialog, the non-split receipt screen. */}

        {/* Sticky footer — single CTA. With selection, runs the print
            queue with per-row status. Without selection, behaves as the
            legacy Hoàn tất / dismiss button. */}
        <div className="bg-muted/20 sticky bottom-0 z-10 flex gap-2 border-t px-5 py-3">
          <Button
            type="button"
            onClick={
              selectedCount > 0 && !running ? handlePrintSelected : handleComplete
            }
            disabled={running}
            size="lg"
            className="h-11 gap-2 rounded-lg px-5 text-sm font-semibold"
          >
            {running ? (
              <Spinner className="size-4" />
            ) : (
              <PrinterIcon className="size-4" />
            )}
            {selectedCount > 0
              ? t("pos.dialog.split_receipt.print_count", {
                  count: selectedCount,
                })
              : t("pos.dialog.split_receipt.complete")}
          </Button>
          {selectedCount > 0 && !running && (
            <Button
              type="button"
              variant="ghost"
              size="lg"
              onClick={handleComplete}
              className="h-11 rounded-lg"
            >
              {t("pos.dialog.split_receipt.complete")}
            </Button>
          )}
        </div>

        {/* Hoá đơn đỏ — printed directly (no DB record), always scoped to ONE
            payer here (#1939). Mounting only once a guest is chosen means
            `paymentId` can never be undefined, which is what RedInvoiceDialog
            reads as "whole order".

            Remounting per guest is also the correct behaviour, not just a
            side-effect: the name field seeds lazily on mount, so each guest
            starts blank instead of inheriting the name typed for the guest
            before them. */}
        {orderId && redInvoicePaymentId !== null && (
          <RedInvoiceDialog
            open={redInvoiceOpen}
            onOpenChange={(o) => {
              setRedInvoiceOpen(o);
              if (!o) setRedInvoicePaymentId(null);
            }}
            orderId={orderId}
            defaultCustomerName={customerName ?? undefined}
            paymentId={redInvoicePaymentId}
            onPrinted={refreshRedInvoiceCounts}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}
