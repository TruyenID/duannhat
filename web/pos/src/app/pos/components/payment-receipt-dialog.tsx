/**
 * PaymentReceiptDialog — confirmation screen shown right after PaymentDialog
 * (or SplitBillDialog) finishes posting all payments successfully.
 *
 * Plan-038 T6.1: "In biên lai" now actually calls the workstation print
 * API. First click on an auto-shown dialog → reprintReason="auto"; every
 * subsequent click → "manual reprint" (workstation appends print_history
 * + emits payment.receipt_printed audit). Button label updates from
 * "In biên lai" → "Đã in ✓" → "In lại" as the user clicks.
 */

import { useState } from "react";
import {
  Button,
  Dialog,
  DialogTitle,
} from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { HelpButton } from "@/help/help-button";
import {
  CheckCircle2Icon,
  PrinterIcon,
  ReceiptTextIcon,
} from "lucide-react";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { Spinner } from "@/components/ui/spinner";
import { cn } from "@/lib/utils";
import { formatDateTime } from "@/lib/format-date";
import { useTranslation } from "@/providers/app-provider";
import { workstationPrintService } from "@/services/workstation-print-service";
import { formatCurrency } from "../lib/totals";
import { isOnHoldError } from "../lib/on-hold";
import { DebtChargeButton } from "./debt-charge-button";
import { RedInvoiceDialog } from "./red-invoice-dialog";
import { RedInvoicePrintedBadge } from "./red-invoice-printed-badge";
import { useOrderPrintCounts } from "../hooks/use-order-print-counts";
import type { PaymentBody } from "./payment-dialog";

/**
 * One settled payment row. Sequence is preserved (1 = first transaction
 * fired, typically the oldest debt; last = current order).
 */
export interface PaymentReceiptEntry {
  /** 1-based index — rendered as "Giao dịch #N". */
  index: number;
  /** Bold line ("Thanh toán đơn hàng" or "Thanh toán nợ cũ"). */
  title: string;
  /** Subtitle describing what this transaction settled. */
  description: string;
  /** Decimal VND amount this transaction posted. */
  amount: number;
}

export interface PaymentReceiptDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Customer info pulled from the just-paid order. Optional for walk-ins. */
  customer?: {
    name?: string | null;
    phone?: string | null;
  } | null;
  /** All transactions posted in this payment session, in chronological order. */
  receipts: PaymentReceiptEntry[];
  /** Sum of all `receipts[].amount` — same as `tendered - change`. */
  totalPaid: number;
  /** Cash handed over by the customer. May exceed `totalPaid` (change). */
  tendered: number;
  /** Timestamp the payment session finished — used for the header subtitle. */
  paidAt: Date;
  /**
   * Plan-038 T6.1 — workstation print needs these to fire a slip. When
   * `orderId` is undefined (rare — legacy callers that haven't migrated
   * yet) the "In biên lai" button degrades to the legacy onComplete
   * behaviour so the cashier flow never blocks.
   */
  orderId?: string;
  /** Targets a specific split row's payment when set; else the last
   *  confirmed payment on the order. */
  paymentId?: string;
  /** The paid order's customer_id. Gates both post-payment CTAs below —
   *  debt and VAT invoice each require a registered customer (the backend
   *  rejects walk-ins with 422 customer_required_for_{debt,invoice}). */
  customerId?: string | null;
  /** What the order STILL owes. > 0 surfaces "Ghi nợ phần còn lại". */
  remaining?: number;
  /** Shop slug — handed to the debt CTA, which reads its own method list. */
  shopSlug?: string;
  /** Returns the created payment id (needed to print the debt slip).
   *  Omit to hide the "Ghi nợ phần còn lại" CTA. */
  onCreateDebtPayment?: (
    orderId: string,
    body: PaymentBody,
  ) => Promise<{ id: string }>;
  /** Fired after the remaining balance is charged to the customer's account.
   *  The slip is NOT printed here — the parent offers an explicit print action. */
  onDebtCharged?: (paymentId: string, amount: number, orderId: string) => void;
  /**
   * Fired when the user clicks "Hoàn tất". The parent should invalidate
   * caches + close the order tab here. The dialog auto-closes after.
   */
  onComplete: () => void;
}

export function PaymentReceiptDialog({
  open,
  onOpenChange,
  customer,
  receipts,
  totalPaid,
  tendered,
  paidAt,
  orderId,
  paymentId,
  customerId,
  remaining = 0,
  shopSlug,
  onCreateDebtPayment,
  onDebtCharged,
  onComplete,
}: PaymentReceiptDialogProps) {
  const { t, locale } = useTranslation();
  const [redInvoiceOpen, setRedInvoiceOpen] = useState(false);
  const { counts: printCounts, refresh: refreshRedInvoiceCounts } =
    useOrderPrintCounts(orderId, open);
  const redInvoiceCounts = printCounts.redInvoice;

  const timestamp = formatDateTime(paidAt, locale);

  const customerName = customer?.name?.trim() || null;
  const customerPhone = customer?.phone?.trim() || null;
  const hasCustomerInfo = !!(customerName || customerPhone);

  // Plan-038 T6.1 — local click counter so the first click sends
  // reprintReason="auto" and subsequent clicks send "manual reprint".
  const [printCount, setPrintCount] = useState(0);
  const [printing, setPrinting] = useState(false);

  function handleComplete() {
    onComplete();
    onOpenChange(false);
  }

  async function handlePrint() {
    // Legacy callers that haven't wired orderId yet — degrade to onComplete
    // so the cashier can still close the dialog.
    if (!orderId || !workstationPrintService.enabled) {
      handleComplete();
      return;
    }
    setPrinting(true);
    try {
      const reason = printCount === 0 ? "auto" : "manual reprint";
      await workstationPrintService.printPaymentReceipt({
        orderId,
        paymentId,
        reprintReason: reason,
      });
      setPrintCount((c) => c + 1);
    } catch (err) {
      // #2049 — workstation từ chối vì đơn đang treo. Bắt TRƯỚC nhánh 409 chung
      // bên dưới: nhánh đó in thẳng `err.body.message`, tức một câu tiếng Anh
      // dành cho log ("order is on hold — payment not fully collected") vào mặt
      // thu ngân đang đứng trước khách.
      if (isOnHoldError(err)) {
        toast.error(t("pos.on_hold.print_blocked"));
        return;
      }
      if (err instanceof ApiError) {
        if (err.status === 503) {
          toast.error(t("pos.kitchen.no_printer"));
        } else if (err.status === 504) {
          toast.warning(t("pos.kitchen.sync_pending"));
        } else if (err.status === 409) {
          toast.error(
            (err.body.message as string) || t("pos.kitchen.fire_failed"),
          );
        } else {
          toast.error(t("pos.kitchen.fire_failed"));
        }
      } else {
        toast.error(t("pos.kitchen.fire_failed"));
      }
    } finally {
      setPrinting(false);
    }
  }

  const printLabel =
    printCount === 0
      ? t("pos.dialog.payment_receipt.print")
      : printCount === 1
        ? `${t("pos.dialog.payment_receipt.print")} ✓`
        : `${t("pos.dialog.payment_receipt.print")} #${printCount + 1}`;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className={cn(
          // Block + sticky-footer pattern used elsewhere in this codebase
          // to guarantee the dialog hugs its content height (no whitespace
          // bug from flex-grow chains).
          "block max-h-[90vh] w-[95vw] !max-w-md gap-0 overflow-y-auto rounded-2xl p-0",
        )}
      >
        {/* Header — green check + title + timestamp. */}
        <div className="sticky top-0 z-10 flex items-start gap-3 border-b bg-background px-5 pt-5 pb-4">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
            <CheckCircle2Icon className="size-6" />
          </span>
          <div className="min-w-0 flex-1 space-y-0.5">
            <div className="flex items-center gap-1.5">
              <DialogTitle className="text-lg font-bold tracking-tight text-foreground">
                {t("pos.dialog.payment_receipt.title")}
              </DialogTitle>
              <HelpButton topic="payment-receipt" className="size-7" />
            </div>
            <p className="text-xs text-muted-foreground tabular-nums">
              {timestamp}
            </p>
          </div>
        </div>

        {/* Body — customer info + transactions + totals. */}
        <div className="space-y-4 px-5 py-4">
          {/* Customer card — only when at least one of name/phone is present. */}
          {hasCustomerInfo && (
            <div className="space-y-0.5 rounded-xl border bg-card px-4 py-3">
              <div className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                {t("pos.dialog.payment_receipt.customer")}
              </div>
              {customerName && (
                <div className="text-sm font-semibold text-foreground">
                  {customerName}
                </div>
              )}
              {customerPhone && (
                <div className="text-xs text-muted-foreground">
                  {t("pos.dialog.payment_receipt.phone")}: {customerPhone}
                </div>
              )}
            </div>
          )}

          {/* Transactions list — one card per payment, in chronological
              order. Each shows index + title + amount + description. */}
          <div className="space-y-2.5">
            {receipts.map((r) => (
              <div
                key={r.index}
                className="rounded-xl border bg-card px-4 py-3"
              >
                <div className="flex items-baseline justify-between gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="text-[11px] font-medium text-muted-foreground">
                      {t("pos.dialog.payment_receipt.transaction", {
                        index: r.index,
                      })}
                    </div>
                    <div className="mt-0.5 truncate text-sm font-bold text-foreground">
                      {r.title}
                    </div>
                  </div>
                  <span className="shrink-0 text-base font-bold tabular-nums text-foreground">
                    {formatCurrency(r.amount)}
                  </span>
                </div>
                {r.description && (
                  <p className="mt-1.5 text-xs leading-relaxed text-muted-foreground">
                    {r.description}
                  </p>
                )}
              </div>
            ))}
          </div>

          {/* Totals block. */}
          <div className="space-y-2 px-1 pt-1">
            <div className="flex items-baseline justify-between text-sm">
              <span className="text-muted-foreground">
                {t("pos.dialog.payment_receipt.tendered")}
              </span>
              <span className="font-semibold tabular-nums text-foreground">
                {formatCurrency(tendered)}
              </span>
            </div>
            <div className="flex items-baseline justify-between border-t pt-2">
              <span className="text-base font-bold text-foreground">
                {t("pos.dialog.payment_receipt.total_paid")}
              </span>
              <span className="text-xl font-bold tabular-nums text-foreground">
                {formatCurrency(totalPaid)}
              </span>
            </div>
          </div>
        </div>

        {/* Post-payment actions (plan-038 capabilities 4 + 5). Kept above the
            sticky Print/Hoàn tất row so the primary flow is unchanged for the
            common case — both CTAs only appear when they're actually usable.
            "Ghi nợ phần còn lại" shows for a partially-paid order; the VAT
            invoice CTA shows once the order is paid.

            "Ghi nợ" still disables itself for a walk-in — a debt has to be owed
            by somebody the shop can bill. The invoice CTA does NOT any more
            (#1309): a hoá đơn đỏ names a buyer as free text and needs no
            customer record, and leaving the button grey was what made the
            relaxed form unreachable. */}
        {orderId && onCreateDebtPayment && remaining > 0 && (
          <div className="flex flex-col gap-2 border-t px-5 py-3 sm:flex-row">
            <div className="flex-1 [&>button]:h-11 [&>button]:w-full [&>button]:rounded-lg">
              <DebtChargeButton
                mode="remaining"
                orderId={orderId}
                customerId={customerId ?? null}
                amount={remaining}
                shopSlug={shopSlug ?? ""}
                onCreatePayment={(body) => onCreateDebtPayment(orderId, body)}
                onCharged={(paymentId) => {
                  onDebtCharged?.(paymentId, remaining, orderId);
                  onOpenChange(false);
                }}
              />
            </div>
          </div>
        )}

        {/* Red invoice (hoá đơn đỏ) — the paid receipt + a customer-name line.
            Shown whenever the workstation print bridge is available; the name
            can be typed in the dialog or hand-written on the printed slip. */}
        {orderId && workstationPrintService.enabled && (
          <div className="flex flex-col items-center gap-2 border-t px-5 py-3">
            <Button
              type="button"
              variant="outline"
              onClick={() => setRedInvoiceOpen(true)}
              className="h-11 w-full gap-1.5 rounded-lg"
            >
              <ReceiptTextIcon className="size-4" />
              {t("pos.red_invoice.cta")}
            </Button>
            {/* #1875 — say it BEFORE the dialog opens, so the cashier knows this
                order already has paper without having to start a print to find
                out. Renders nothing at zero or unknown. */}
            <RedInvoicePrintedBadge counts={redInvoiceCounts} />
          </div>
        )}

        {/* Footer — sticky 2-button row. "In biên lai" currently mirrors
            "Hoàn tất"; will route to the workstation-app print API once
            that integration ships (see file header). */}
        <div className="sticky bottom-0 z-10 grid grid-cols-2 gap-2 border-t bg-background px-5 py-3">
          <Button
            type="button"
            variant="outline"
            onClick={handlePrint}
            disabled={printing}
            className="h-11 gap-1.5 rounded-lg"
          >
            {printing ? (
              <Spinner className="size-4" />
            ) : (
              <PrinterIcon className="size-4" />
            )}
            {printLabel}
          </Button>
          <Button
            type="button"
            onClick={handleComplete}
            className="h-11 rounded-lg text-base font-semibold"
          >
            {t("pos.dialog.payment_receipt.complete")}
          </Button>
        </div>

        {orderId && (
          <RedInvoiceDialog
            open={redInvoiceOpen}
            onOpenChange={setRedInvoiceOpen}
            orderId={orderId}
            defaultCustomerName={customer?.name}
            onPrinted={refreshRedInvoiceCounts}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}
