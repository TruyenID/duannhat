/**
 * PrintDocActions — cặp nút `In gốc` / `In lại` cho MỘT chứng từ tiền, ở MỘT
 * phạm vi (cả đơn, hoặc một khách của bill chia). Ruling chủ dự án ở #2535 A7.
 *
 * ## Vì sao phải là hai nút
 *
 * Chính #2535 đã đẻ ra một lớp đơn **đã thu tiền mà chưa từng ra tờ giấy nào**
 * (thu bằng 釣銭機 xong không màn nào mở ra để in). Với những đơn đó tờ đầu tiên
 * là bản **GỐC**. Một nút "In" chung buộc phải ĐOÁN nó là gốc hay là bản sao —
 * và nó sẽ đoán sai đúng ở lớp đơn đó.
 *
 * Hai nút gọi **CÙNG một endpoint**. Chúng chỉ khác nhau ba chỗ: nhãn, nút nào
 * được bật, và **chỉ nhánh `In lại` hỏi `reprint_reason`** (bản gốc không có gì
 * để giải thích).
 *
 * | tally | In gốc | In lại |
 * |---|---|---|
 * | `printedCount === 0` | **bật** — ra bản #1, không dấu | khoá |
 * | `printedCount ≥ 1`   | khoá + hiện "đã in ×N" | **bật** — ra bản #`nextCopyNo` |
 * | `unknown`            | gộp về MỘT nút "In" trung tính | |
 *
 * Hàng `unknown` (workstation cũ, hoặc probe LAN hỏng) **không được đoán** —
 * `print-copy-state.ts` viết rõ vì sao: *một câu trả lời sai mà tự tin còn tệ
 * hơn không trả lời, vì nó là câu thu ngân hành động theo*.
 *
 * ## Số bản in là của SERVER
 *
 * Client tuyệt đối không gửi số. `printjob.Reserve` lấy `MAX(reprint_no)+1`
 * trong một `BEGIN IMMEDIATE`; hai tablet in cùng lúc mà client tự đánh số thì
 * cả hai cùng ra #1. Tally ở đây chỉ để BẬT/KHOÁ nút và để nói trước cho thu
 * ngân biết tờ sắp ra mang số mấy — nó không quyết định con số.
 *
 * Vì vậy cả `In gốc` lẫn `In lại` đều KHÔNG BAO GIỜ tự khoá vì "đã in rồi": nút
 * bị khoá ở đây là nút SAI NGHĨA, không phải nút bị cấm. Luật "warn, never
 * block" (plan-052 §4) vẫn nguyên — luôn còn đúng một nút bấm được.
 */

import { useState } from "react";
import { Button } from "@godxjp/ui";
import { PrinterIcon, ReceiptTextIcon } from "lucide-react";
import { toast } from "sonner";

import { Spinner } from "@/components/ui/spinner";
import { cn } from "@/lib/utils";
import {
  workstationPrintService,
  type PrintKindCounts,
} from "@/services/workstation-print-service";
import { printCopyState, UNKNOWN_PRINT_COPY_STATE } from "../lib/print-copy-state";
import { printErrorToast } from "../lib/print-error-message";
import { ReprintReasonDialog } from "./reprint-reason-dialog";
import { RedInvoiceDialog } from "./red-invoice-dialog";
import type { TFn } from "./order-history-shared";

/** Chứng từ tiền — loại có bộ đếm bản in và dấu 「BAN IN #N」 trên giấy.
 *  Phiếu bếp / phiếu order KHÔNG nằm ở đây: chúng không đếm bản, nên "gốc" và
 *  "in lại" không phải hai thứ khác nhau đối với chúng. */
export type MoneyDocKind = "receipt" | "red_invoice";

export function PrintDocActions({
  kind,
  orderId,
  paymentId,
  scopePaymentId,
  counts,
  customerName,
  onPrinted,
  compact,
  t,
}: {
  kind: MoneyDocKind;
  orderId: string;
  /** Nhắm một khách của bill chia. Bỏ trống = in KHÔNG nhắm ai. */
  paymentId?: string;
  /**
   * Phạm vi để ĐỌC tally — không nhất thiết trùng `paymentId`.
   *
   * Với lượt in không nhắm ai, workstation tự quyết phạm vi (`resolvePrintScope`)
   * và nói lại qua `untargeted_scope`. Trên đơn một người trả, phạm vi đó là
   * CHÍNH THANH TOÁN ĐÓ chứ không phải `order_scope` — đọc nhầm sang order_scope
   * cho ra số 0 vĩnh viễn, tức `In lại` không bao giờ sáng lên trên đúng loại
   * đơn phổ biến nhất. `undefined` = workstation chưa nói ⇒ unknown, không đoán.
   */
  scopePaymentId?: string | null;
  counts: PrintKindCounts | undefined;
  /** Prefill tên người mua cho hoá đơn đỏ. */
  customerName?: string | null;
  /** Đọc lại tally sau khi in — nếu không, thu ngân vừa nhìn tờ giấy chạy ra mà
   *  màn hình vẫn bảo chưa in bản nào. */
  onPrinted?: () => void;
  compact?: boolean;
  t: TFn;
}) {
  const [printing, setPrinting] = useState(false);
  const [reasonOpen, setReasonOpen] = useState(false);
  const [redInvoiceOpen, setRedInvoiceOpen] = useState(false);
  const [redInvoiceIsReprint, setRedInvoiceIsReprint] = useState(false);

  if (!workstationPrintService.enabled) return null;

  // Nhắm một khách thì phạm vi CHÍNH LÀ khách đó (branch ① của resolvePrintScope).
  // Không nhắm ai thì phải hỏi workstation — xem docblock của `scopePaymentId`.
  const scope = paymentId ?? scopePaymentId;
  const state =
    paymentId === undefined && scopePaymentId === undefined
      ? // Workstation chưa nói phạm vi ⇒ mọi tally đọc được đều có thể là của
        // phạm vi khác. Một nút trung tính, không hứa gì.
        UNKNOWN_PRINT_COPY_STATE
      : printCopyState(counts, scope);

  async function printReceipt(reason: string | undefined) {
    setPrinting(true);
    try {
      await workstationPrintService.printPaymentReceipt({
        orderId,
        paymentId,
        reprintReason: reason,
      });
      toast.success(t("pos.order_history.reprint_ok.receipt"));
      setReasonOpen(false);
      onPrinted?.();
    } catch (err) {
      const { level, key } = printErrorToast(err);
      toast[level](t(key));
    } finally {
      setPrinting(false);
    }
  }

  // Bản gốc: in thẳng, KHÔNG hỏi lý do. Hoá đơn đỏ vẫn phải qua hộp thoại vì nó
  // cần tên người mua — đó là nội dung của tờ giấy, không phải một câu hỏi thêm.
  function handleOriginal() {
    if (kind === "red_invoice") {
      setRedInvoiceIsReprint(false);
      setRedInvoiceOpen(true);
      return;
    }
    void printReceipt(undefined);
  }

  function handleReprint() {
    if (kind === "red_invoice") {
      setRedInvoiceIsReprint(true);
      setRedInvoiceOpen(true);
      return;
    }
    setReasonOpen(true);
  }

  const size = compact ? "h-8 gap-1.5 px-2.5 text-xs" : "h-9 gap-1.5 px-3";
  const iconSize = compact ? "size-3.5" : "size-4";
  const Icon = kind === "red_invoice" ? ReceiptTextIcon : PrinterIcon;
  const busy = printing && !reasonOpen;

  return (
    <>
      <div className="flex flex-wrap items-center gap-1.5" data-slot={`print-doc-${kind}`}>
        {state.unknown ? (
          // Không biết đã in chưa ⇒ không đặt tên cho tờ giấy. Một nút trung
          // tính; workstation vẫn đánh số đúng, chỉ là màn hình không hứa gì.
          <Button
            type="button"
            variant="outline"
            size="sm"
            data-slot={`print-${kind}-unknown`}
            onClick={handleOriginal}
            disabled={busy}
            className={cn(size)}
          >
            {busy ? <Spinner className={iconSize} /> : <Icon className={iconSize} />}
            {t(`pos.print_doc.${kind}.print`)}
          </Button>
        ) : (
          <>
            <Button
              type="button"
              variant="outline"
              size="sm"
              data-slot={`print-${kind}-original`}
              onClick={handleOriginal}
              disabled={busy || state.alreadyPrinted}
              className={cn(size)}
            >
              {busy ? <Spinner className={iconSize} /> : <Icon className={iconSize} />}
              {t(`pos.print_doc.${kind}.original`)}
            </Button>
            <Button
              type="button"
              variant="outline"
              size="sm"
              data-slot={`print-${kind}-reprint`}
              onClick={handleReprint}
              disabled={busy || !state.alreadyPrinted}
              className={cn(size)}
            >
              <Icon className={iconSize} />
              {t(`pos.print_doc.${kind}.reprint`)}
            </Button>
            {state.alreadyPrinted && (
              <span className="text-[11px] tabular-nums text-muted-foreground">
                {t("pos.red_invoice.printed_count", { count: state.printedCount })}
              </span>
            )}
          </>
        )}
      </div>

      {kind === "receipt" && (
        <ReprintReasonDialog
          open={reasonOpen}
          onOpenChange={setReasonOpen}
          copyNo={state.nextCopyNo}
          printing={printing}
          onConfirm={(reason) => void printReceipt(reason)}
          t={t}
        />
      )}

      {kind === "red_invoice" && (
        <RedInvoiceDialog
          open={redInvoiceOpen}
          onOpenChange={setRedInvoiceOpen}
          orderId={orderId}
          paymentId={paymentId}
          defaultCustomerName={customerName}
          askReprintReason={redInvoiceIsReprint}
          onPrinted={onPrinted}
        />
      )}
    </>
  );
}
