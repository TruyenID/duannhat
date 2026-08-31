/**
 * OnHoldReceiptDialog — #2049. Màn đóng đơn khi đơn rơi vào trạng thái TREO
 * (quán chưa nhận đủ tiền), thay cho `PaymentReceiptDialog`.
 *
 * Vì sao là một dialog RIÊNG chứ không phải một cờ trong PaymentReceiptDialog:
 *
 *   Cái sai lớn nhất ở đây không phải hai cái nút, mà là câu TIÊU ĐỀ. Màn kia mở
 *   bằng dấu tick xanh và chữ "Thanh toán thành công". Giữ nguyên khung đó rồi
 *   chỉ ẩn hai nút in sẽ nói với thu ngân — và với khách đứng cạnh — rằng đơn đã
 *   xong, trong khi quán vừa mới cho nợ. Trạng thái khác thì màn hình phải khác.
 *
 * Nó cố ý KHÔNG có nút "In biên lai" và KHÔNG có nút "Xuất hoá đơn đỏ". Không
 * phải disabled — KHÔNG CÓ. Một nút xám vẫn là một lời mời, và ở đây không có gì
 * để mời: workstation từ chối cứng hai đường đó bằng 409 `order_on_hold`.
 *
 * Thứ VẪN có: "Ghi nợ phần còn lại" (đường đưa đơn về trạng thái có người chịu
 * trách nhiệm) và "Hoàn tất". Sau khi ghi nợ, cha mở màn in PHIẾU GHI NỢ —
 * chứng từ ĐÚNG của trạng thái này, và nó không bị chặn.
 */

import { Button, Dialog, DialogTitle } from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { HelpButton } from "@/help/help-button";
import { WalletIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import { formatDateTime } from "@/lib/format-date";
import { useTranslation } from "@/providers/app-provider";
import { formatCurrency } from "../lib/totals";
import { onHoldReasonKey, type OrderOnHoldReason } from "../lib/on-hold";
import { DebtChargeButton } from "./debt-charge-button";
import type { PaymentBody } from "./payment-dialog";

export interface OnHoldReceiptDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Khách của đơn. Vãng lai thì null — và khi đó không ghi nợ được. */
  customer?: { name?: string | null; phone?: string | null } | null;
  /** Tổng tiền quán ĐÃ thực nhận trong phiên thu vừa rồi. */
  collected: number;
  /** Phần đơn còn thiếu. Là con số thu ngân đọc to lên, nên nó ở chỗ to nhất. */
  remaining: number;
  /** Mốc thời gian phiên thu kết thúc. */
  paidAt: Date;
  orderId: string;
  customerId?: string | null;
  shopSlug?: string;
  /** Lý do treo theo server, nếu đã biết. */
  reason?: OrderOnHoldReason | null;
  /** Trả về id payment vừa tạo (cha cần để in phiếu nợ). Bỏ trống ⇒ ẩn CTA. */
  onCreateDebtPayment?: (orderId: string, body: PaymentBody) => Promise<{ id: string }>;
  /** Bắn sau khi ghi nợ xong. Cha mở màn in phiếu nợ — KHÔNG in ở đây. */
  onDebtCharged?: (paymentId: string, amount: number, orderId: string) => void;
  /** Thu ngân bấm "Hoàn tất": cha đóng tab + dọn cache. */
  onComplete: () => void;
}

export function OnHoldReceiptDialog({
  open,
  onOpenChange,
  customer,
  collected,
  remaining,
  paidAt,
  orderId,
  customerId,
  shopSlug,
  reason,
  onCreateDebtPayment,
  onDebtCharged,
  onComplete,
}: OnHoldReceiptDialogProps) {
  const { t, locale } = useTranslation();

  const customerName = customer?.name?.trim() || null;
  const customerPhone = customer?.phone?.trim() || null;
  const hasCustomerInfo = !!(customerName || customerPhone);

  function handleComplete() {
    onComplete();
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className={cn(
          "block max-h-[90vh] w-[95vw] !max-w-md gap-0 overflow-y-auto rounded-2xl p-0",
        )}
      >
        {/* Đầu màn — hổ phách, KHÔNG phải tick xanh. Màu là thứ thu ngân đọc
            trước cả chữ, nên nó phải nói đúng ngay từ nhịp đầu: đơn này chưa
            xong. */}
        <div className="sticky top-0 z-10 flex items-start gap-3 border-b bg-amber-50 px-5 pt-5 pb-4 dark:bg-amber-950/40">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-amber-500/15 text-amber-700 dark:text-amber-300">
            <WalletIcon className="size-6" />
          </span>
          <div className="min-w-0 flex-1 space-y-0.5">
            <div className="flex items-center gap-1.5">
              <DialogTitle className="text-lg font-bold tracking-tight text-amber-900 dark:text-amber-100">
                {t("pos.on_hold.title")}
              </DialogTitle>
              <HelpButton
                topic="on-hold-receipt"
                className="size-7 text-amber-700 hover:bg-amber-500/15 hover:text-amber-900 dark:text-amber-300 dark:hover:text-amber-100"
              />
            </div>
            <p className="text-xs text-amber-800/80 tabular-nums dark:text-amber-200/80">
              {formatDateTime(paidAt, locale)}
            </p>
          </div>
        </div>

        <div className="space-y-4 px-5 py-4">
          {/* Vì sao đơn treo — nói thẳng, để thu ngân không phải đoán. */}
          <p className="rounded-xl border border-amber-300/60 bg-amber-50/60 px-4 py-3 text-sm leading-relaxed text-amber-900 dark:border-amber-500/30 dark:bg-amber-950/30 dark:text-amber-100">
            {t(onHoldReasonKey(reason))}
          </p>

          {hasCustomerInfo && (
            <div className="space-y-0.5 rounded-xl border bg-card px-4 py-3">
              <div className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                {t("pos.dialog.payment_receipt.customer")}
              </div>
              {customerName && (
                <div className="text-sm font-semibold text-foreground">{customerName}</div>
              )}
              {customerPhone && (
                <div className="text-xs text-muted-foreground">
                  {t("pos.dialog.payment_receipt.phone")}: {customerPhone}
                </div>
              )}
            </div>
          )}

          {/* Hai con số. "Còn nợ" to hơn "đã thu" — nó là con số thu ngân phải
              đọc to lên trước khi cho khách đi. */}
          <div className="space-y-2 px-1 pt-1">
            <div className="flex items-baseline justify-between text-sm">
              <span className="text-muted-foreground">{t("pos.on_hold.collected")}</span>
              <span className="font-semibold tabular-nums text-foreground">
                {formatCurrency(collected)}
              </span>
            </div>
            <div className="flex items-baseline justify-between border-t pt-2">
              <span className="text-base font-bold text-amber-900 dark:text-amber-100">
                {t("pos.on_hold.remaining")}
              </span>
              <span className="text-xl font-bold tabular-nums text-amber-900 dark:text-amber-100">
                {formatCurrency(remaining)}
              </span>
            </div>
          </div>
        </div>

        {/* KHÔNG có "In biên lai", KHÔNG có "Xuất hoá đơn đỏ" — xem đầu file. */}
        {onCreateDebtPayment && remaining > 0 && (
          <div className="border-t px-5 py-3">
            <div className="[&>button]:h-11 [&>button]:w-full [&>button]:rounded-lg">
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

        <div className="sticky bottom-0 z-10 border-t bg-background px-5 py-3">
          <Button
            type="button"
            onClick={handleComplete}
            className="h-11 w-full rounded-lg text-base font-semibold"
          >
            {t("pos.dialog.payment_receipt.complete")}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
