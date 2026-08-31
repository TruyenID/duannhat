/**
 * RedInvoiceDialog — "In hoá đơn đỏ".
 *
 * The red invoice slip: the same slip as the paid receipt (items, totals, cash
 * tendered/change) plus a customer-name line. The name is optional — when blank
 * the workstation prints a blank underline for the cashier to hand-write it.
 *
 * #1779 — this prints DIRECTLY via /api/lan/print/red-invoice and writes NO DB
 * record. It is now the only red-invoice path; the formal customer_invoices
 * flow was removed. `paymentId` targets one split payer when chia bill.
 */

import { useEffect, useState } from "react";
import {
  Button,
  Dialog,
  DialogTitle,
  Input,
} from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { HelpButton } from "@/help/help-button";
import { AlertTriangleIcon, PrinterIcon } from "lucide-react";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { Spinner } from "@/components/ui/spinner";
import { useTranslation } from "@/providers/app-provider";
import {
  workstationPrintService,
  type PrintKindCounts,
} from "@/services/workstation-print-service";
import { printCopyState, UNKNOWN_PRINT_COPY_STATE } from "../lib/print-copy-state";
import { isOnHoldError } from "../lib/on-hold";

export interface RedInvoiceDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  orderId: string;
  /** Prefill from the order's customer name, if known. */
  defaultCustomerName?: string | null;
  /**
   * #1779 — when chia bill, target ONE split payer so the slip carries only
   * that guest's items + amount. Absent → the whole order.
   */
  paymentId?: string;
  /**
   * #2535 A7 — mở từ nhánh IN LẠI, nên hộp này hỏi thêm lý do.
   *
   * **Hỏi, KHÔNG bắt buộc** (plan-052 §4 / #1166): lý do rỗng vẫn in đúng tờ đó,
   * ledger chỉ ghi lại là không ai nêu lý do. Nhánh IN GỐC không hỏi — bản gốc
   * không có gì để giải thích, và bắt nó giải thích sẽ dạy thu ngân bấm qua ô
   * này mà không đọc, đúng lúc lần sau nó thật sự cần được đọc.
   */
  askReprintReason?: boolean;
  /**
   * #1875 — fired after a sheet actually printed, so the caller's "đã in ×N"
   * badge reflects it. A badge that only refreshes on remount would tell the
   * cashier a sheet they just watched come out does not exist.
   */
  onPrinted?: () => void;
}

export function RedInvoiceDialog({
  open,
  onOpenChange,
  orderId,
  defaultCustomerName,
  paymentId,
  askReprintReason,
  onPrinted,
}: RedInvoiceDialogProps) {
  const { t } = useTranslation();
  // Seed from the order's customer name on mount. The dialog is mounted per
  // receipt (one order), so a lazy initial value is correct and avoids a
  // set-state-in-effect; the cashier can freely edit or clear it.
  const [name, setName] = useState(() => defaultCustomerName?.trim() ?? "");
  const [printing, setPrinting] = useState(false);
  const [reprintReason, setReprintReason] = useState("");
  // #1875 — how many red invoices THIS scope already has.
  //
  // Fetched once when the dialog opens, deliberately NOT polled: a 30s poll
  // re-renders the page under an open dialog and has already wiped a cashier's
  // input once in this codebase. One read at open is also the honest window —
  // the number cannot change while this dialog is the thing printing.
  const [counts, setCounts] = useState<PrintKindCounts | undefined>(undefined);
  // #2535 A7 — phạm vi mà một lượt in KHÔNG NHẮM AI rơi vào, do WORKSTATION nói.
  // `undefined` = nó không nói (bản cũ, hoặc probe hỏng) ⇒ không biết.
  const [untargetedScope, setUntargetedScope] = useState<string | null | undefined>(
    undefined,
  );

  useEffect(() => {
    if (!open || !workstationPrintService.enabled) return;
    let cancelled = false;
    setCounts(undefined);
    setUntargetedScope(undefined);
    workstationPrintService
      .getPrintStatus({ orderId })
      .then((status) => {
        if (cancelled) return;
        setCounts(status.red_invoice);
        setUntargetedScope(
          status.untargeted_scope ? status.untargeted_scope.payment_id : undefined,
        );
      })
      // A probe failure leaves `counts` undefined, which reads as UNKNOWN and
      // shows nothing. Never a toast: the cashier came here to print, and a
      // failed status probe is not a reason to put an error in front of them.
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [open, orderId]);

  /* Lượt in KHÔNG nhắm ai KHÔNG tính vào `order_scope` trên đơn một người trả:
     `resolvePrintScope` branch ② đặt nó vào CHÍNH thanh toán đó. Đọc thẳng
     `order_scope` ở đây cho ra 0 vĩnh viễn, nên dòng cảnh báo "đã in ×N, tờ sau
     mang dấu #N+1" không bao giờ hiện — kể cả khi thu ngân vừa bấm đúng nút
     `In lại` đang sáng lên vì phạm vi đó ĐÃ có giấy. Cùng một lỗi mà #2535 A7
     đã bịt cho cặp nút; hộp thoại thì vẫn còn.

     Workstation không nói phạm vi ⇒ UNKNOWN, im lặng. Không đoán: một câu trả
     lời sai mà tự tin còn tệ hơn không trả lời (`print-copy-state.ts`). */
  const printState =
    paymentId === undefined && untargetedScope === undefined
      ? UNKNOWN_PRINT_COPY_STATE
      : printCopyState(counts, paymentId ?? untargetedScope);

  async function handlePrint() {
    if (!workstationPrintService.enabled) {
      onOpenChange(false);
      return;
    }
    setPrinting(true);
    try {
      await workstationPrintService.printRedInvoice({
        orderId,
        customerName: name.trim(),
        paymentId,
        // Rỗng là một câu trả lời hợp lệ, và nó đi tới server y như một câu có
        // chữ — Cloud tự suy ra `warned_without_reason` lúc nhận.
        reprintReason: askReprintReason ? reprintReason.trim() : undefined,
      });
      toast.success(t("pos.red_invoice.printed"));
      onPrinted?.();
      onOpenChange(false);
    } catch (err) {
      // #2049 — đơn treo thì hoá đơn đỏ không được phát. Đường này tới được từ
      // màn lịch sử với một cờ đã cũ, nên lời từ chối của server phải dịch được
      // ra chữ thu ngân hiểu, thay vì rơi vào "lỗi in" chung chung.
      if (isOnHoldError(err)) {
        toast.error(t("pos.on_hold.print_blocked"));
      } else if (err instanceof ApiError && err.status === 503) {
        toast.error(t("pos.kitchen.no_printer"));
      } else {
        toast.error(t("pos.red_invoice.error"));
      }
    } finally {
      setPrinting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        data-slot="red-invoice-dialog"
        className="w-[95vw] !max-w-sm gap-0 rounded-2xl p-0"
      >
        <div className="flex items-center gap-1.5 border-b px-5 pb-4 pt-5">
          <DialogTitle className="text-lg font-bold tracking-tight">
            {t("pos.red_invoice.title")}
          </DialogTitle>
          <HelpButton topic="red-invoice" className="size-7" />
        </div>

        <div className="space-y-2 px-5 py-4">
          <label
            htmlFor="red-invoice-customer"
            className="text-sm font-medium text-foreground"
          >
            {t("pos.red_invoice.customer_name")}
          </label>
          <Input
            id="red-invoice-customer"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder={t("pos.red_invoice.customer_placeholder")}
            className="h-11"
            autoFocus
          />
          <p className="text-xs text-muted-foreground">
            {t("pos.red_invoice.hint")}
          </p>

          {/* #1875 — WARN, never block. The owner ruling of 2026-07-28 is that
              the system may never refuse a print: a cashier at the counter with
              a waiting customer has to be able to reprint, full stop. The mark
              on the paper is what replaced the refusal, so this line only tells
              the cashier what that paper will say. The button below stays
              enabled no matter what is rendered here. */}
          {printState.alreadyPrinted && (
            <div
              data-slot="red-invoice-reprint-warning"
              className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-500/30 dark:bg-amber-500/5"
            >
              <AlertTriangleIcon className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-500" />
              <p className="text-[11px] leading-relaxed text-amber-900 dark:text-amber-200">
                {t("pos.red_invoice.already_printed", {
                  count: String(printState.printedCount),
                  next: String(printState.nextCopyNo),
                })}
              </p>
            </div>
          )}
          {/* #2535 A7 — chỉ nhánh IN LẠI mới hỏi. Không `disabled` theo ô này:
              luật "warn, never block" nghĩa là ô trống vẫn in được. */}
          {askReprintReason && (
            <div className="space-y-1.5 pt-1">
              <label
                htmlFor="red-invoice-reprint-reason"
                className="text-sm font-medium text-foreground"
              >
                {t("pos.reprint_reason.label")}
              </label>
              <Input
                id="red-invoice-reprint-reason"
                value={reprintReason}
                maxLength={256}
                onChange={(e) => setReprintReason(e.target.value)}
                placeholder={t("pos.reprint_reason.placeholder")}
                className="h-11"
              />
              <p className="text-xs text-muted-foreground">
                {t("pos.reprint_reason.hint")}
              </p>
            </div>
          )}
        </div>

        <div className="grid grid-cols-2 gap-2 border-t px-5 py-3">
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            className="h-11 rounded-lg"
          >
            {t("common.cancel")}
          </Button>
          <Button
            type="button"
            onClick={handlePrint}
            disabled={printing}
            className="h-11 gap-1.5 rounded-lg"
          >
            {printing ? (
              <Spinner className="size-4" />
            ) : (
              <PrinterIcon className="size-4" />
            )}
            {t("pos.red_invoice.print")}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
