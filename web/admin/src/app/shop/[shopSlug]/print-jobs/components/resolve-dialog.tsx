"use client";

/**
 * "A person dealt with this job" — plan-052 M2 / T2.2 (#1166).
 *
 * Read the title of this file twice: it is NOT a reprint dialog and cannot
 * become one. The enum has exactly two values — the paper exists (produced
 * some other way), or the paper is no longer needed — and neither of them
 * sends bytes anywhere. Reprinting a money document is an accounting event
 * that must consume 「Bản in #N」 and pass the reprint gate, so it lives behind
 * `POST /api/v1/pos/print-jobs/reprint-authorization` (P-10 / RISKS PR1). The
 * dialog says so out loud, because a manager staring at an unprinted receipt
 * will otherwise assume this button prints it.
 *
 * Error surfaces, all rendered INSIDE the dialog (never as a toast that
 * disappears while the manager is still reading):
 *   409  the ledger already records paper → nothing to resolve, submit hidden
 *   403  manager-only → a cashier sees the ledger but may not close a line
 *   422  reason missing / too short → the audit field is the point
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
  RadioGroup,
  RadioGroupItem,
  Textarea,
} from "@godxjp/ui";
import { AlertTriangle, Info } from "lucide-react";
import { useTranslation } from "@/providers/app-provider";
import { describeResolveError, validateResolveReason } from "@/lib/print-jobs";
import type { PrintJobDetail, PrintJobResolutionKind } from "@/types/models/PrintJob";

export interface ResolveDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  job: PrintJobDetail | null;
  isPending: boolean;
  /** Rejects with the raw ApiError so the dialog can render 409 / 403 / 422. */
  onSubmit: (input: { resolution: PrintJobResolutionKind; reason: string }) => Promise<unknown>;
}

export function ResolvePrintJobDialog({
  open,
  onOpenChange,
  job,
  isPending,
  onSubmit,
}: ResolveDialogProps) {
  const { t } = useTranslation();

  const [resolution, setResolution] = useState<PrintJobResolutionKind>("printed_by_hand");
  const [reason, setReason] = useState("");
  const [localError, setLocalError] = useState<string | null>(null);
  const [serverError, setServerError] = useState<ReturnType<typeof describeResolveError> | null>(null);

  /**
   * Reset on the way OUT, not in an effect on the way in. A 409 that stayed on
   * screen from the previous job would be a lie about this one, and an effect
   * that fires after paint would show it for a frame.
   */
  function handleOpenChange(next: boolean) {
    if (!next) {
      setResolution("printed_by_hand");
      setReason("");
      setLocalError(null);
      setServerError(null);
    }
    onOpenChange(next);
  }

  const isMoney = job?.is_money_document ?? false;
  const blocked = serverError?.terminal ?? false;

  async function handleSubmit() {
    const problem = validateResolveReason(reason);
    setLocalError(problem);
    setServerError(null);
    if (problem) return;

    try {
      await onSubmit({ resolution, reason: reason.trim() });
      handleOpenChange(false);
    } catch (error) {
      setServerError(describeResolveError(error));
    }
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-lg" data-slot="print-job-resolve-dialog">
        <DialogHeader>
          <DialogTitle>{t("print_jobs.resolve.title")}</DialogTitle>
          <DialogDescription>{t("print_jobs.resolve.description")}</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-3">
          {/* The sentence that stops this being read as a reprint button. */}
          <div className="flex items-start gap-2 rounded-md border border-border bg-muted px-3 py-2 text-[11px] text-muted-foreground">
            <Info className="mt-0.5 size-3.5 shrink-0" />
            <span>
              {isMoney
                ? t("print_jobs.resolve.not_a_reprint_money")
                : t("print_jobs.resolve.not_a_reprint")}
            </span>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label className="text-xs">{t("print_jobs.resolve.kind_label")}</Label>
            <RadioGroup
              value={resolution}
              onValueChange={(value) => setResolution(value as PrintJobResolutionKind)}
              className="gap-2"
            >
              <label className="flex cursor-pointer items-start gap-2 rounded-md border border-border px-3 py-2">
                <RadioGroupItem value="printed_by_hand" className="mt-0.5" />
                <span className="flex flex-col gap-0.5">
                  <span className="text-xs font-medium">
                    {t("print_jobs.resolution.printed_by_hand")}
                  </span>
                  <span className="text-[11px] text-muted-foreground">
                    {t("print_jobs.resolution.printed_by_hand_help")}
                  </span>
                </span>
              </label>
              <label className="flex cursor-pointer items-start gap-2 rounded-md border border-border px-3 py-2">
                <RadioGroupItem value="discarded" className="mt-0.5" />
                <span className="flex flex-col gap-0.5">
                  <span className="text-xs font-medium">{t("print_jobs.resolution.discarded")}</span>
                  <span className="text-[11px] text-muted-foreground">
                    {t("print_jobs.resolution.discarded_help")}
                  </span>
                </span>
              </label>
            </RadioGroup>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="resolve-reason" className="text-xs">
              {t("print_jobs.resolve.reason_label")}
              <span className="ml-1 text-destructive">*</span>
            </Label>
            <Textarea
              id="resolve-reason"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              placeholder={t("print_jobs.resolve.reason_placeholder")}
              rows={3}
              maxLength={255}
              data-testid="resolve-reason"
            />
            <p className="text-[11px] text-muted-foreground">
              {t("print_jobs.resolve.reason_help")}
            </p>
            {localError && (
              <p className="text-[11px] text-destructive" data-testid="resolve-local-error">
                {t(localError)}
              </p>
            )}
          </div>

          {serverError && (
            <div
              className="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-xs text-destructive"
              data-testid={`resolve-error-${serverError.kind}`}
            >
              <AlertTriangle className="mt-0.5 size-4 shrink-0" />
              <div className="flex flex-col gap-1">
                <span>{t(serverError.messageKey)}</span>
                {Object.entries(serverError.fieldErrors).map(([field, message]) => (
                  <span key={field} className="text-[11px] opacity-90">
                    {message}
                  </span>
                ))}
              </div>
            </div>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" size="sm" onClick={() => handleOpenChange(false)}>
            {blocked ? t("common.close") : t("common.cancel")}
          </Button>
          {!blocked && (
            <Button size="sm" onClick={handleSubmit} disabled={isPending} data-testid="resolve-submit">
              {t("print_jobs.resolve.submit")}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
