/**
 * ReopenOrderDialog — #2479. Nhận lý do trước khi đưa bill từ `checkout` về
 * `open`.
 *
 * CỐ Ý **không** tái dụng `VoidOrderDialog`: nó là hộp thoại phá huỷ — viền đỏ,
 * biểu tượng cảnh báo, câu chữ "đơn sẽ bị huỷ, không hoàn tác được". Mượn nó cho
 * một thao tác sửa nhầm là nói dối thu ngân về mức nghiêm trọng, và người đọc
 * một cảnh báo sai ngữ cảnh sẽ học cách bấm qua nó — kể cả lần nó nói thật.
 *
 * Ngưỡng lý do 10 ký tự dùng chung với void, cùng lý do: chặn free text rác chứ
 * không chặn người dùng. Lý do là thứ duy nhất giữ cho reopen và void cân nhau
 * về dấu vết; thiếu nó, mở lại thành đường sửa bill rẻ hơn mà không ai thấy.
 */
"use client";

import { Dialog } from "@godxjp/ui";
import { useEffect, useState } from "react";
import { DialogContent } from "@/components/ui/dialog";
import { Spinner } from "@/components/ui/spinner";
import { useTranslation } from "@/providers/app-provider";

export interface ReopenOrderDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  orderCode: string;
  onConfirm: (reason: string) => Promise<void>;
}

const MIN_REASON = 10;

export function ReopenOrderDialog({
  open,
  onOpenChange,
  orderCode,
  onConfirm,
}: ReopenOrderDialogProps) {
  const { t } = useTranslation();
  const [reason, setReason] = useState("");
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) {
      setReason("");
      setSubmitting(false);
    }
  }, [open]);

  const trimmed = reason.trim();
  const valid = trimmed.length >= MIN_REASON;

  async function handleConfirm() {
    if (!valid || submitting) {
      return;
    }
    setSubmitting(true);
    try {
      await onConfirm(trimmed);
      onOpenChange(false);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <div className="space-y-4">
          <div className="space-y-1">
            <h2 className="text-base font-medium text-foreground">
              {t("pos.dialog.reopen_order.title")}
            </h2>
            <p className="text-xs text-muted-foreground">{orderCode}</p>
          </div>

          <p className="text-sm text-muted-foreground">
            {t("pos.dialog.reopen_order.desc")}
          </p>

          <textarea
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            rows={3}
            placeholder={t("pos.dialog.reopen_order.reason")}
            className="w-full rounded-md border border-border bg-background p-2 text-sm"
          />

          <div className="flex justify-end gap-2">
            <button
              type="button"
              onClick={() => onOpenChange(false)}
              className="cursor-pointer rounded-md px-3 py-2 text-sm text-muted-foreground hover:bg-muted"
            >
              {t("common.cancel")}
            </button>
            <button
              type="button"
              onClick={handleConfirm}
              disabled={!valid || submitting}
              className="flex h-11 cursor-pointer items-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
            >
              {submitting && <Spinner className="size-4" />}
              {t("pos.dialog.reopen_order.confirm")}
            </button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
