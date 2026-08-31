"use client";

/**
 * Plan-032 T7.3 — Force-abandon dialog (Decision 8 dual-field reason).
 *
 * Manager confirms force-abandon by picking a `reason_code` (categorical,
 * required) and optionally a free-text `reason_detail`. The detail field is
 * required + min:20 ONLY when reason_code='other' — that's the gate for
 * audit-trail searchability (the other 5 codes already classify by name).
 */

import { useState } from "react";
import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Textarea,
} from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import type { ForceAbandonReasonCode } from "@/services/till-session-admin-service";

const REASON_CODES: ForceAbandonReasonCode[] = [
  "cashier_forgot_to_close",
  "cashier_unavailable_today",
  "pos_device_failure",
  "network_outage",
  "end_of_employment",
  "other",
];

export interface ForceAbandonDialogProps {
  open: boolean;
  sessionCode: string;
  isPending?: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: (input: { reason_code: ForceAbandonReasonCode; reason_detail?: string }) => void;
}

export function ForceAbandonDialog({
  open,
  sessionCode,
  isPending = false,
  onOpenChange,
  onConfirm,
}: ForceAbandonDialogProps) {
  const { t } = useTranslation();
  const [reasonCode, setReasonCode] = useState<ForceAbandonReasonCode | undefined>(undefined);
  const [reasonDetail, setReasonDetail] = useState("");

  // Reset state on close — wired through onOpenChange to keep all setState
  // calls in event handlers (avoids react-compiler's "setState in effect"
  // cascading-render warning).
  const handleOpenChange = (next: boolean) => {
    if (!next) {
      setReasonCode(undefined);
      setReasonDetail("");
    }
    onOpenChange(next);
  };

  const isOther = reasonCode === "other";
  const detailLen = reasonDetail.trim().length;
  const canSubmit =
    reasonCode !== undefined &&
    (!isOther || detailLen >= 20) &&
    reasonDetail.length <= 2000 &&
    !isPending;

  const handleSubmit = () => {
    if (!canSubmit || reasonCode === undefined) return;
    onConfirm({
      reason_code: reasonCode,
      reason_detail: reasonDetail.trim() === "" ? undefined : reasonDetail.trim(),
    });
  };

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        data-slot="force-abandon-dialog"
        className="sm:max-w-lg"
        // Block ESC + outside-click while pending so the network round-trip
        // can't be interrupted mid-flight.
        onEscapeKeyDown={(e) => isPending && e.preventDefault()}
        onPointerDownOutside={(e) => isPending && e.preventDefault()}
      >
        <DialogHeader>
          <DialogTitle>{t("till_sessions.force_abandon.title", { code: sessionCode })}</DialogTitle>
          <DialogDescription>{t("till_sessions.force_abandon.description")}</DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="force-abandon-reason-code">
              {t("till_sessions.force_abandon.reason_code_label")}
            </Label>
            <Select
              value={reasonCode ?? ""}
              onValueChange={(v) => setReasonCode(v as ForceAbandonReasonCode)}
              disabled={isPending}
            >
              <SelectTrigger id="force-abandon-reason-code">
                <SelectValue
                  placeholder={t("till_sessions.force_abandon.reason_code_placeholder")}
                />
              </SelectTrigger>
              <SelectContent>
                {REASON_CODES.map((code) => (
                  <SelectItem key={code} value={code}>
                    {t(`till_sessions.force_abandon.reason_code.${code}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="force-abandon-reason-detail">
              {t("till_sessions.force_abandon.reason_detail_label")}
            </Label>
            <Textarea
              id="force-abandon-reason-detail"
              placeholder={t("till_sessions.force_abandon.reason_detail_placeholder")}
              value={reasonDetail}
              onChange={(e) => setReasonDetail(e.target.value)}
              rows={4}
              maxLength={2000}
              aria-invalid={isOther && reasonDetail !== "" && detailLen < 20 ? true : undefined}
              disabled={isPending}
            />
            <div className="flex items-center justify-between text-xs text-muted-foreground">
              <span>{t("till_sessions.force_abandon.reason_detail_help")}</span>
              {isOther && (
                <span className={detailLen >= 20 ? "" : "text-destructive"}>{detailLen}/20</span>
              )}
            </div>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => handleOpenChange(false)} disabled={isPending}>
            {t("till_sessions.force_abandon.cancel")}
          </Button>
          <Button onClick={handleSubmit} disabled={!canSubmit}>
            {isPending && <Spinner className="mr-2 size-4" />}
            {t("till_sessions.force_abandon.confirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
