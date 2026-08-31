/**
 * PrintResultDialog — confirmation screen for the two flows that used to push
 * paper out on their own: recording a debt, and issuing a VAT invoice.
 *
 * Recording and printing are separate decisions. The customer may not want a
 * slip, the printer may not even be reachable, and every reprint is written to
 * `print_history` + the audit log — so a slip that appears unasked is not free.
 * PaymentReceiptDialog already got this right (it shows the receipt and offers
 * "In biên lai"); this brings debt + invoice in line.
 *
 * The write has ALREADY succeeded by the time this opens. Nothing here can fail
 * in a way that loses money — a print error is reported and the dialog stays
 * open so the cashier can retry or just walk away.
 */

import { useState } from "react";
import { Button, Dialog, DialogTitle } from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { HelpButton } from "@/help/help-button";
import { CheckCircle2Icon, PrinterIcon } from "lucide-react";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import { Spinner } from "@/components/ui/spinner";
import { workstationPrintService } from "@/services/workstation-print-service";

/** What was just created, and therefore which slip the Print button fires.
 *  #1779 — the VAT-invoice target was removed: the red invoice now prints
 *  directly (no DB record), so nothing "issues then offers to print" any more. */
export type PrintResultTarget = {
  kind: "debt";
  orderId: string;
  paymentId: string;
  amount: number;
};

export interface PrintResultDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  target: PrintResultTarget;
  /** Headline amount / invoice number, already formatted by the caller. */
  detail: string;
  onDone: () => void;
}

export function PrintResultDialog({
  open,
  onOpenChange,
  target,
  detail,
  onDone,
}: PrintResultDialogProps) {
  const { t } = useTranslation();
  const [printing, setPrinting] = useState(false);
  const [printCount, setPrintCount] = useState(0);

  const title = t("pos.debt.charged_title");
  const baseLabel = t("pos.debt.print_slip");

  // Same convention as PaymentReceiptDialog: "In" → "In ✓" → "In #3".
  const printLabel =
    printCount === 0
      ? baseLabel
      : printCount === 1
        ? `${baseLabel} ✓`
        : `${baseLabel} #${printCount + 1}`;

  async function handlePrint() {
    setPrinting(true);
    try {
      // First click on a freshly-created row is the original print; anything
      // after that is a reprint, which the workstation records as such.
      const reprintReason = printCount === 0 ? "auto" : "manual reprint";

      await workstationPrintService.printDebtSlip({
        orderId: target.orderId,
        paymentId: target.paymentId,
        reprintReason,
      });

      setPrintCount((n) => n + 1);
    } catch (err) {
      if (err instanceof ApiError && err.status === 503) {
        toast.warning(t("pos.kitchen.no_printer"));
      } else {
        toast.error(t("pos.kitchen.fire_failed"));
      }
    } finally {
      setPrinting(false);
    }
  }

  function handleDone() {
    onOpenChange(false);
    onDone();
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        data-slot="print-result-dialog"
        className="max-w-sm gap-0 p-0"
      >
        <div className="flex flex-col items-center gap-2 px-5 py-6 text-center">
          <CheckCircle2Icon className="size-10 text-emerald-600" />
          <div className="flex items-center gap-1.5">
            <DialogTitle className="text-lg font-semibold">{title}</DialogTitle>
            <HelpButton topic="print-result" className="size-7" />
          </div>
          <p className="text-2xl font-bold tabular-nums text-foreground">
            {detail}
          </p>
        </div>

        <div className="grid grid-cols-2 gap-2 border-t px-5 py-3">
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
            onClick={handleDone}
            className="h-11 rounded-lg text-base font-semibold"
          >
            {t("pos.dialog.payment_receipt.complete")}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
