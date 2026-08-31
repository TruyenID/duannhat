/**
 * ClosingReceipt — #2049. Chọn màn ĐÓNG ĐƠN cho một phiên thu vừa xong.
 *
 * Có đúng hai kết cục, và chúng là hai màn hình khác nhau chứ không phải một
 * màn có cờ:
 *
 *   thu đủ   `PaymentReceiptDialog` — tick xanh, "Thanh toán thành công",
 *            có In biên lai + Xuất hoá đơn đỏ.
 *   còn nợ   `OnHoldReceiptDialog`  — hổ phách, "Đơn treo", KHÔNG có nút in nào.
 *
 * Việc rẽ nhánh nằm ở đây chứ không ở `page.tsx` vì hai lý do, và lý do thứ hai
 * mới là lý do thật:
 *
 *   1. `page.tsx` có TRẦN DÒNG chỉ-được-giảm (`page-size-budget.arch.test.ts`),
 *      và hai khối mount gần như giống hệt nhau là đúng loại thứ file đó không
 *      nên chứa;
 *   2. quan trọng hơn — nhánh này là một LUẬT ("đơn treo không có đường in"),
 *      và luật thì phải có một chỗ ở. Rải hai khối `&&` trong một file 950 dòng
 *      là cách mà lần sau ai đó thêm màn thứ ba rồi chỉ sửa một nhánh.
 */

import { PaymentReceiptDialog } from "./payment-receipt-dialog";
import { OnHoldReceiptDialog } from "./on-hold-receipt-dialog";
import type { PaymentReceiptState } from "../hooks/use-receipt-flow";
import type { PaymentBody } from "./payment-dialog";

export interface ClosingReceiptProps {
  /** `null` ⇒ không có phiên thu nào vừa xong; component không vẽ gì. */
  receipt: PaymentReceiptState | null;
  shopSlug: string;
  onCreateDebtPayment: (orderId: string, body: PaymentBody) => Promise<{ id: string }>;
  onDebtCharged: (paymentId: string, amount: number, orderId: string) => void;
  /** Thu ngân đóng màn: đóng tab + dọn state (useReceiptFlow sở hữu). */
  onComplete: () => void;
}

export function ClosingReceipt({
  receipt,
  shopSlug,
  onCreateDebtPayment,
  onDebtCharged,
  onComplete,
}: ClosingReceiptProps) {
  if (!receipt) return null;

  // Đóng bằng nút X / Esc cũng phải đi qua đúng đường "hoàn tất" như bấm nút,
  // nếu không tab đơn sẽ ở lại vĩnh viễn trên thanh tab.
  const handleOpenChange = (open: boolean) => {
    if (!open) onComplete();
  };

  if (receipt.onHold) {
    return (
      <OnHoldReceiptDialog
        open
        onOpenChange={handleOpenChange}
        customer={receipt.customer}
        collected={receipt.collected}
        remaining={receipt.remaining}
        paidAt={receipt.paidAt}
        orderId={receipt.orderId}
        customerId={receipt.customerId}
        shopSlug={shopSlug}
        onCreateDebtPayment={onCreateDebtPayment}
        onDebtCharged={onDebtCharged}
        onComplete={onComplete}
      />
    );
  }

  return (
    <PaymentReceiptDialog
      open
      onOpenChange={handleOpenChange}
      customer={receipt.customer}
      receipts={receipt.receipts}
      totalPaid={receipt.totalPaid}
      tendered={receipt.tendered}
      paidAt={receipt.paidAt}
      orderId={receipt.orderId}
      customerId={receipt.customerId}
      remaining={receipt.remaining}
      shopSlug={shopSlug}
      onCreateDebtPayment={onCreateDebtPayment}
      onDebtCharged={onDebtCharged}
      onComplete={onComplete}
    />
  );
}
