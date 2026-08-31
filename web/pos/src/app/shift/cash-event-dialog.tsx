/**
 * CashEventDialog — record 入金/出金 against the open shift.
 *
 * Aligned with pos-web design system (Dialog primitives from @godxjp/ui,
 * tokens, useTranslation). Invalidates the reconciliation query on success
 * via useCashEvent hook so the close screen reflects the change.
 */

import { useEffect, useState } from "react";
import {
  Button,
  Dialog,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Textarea,
} from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { HelpButton } from "@/help/help-button";
import { ArrowDownToLineIcon, ArrowUpFromLineIcon, BanknoteIcon } from "lucide-react";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/providers/app-provider";
import { useCashEvent } from "@/hooks/api/use-till";
import type { TillCashEventType } from "@/services/till-service";
import { Spinner } from "@/components/ui/spinner";

interface Props {
  shopSlug: string;
  sessionId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function CashEventDialog({
  shopSlug,
  sessionId,
  open,
  onOpenChange,
}: Props) {
  const { t } = useTranslation();
  const [eventType, setEventType] = useState<TillCashEventType>("paid_in");
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  const [referenceNo, setReferenceNo] = useState("");

  const mut = useCashEvent(shopSlug, sessionId);

  // Reset on close so the next open starts fresh.
  useEffect(() => {
    if (!open) {
      setEventType("paid_in");
      setAmount("");
      setReason("");
      setReferenceNo("");
    }
  }, [open]);

  const isPaidIn = eventType === "paid_in";
  const trimmedAmount = amount.replace(/[^\d.]/g, "");
  const numericAmount = Number(trimmedAmount);
  const amountValid = Number.isFinite(numericAmount) && numericAmount > 0;
  const reasonValid = reason.trim().length > 0;
  const canSubmit = amountValid && reasonValid && !mut.isPending;

  async function submit() {
    if (!amountValid) {
      toast.error(t("shift.cash_event.error.amount"));
      return;
    }
    if (!reasonValid) {
      toast.error(t("shift.cash_event.error.reason"));
      return;
    }
    try {
      await mut.mutateAsync({
        event_type: eventType,
        amount: numericAmount,
        reason: reason.trim(),
        reference_no: referenceNo.trim() || null,
      });
      toast.success(
        isPaidIn
          ? t("shift.cash_event.success.paid_in")
          : t("shift.cash_event.success.paid_out"),
      );
      onOpenChange(false);
    } catch (e) {
      toast.error(
        e instanceof Error ? e.message : t("shift.cash_event.error.failed"),
      );
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[85vh] max-w-md flex-col gap-0 rounded-2xl p-0 sm:max-w-md sm:rounded-lg">
        <DialogHeader className="flex flex-row items-start gap-3 border-b px-4 py-4 sm:px-6">
          <span
            className={cn(
              "flex size-10 shrink-0 items-center justify-center rounded-full",
              isPaidIn
                ? "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                : "bg-amber-500/10 text-amber-600 dark:text-amber-400",
            )}
          >
            <BanknoteIcon className="size-5" />
          </span>
          <div className="min-w-0 flex-1 space-y-1">
            <div className="flex items-center gap-1.5">
              <DialogTitle className="text-base sm:text-lg">
                {t("shift.cash_event.title")}
              </DialogTitle>
              <HelpButton topic="cash-event" className="size-7" />
            </div>
            <DialogDescription className="text-xs sm:text-sm">
              {t("shift.cash_event.description")}
            </DialogDescription>
          </div>
        </DialogHeader>

        <div className="flex-1 space-y-4 overflow-y-auto px-4 py-4 sm:px-6 sm:py-5">
          {/* Event type */}
          <div className="space-y-1.5">
            <Label htmlFor="cash-event-type" className="text-[13px] font-medium">
              {t("shift.cash_event.field.type")}
            </Label>
            <Select
              value={eventType}
              onValueChange={(v) => setEventType(v as TillCashEventType)}
            >
              <SelectTrigger id="cash-event-type" className="h-11">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="paid_in">
                  <span className="flex items-center gap-2">
                    <ArrowDownToLineIcon className="size-4 text-emerald-600" />
                    {t("shift.cash_event.type.paid_in")}
                  </span>
                </SelectItem>
                <SelectItem value="paid_out">
                  <span className="flex items-center gap-2">
                    <ArrowUpFromLineIcon className="size-4 text-amber-600" />
                    {t("shift.cash_event.type.paid_out")}
                  </span>
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          {/* Amount */}
          <div className="space-y-1.5">
            <Label htmlFor="cash-event-amount" className="text-[13px] font-medium">
              {t("shift.cash_event.field.amount")}
            </Label>
            <Input
              id="cash-event-amount"
              type="text"
              inputMode="numeric"
              value={amount}
              onChange={(e) => setAmount(e.target.value.replace(/[^\d.]/g, ""))}
              className="h-11 text-right text-[16px] font-semibold tabular-nums"
            />
          </div>

          {/* Reason */}
          <div className="space-y-1.5">
            <Label htmlFor="cash-event-reason" className="text-[13px] font-medium">
              {t("shift.cash_event.field.reason")}
            </Label>
            <Textarea
              id="cash-event-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              rows={2}
              maxLength={2000}
              placeholder={t("shift.cash_event.reason_placeholder")}
              className="min-h-[64px] text-[14px]"
            />
          </div>

          {/* Reference (optional) */}
          <div className="space-y-1.5">
            <Label
              htmlFor="cash-event-reference"
              className="text-[13px] font-medium"
            >
              {t("shift.cash_event.field.reference")}{" "}
              <span className="font-normal text-muted-foreground">
                {t("shift.cash_event.field.reference_optional")}
              </span>
            </Label>
            <Input
              id="cash-event-reference"
              value={referenceNo}
              onChange={(e) => setReferenceNo(e.target.value)}
              maxLength={100}
              placeholder={t("shift.cash_event.reference_placeholder")}
              className="h-11 text-[14px]"
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
            {t("shift.cash_event.action.cancel")}
          </Button>
          <Button
            onClick={submit}
            disabled={!canSubmit}
            className="h-11 gap-1.5"
          >
            {mut.isPending && <Spinner className="size-4" />}
            {mut.isPending
              ? t("shift.cash_event.action.saving")
              : t("shift.cash_event.action.save")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
