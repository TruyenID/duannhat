/**
 * ReprintReasonDialog — hỏi vì sao in lại. **Hỏi, KHÔNG bắt buộc.**
 *
 * plan-052 §4 / #1166: không đường in nào được phép TỪ CHỐI in. Thu ngân đang
 * đứng trước khách chờ, và một ô nhập bắt buộc ở đó chỉ tạo ra hai thứ — hoặc
 * một hàng người chờ, hoặc một sổ nhật ký đầy chữ "x". Nên nút "In" ở đây luôn
 * bấm được kể cả khi ô trống; workstation nhận lý do rỗng, in đúng tờ giấy đó,
 * và Cloud tự suy ra `warned_without_reason` lúc nhận.
 *
 * Chỉ nhánh IN LẠI mới mở hộp này. Bản gốc không có gì để giải thích, và bắt nó
 * giải thích sẽ dạy thu ngân bấm qua hộp thoại này mà không đọc — đúng lúc mà
 * lần sau nó thật sự cần được đọc.
 */

import { useState } from "react";
import { Button, Dialog, DialogTitle, Input } from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { PrinterIcon } from "lucide-react";

import { Spinner } from "@/components/ui/spinner";
import type { TFn } from "./order-history-shared";

/** Giới hạn của workstation (`reprint_reason too long` → 400). Chặn ở đây để
 *  thu ngân biết ngay, thay vì biết sau khi bấm In. */
const MAX_REASON = 256;

export function ReprintReasonDialog({
  open,
  onOpenChange,
  /** Tờ giấy sắp ra sẽ mang số này — nói trước, đừng để nó là bất ngờ. */
  copyNo,
  printing,
  onConfirm,
  t,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  copyNo: number;
  printing: boolean;
  onConfirm: (reason: string) => void;
  t: TFn;
}) {
  const [reason, setReason] = useState("");

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) setReason("");
        onOpenChange(next);
      }}
    >
      <DialogContent
        data-slot="reprint-reason-dialog"
        className="w-[95vw] !max-w-sm gap-0 rounded-2xl p-0"
      >
        <div className="border-b px-5 pb-4 pt-5">
          <DialogTitle className="text-lg font-bold tracking-tight">
            {t("pos.reprint_reason.title")}
          </DialogTitle>
          <p className="mt-1 text-xs text-muted-foreground">
            {t("pos.reprint_reason.copy_no", { no: copyNo })}
          </p>
        </div>

        <div className="space-y-2 px-5 py-4">
          <label htmlFor="reprint-reason" className="text-sm font-medium text-foreground">
            {t("pos.reprint_reason.label")}
          </label>
          <Input
            id="reprint-reason"
            value={reason}
            maxLength={MAX_REASON}
            onChange={(e) => setReason(e.target.value)}
            placeholder={t("pos.reprint_reason.placeholder")}
            className="h-11"
            autoFocus
          />
          <p className="text-xs text-muted-foreground">{t("pos.reprint_reason.hint")}</p>
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
          {/* Không `disabled={!reason}`. Đó là cả điểm của hộp thoại này. */}
          <Button
            type="button"
            onClick={() => onConfirm(reason.trim())}
            disabled={printing}
            className="h-11 gap-1.5 rounded-lg"
          >
            {printing ? <Spinner className="size-4" /> : <PrinterIcon className="size-4" />}
            {t("pos.reprint_reason.confirm")}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
