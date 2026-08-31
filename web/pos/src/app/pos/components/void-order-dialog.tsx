/**
 * VoidOrderDialog — captures void_reason and confirms before destroying
 * the order. Reason required (min 10 chars) so the audit log is useful.
 */

import { useEffect, useState } from "react";
import {
  Badge,
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
import { cn } from "@/lib/utils";
import { useTranslation } from "@/providers/app-provider";
import { Spinner } from "@/components/ui/spinner";

export interface VoidOrderDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  orderCode: string;
  onConfirm: (reason: string) => Promise<void>;
}

const MIN_REASON = 10;

export function VoidOrderDialog({
  open,
  onOpenChange,
  orderCode,
  onConfirm,
}: VoidOrderDialogProps) {
  const { t } = useTranslation();
  const [reason, setReason] = useState("");
  const [submitting, setSubmitting] = useState(false);

  const presetReasons = [
    t("pos.void_reason.customer_changed"),
    t("pos.void_reason.wrong_operation"),
    t("pos.void_reason.system_error"),
    t("pos.void_reason.customer_request"),
  ];

  useEffect(() => {
    if (!open) setReason("");
  }, [open]);

  const trimmed = reason.trim();
  // #1188 — presets are approved reasons and clear the gate at any length.
  // MIN_REASON polices junk free text, not copy we ship: EVERY ja preset here
  // is 4–7 characters against a threshold of 10, so a JP cashier tapping any
  // chip got a filled box and a dead confirm button — no preset worked at all.
  const presetChosen = presetReasons.includes(trimmed);
  const valid = presetChosen || trimmed.length >= MIN_REASON;
  const charCount = trimmed.length;
  const progress = valid ? 100 : Math.min(100, (charCount / MIN_REASON) * 100);

  async function handleConfirm() {
    if (!valid) return;
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
      <DialogContent className="flex max-h-[85vh] max-w-lg flex-col gap-0 rounded-2xl p-0 sm:max-w-lg sm:rounded-lg">
        <DialogHeader className="flex flex-row items-start gap-3 border-b px-4 py-4 sm:px-6 sm:py-4">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-destructive/10 text-destructive">
            <XCircleIcon className="size-5" />
          </span>
          <div className="min-w-0 flex-1 space-y-1">
            <div className="flex items-start gap-1.5">
              <DialogTitle className="flex flex-col items-start gap-1 text-base sm:flex-row sm:flex-wrap sm:items-center sm:gap-2 sm:text-lg">
                <span>{t("pos.dialog.void_order.title")}</span>
                <Badge variant="outline" className="font-mono text-[10px]">
                  {orderCode}
                </Badge>
              </DialogTitle>
              <HelpButton topic="void-order" className="size-7" />
            </div>
            <DialogDescription className="text-xs sm:text-sm">
              {t("pos.dialog.void_order.audit_hint", { min: String(MIN_REASON) })}
            </DialogDescription>
          </div>
        </DialogHeader>

        <div className="flex-1 space-y-4 overflow-y-auto px-4 py-4 sm:px-6 sm:py-5">
          {/* Hard warning banner — irreversible action */}
          <div className="flex items-start gap-2.5 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2.5">
            <AlertTriangleIcon className="mt-0.5 size-4 shrink-0 text-destructive" />
            <p className="text-[12px] leading-relaxed text-destructive">
              {t("pos.dialog.void_order.desc")}
            </p>
          </div>

          {/* Preset chips */}
          <div className="space-y-2">
            <div className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
              {t("pos.dialog.void_order.preset_label")}
            </div>
            <div className="flex flex-wrap gap-1.5">
              {presetReasons.map((preset) => (
                <button
                  key={preset}
                  type="button"
                  onClick={() => setReason(preset)}
                  className={cn(
                    "cursor-pointer rounded-full border-2 px-3.5 py-1.5 text-xs font-medium transition-all",
                    "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
                    trimmed === preset
                      ? "border-destructive bg-destructive/10 text-destructive shadow-sm"
                      : "border-border/60 bg-card text-muted-foreground hover:-translate-y-0.5 hover:border-destructive/40 hover:text-foreground hover:shadow-sm",
                  )}
                >
                  {preset}
                </button>
              ))}
            </div>
          </div>

          {/* Reason textarea + progress */}
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label htmlFor="void-reason">{t("pos.dialog.void_order.reason_label")}</Label>
              {/* #1188 — the counter describes the free-text gate only; a
                  chosen preset bypasses it, so showing "6 / 10+" beside an
                  enabled confirm button would contradict itself. */}
              {!presetChosen && (
                <span
                  className={cn(
                    "text-[11px] font-semibold tabular-nums",
                    valid ? "text-emerald-600 dark:text-emerald-400" : "text-destructive",
                  )}
                >
                  {charCount} / {MIN_REASON}+ {t("common.characters")}
                </span>
              )}
            </div>
            <Textarea
              id="void-reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              rows={3}
              placeholder={t("pos.dialog.void_order.reason_placeholder")}
              className="min-h-[88px] resize-none"
            />
            {/* Progress bar — fills as user types toward MIN_REASON */}
            <div className="h-1 overflow-hidden rounded-full bg-muted">
              <div
                className={cn(
                  "h-full transition-all duration-200",
                  valid ? "bg-emerald-500" : "bg-destructive",
                )}
                style={{ width: `${progress}%` }}
              />
            </div>
          </div>
        </div>

        <DialogFooter className="grid shrink-0 grid-cols-2 gap-2 border-t bg-muted/30 px-4 py-3 sm:px-6">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={submitting}
            className="h-11 rounded-lg"
          >
            {t("common.cancel")}
          </Button>
          <Button
            variant="destructive"
            onClick={handleConfirm}
            disabled={!valid || submitting}
            className="h-11 gap-1.5 rounded-lg text-base font-semibold"
          >
            {submitting && <Spinner className="size-4" />}
            {submitting ? t("pos.dialog.void_order.canceling") : t("pos.dialog.void_order.confirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
