/**
 * CloseTabConfirmDialog — hiện khi thu ngân bấm ✕ trên tab của một đơn mà đóng
 * xong thì POS không còn lối nào mở lại (đơn Nhanh / dine-in chưa gán bàn).
 *
 * Đây KHÔNG phải hộp thoại xoá. Đóng tab không gọi API, không huỷ, không xoá —
 * đơn ở lại `open`. Cái nó cảnh báo là mất LỐI VÀO, nên nó mang giọng nhắc
 * nhở chứ không phải giọng phá huỷ. Điều kiện bật nằm ở `lib/close-tab-policy.ts`.
 */

import {
  Badge,
  Button,
  Dialog,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { HelpButton } from "@/help/help-button";
import { AlertTriangleIcon, XIcon } from "lucide-react";
import { useTranslation } from "@/providers/app-provider";

export interface CloseTabConfirmDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Tab label to surface in the dialog (typically the order_code). */
  label: string;
  /**
   * Đồng bộ, không phải `Promise<void>`: đóng tab chỉ là state cục bộ. Một
   * chữ ký async ở đây mời gọi người sau nhét lại lời gọi mạng vào chính chỗ
   * vừa được gỡ ra.
   */
  onConfirm: () => void;
}

export function CloseTabConfirmDialog({
  open,
  onOpenChange,
  label,
  onConfirm,
}: CloseTabConfirmDialogProps) {
  const { t } = useTranslation();

  function handleConfirm() {
    onConfirm();
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md p-0">
        <DialogHeader className="flex flex-row items-start gap-3 border-b px-6 py-4">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-500">
            <XIcon className="size-5" />
          </span>
          <div className="flex-1 space-y-1">
            <div className="flex items-start gap-1.5">
              <DialogTitle className="flex flex-wrap items-center gap-2">
                {t("pos.dialog.close_tab.title")}
                <Badge variant="outline" className="font-mono text-[10px]">
                  {label}
                </Badge>
              </DialogTitle>
              <HelpButton topic="close-tab" className="size-7" />
            </div>
            <DialogDescription>
              {t("pos.dialog.close_tab.recommend")}
            </DialogDescription>
          </div>
        </DialogHeader>

        <div className="space-y-3 px-6 py-5">
          <div className="flex items-start gap-2.5 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 dark:border-amber-500/30 dark:bg-amber-500/5">
            <AlertTriangleIcon className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-500" />
            <p className="text-[12px] leading-relaxed text-amber-900 dark:text-amber-200">
              {t("pos.dialog.close_tab.desc", { label })}
            </p>
          </div>
        </div>

        <DialogFooter className="border-t bg-muted/30 px-6 py-3">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            style={{ padding: "12px 25px", borderRadius: "10px" }}
          >
            {t("common.cancel")}
          </Button>
          <Button
            onClick={handleConfirm}
            style={{ padding: "12px 35px", borderRadius: "10px" }}
          >
            {t("pos.dialog.close_tab.confirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
