/**
 * AbandonShiftDialog — confirm + reason capture for cancelling a
 * mis-opened shift. Aligned with pos-web design system (dialog header
 * with icon, destructive primary, useTranslation).
 *
 * Server returns 409 SHIFT_HAS_PAYMENTS if any payment is already
 * stamped → toast + caller should hide the menu item once we observe
 * a payment.
 */

import { useEffect, useState } from "react";
import {
  Button,
  Dialog,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Label,
  Textarea,
} from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { HelpButton } from "@/help/help-button";
import { AlertTriangleIcon, XCircleIcon } from "lucide-react";
import { toast } from "sonner";
import { useTranslation } from "@/providers/app-provider";
import { useAbandonShift } from "@/hooks/api/use-till";
import { ApiError } from "@/lib/api";
import { Spinner } from "@/components/ui/spinner";

interface Props {
  shopSlug: string;
  sessionId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onAbandoned?: () => void;
}

export function AbandonShiftDialog({
  shopSlug,
  sessionId,
  open,
  onOpenChange,
  onAbandoned,
}: Props) {
  const { t } = useTranslation();
  const [reason, setReason] = useState("");
  const mut = useAbandonShift(shopSlug, sessionId);

  useEffect(() => {
    if (!open) setReason("");
  }, [open]);

  async function submit() {
    try {
      await mut.mutateAsync(reason.trim() || null);
      toast.success(t("shift.abandon.success"));
      onOpenChange(false);
      onAbandoned?.();
    } catch (err) {
      if (err instanceof ApiError && err.body?.code === "SHIFT_HAS_PAYMENTS") {
        toast.error(t("shift.abandon.error.has_payments"));
      } else {
        toast.error(
          err instanceof Error ? err.message : t("shift.abandon.error.failed"),
        );
      }
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[85vh] max-w-md flex-col gap-0 rounded-2xl p-0 sm:max-w-md sm:rounded-lg">
        <DialogHeader className="flex flex-row items-start gap-3 border-b px-4 py-4 sm:px-6">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-destructive/10 text-destructive">
            <XCircleIcon className="size-5" />
          </span>
          <div className="min-w-0 flex-1 space-y-1">
            <div className="flex items-center gap-1.5">
              <DialogTitle className="text-base sm:text-lg">
                {t("shift.abandon.title")}
              </DialogTitle>
              <HelpButton topic="abandon-shift" className="size-7" />
            </div>
            <DialogDescription className="text-xs sm:text-sm">
              {t("shift.abandon.description")}
            </DialogDescription>
          </div>
        </DialogHeader>

        <div className="flex-1 space-y-3 overflow-y-auto px-4 py-4 sm:px-6 sm:py-5">
          <div className="flex items-start gap-2.5 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2.5">
            <AlertTriangleIcon className="mt-0.5 size-4 shrink-0 text-destructive" />
            <p className="text-[12px] leading-relaxed text-destructive">
              {t("shift.abandon.description")}
            </p>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="abandon-reason" className="text-[13px] font-medium">
              {t("shift.cash_event.field.reason")}
            </Label>
            <Textarea
              id="abandon-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              rows={3}
              maxLength={2000}
              placeholder={t("shift.abandon.field.reason_placeholder")}
              className="min-h-[80px] text-[14px]"
            />
          </div>
        </div>

        <DialogFooter className="grid shrink-0 grid-cols-2 gap-2 border-t bg-muted/30 px-4 py-3 sm:px-6">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={mut.isPending}
            className="h-11"
          >
            {t("shift.abandon.action.cancel")}
          </Button>
          <Button
            variant="destructive"
            onClick={submit}
            disabled={mut.isPending}
            className="h-11 gap-1.5"
          >
            {mut.isPending && <Spinner className="size-4" />}
            {mut.isPending
              ? t("shift.abandon.action.abandoning")
              : t("shift.abandon.action.abandon")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
