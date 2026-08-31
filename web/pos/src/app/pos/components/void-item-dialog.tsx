/**
 * VoidItemDialog — captures the void reason for a single cart line before
 * firing useVoidItem.
 *
 * plan-051 (#1149) — reason-PICKER first: when the brand has VoidReason
 * master rows (fetched via useVoidReasons), staff picks from the list and
 * the void carries `void_reason_id` (which drives the stock effect on the
 * backend). A picked reason with `requires_note` demands a free-text note
 * on top. The legacy free-text input remains as a clearly-secondary
 * fallback — and becomes the primary path automatically when the list is
 * empty or unreachable (offline LAN without a mirror for the endpoint).
 *
 * Visual treatment uses an amber/warning tone (vs the destructive red of
 * VoidOrderDialog) — voiding a single line is reversible at the receipt
 * level (line is soft-voided, still printed on the bill with a "voided"
 * marker), so the modal communicates a confirmable warning rather than
 * an irreversible destructive action.
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
import { AlertTriangleIcon, CheckIcon, Trash2Icon } from "lucide-react";
import { Spinner } from "@/components/ui/spinner";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/providers/app-provider";
import type { VoidReason } from "@/services/void-reason-service";

export interface VoidItemSubmit {
  /** Id of the picked VoidReason master row (picker mode). */
  voidReasonId?: string;
  /**
   * Free-text: the note accompanying a picked reason, or the full reason
   * itself in manual/fallback mode.
   */
  note?: string;
}

export interface VoidItemDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  itemLabel: string;
  /** Active VoidReason rows for the picker. Empty/undefined = manual mode. */
  reasons?: VoidReason[];
  /** True while the reason list is still loading (brief spinner). */
  reasonsLoading?: boolean;
  onConfirm: (payload: VoidItemSubmit) => Promise<void>;
}

const MIN_REASON = 5;

export function VoidItemDialog({
  open,
  onOpenChange,
  itemLabel,
  reasons = [],
  reasonsLoading = false,
  onConfirm,
}: VoidItemDialogProps) {
  const { t } = useTranslation();
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [note, setNote] = useState("");
  const [manualReason, setManualReason] = useState("");
  // Staff explicitly opted out of the picker ("enter manually instead").
  const [manualOverride, setManualOverride] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const presetReasons = [
    t("pos.void_item_reason.customer_changed"),
    t("pos.void_item_reason.out_of_stock"),
    t("pos.void_item_reason.wrong_prep"),
    t("pos.void_item_reason.customer_dislike"),
  ];

  useEffect(() => {
    if (!open) {
      setSelectedId(null);
      setNote("");
      setManualReason("");
      setManualOverride(false);
    }
  }, [open]);

  const hasPicker = reasons.length > 0;
  const pickerMode = hasPicker && !manualOverride;
  const selected = reasons.find((r) => r.id === selectedId) ?? null;

  const trimmedNote = note.trim();
  const trimmedManual = manualReason.trim();

  // #1188 — a preset is an approved reason, so it clears the gate at any
  // length. MIN_REASON exists to stop junk free text ("aa"), not to police
  // copy we ship ourselves: the ja presets 食材切れ / 調理ミス are 4 characters,
  // and holding them to 5 left a JP cashier with a filled box and a dead
  // confirm button. Matches plan-051's direction, where the picker is the
  // real path and preset text is only the offline fallback.
  const presetChosen = presetReasons.includes(trimmedManual);

  const valid = pickerMode
    ? selected !== null && (!selected.requires_note || trimmedNote.length > 0)
    : presetChosen || trimmedManual.length >= MIN_REASON;

  const manualCharCount = trimmedManual.length;
  const manualProgress = valid
    ? 100
    : Math.min(100, (manualCharCount / MIN_REASON) * 100);

  async function handleConfirm() {
    if (!valid || submitting) return;
    setSubmitting(true);
    try {
      await onConfirm(
        pickerMode && selected
          ? {
              voidReasonId: selected.id,
              note: trimmedNote.length > 0 ? trimmedNote : undefined,
            }
          : { note: trimmedManual },
      );
      onOpenChange(false);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[85vh] max-w-lg flex-col gap-0 rounded-2xl p-0 sm:max-w-lg sm:rounded-lg">
        <DialogHeader className="flex flex-row items-start gap-3 border-b px-4 py-4 sm:px-6 sm:py-4">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-amber-500/15 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400">
            <Trash2Icon className="size-5" />
          </span>
          <div className="min-w-0 flex-1 space-y-1">
            <div className="flex items-start gap-1.5">
              <DialogTitle className="flex flex-col items-start gap-1 text-base font-bold sm:flex-row sm:flex-wrap sm:items-center sm:gap-2 sm:text-lg">
                <span>{t("pos.dialog.void_item.title")}</span>
                <Badge
                  variant="outline"
                  className="max-w-full truncate text-[10px] font-medium"
                >
                  {itemLabel}
                </Badge>
              </DialogTitle>
              <HelpButton topic="void-item" className="size-7" />
            </div>
            <DialogDescription className="text-xs sm:text-sm">
              {t("pos.dialog.void_item.desc")}
            </DialogDescription>
          </div>
        </DialogHeader>

        <div className="flex-1 space-y-4 overflow-y-auto px-4 py-4 sm:px-6 sm:py-5">
          {/* Soft warning banner — voiding the line is non-destructive
              (line stays on the receipt as voided), so the tone is amber
              rather than the harder red of the void-order dialog. */}
          <div className="flex items-start gap-2.5 rounded-md border border-amber-300 bg-amber-50 px-3 py-2.5 dark:border-amber-500/40 dark:bg-amber-500/10">
            <AlertTriangleIcon className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <p className="text-[12px] leading-relaxed text-amber-900 dark:text-amber-100">
              {t("pos.dialog.void_item.desc")}
            </p>
          </div>

          {reasonsLoading && !hasPicker ? (
            <div className="flex items-center justify-center gap-2 py-4 text-sm text-muted-foreground">
              <Spinner className="size-4" />
              {t("common.loading")}
            </div>
          ) : pickerMode ? (
            <>
              {/* Reason picker — one tap selects, note appears below. */}
              <div className="space-y-2" role="radiogroup">
                <div className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                  {t("pos.dialog.void_item.pick_label")}
                </div>
                <div className="space-y-1.5">
                  {reasons.map((reason) => {
                    const active = reason.id === selectedId;
                    return (
                      <button
                        key={reason.id}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        onClick={() => setSelectedId(reason.id)}
                        className={cn(
                          "flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg border-2 px-3.5 py-2.5 text-left text-sm font-medium transition-all",
                          "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
                          active
                            ? "border-amber-500 bg-amber-500/10 text-amber-800 shadow-sm dark:border-amber-400 dark:text-amber-200"
                            : "border-border/60 bg-card text-foreground hover:border-amber-400/60 hover:shadow-sm",
                        )}
                      >
                        <span className="min-w-0 flex-1 truncate">
                          {reason.label}
                          {reason.requires_note && (
                            <span className="ml-1 text-[11px] font-normal text-muted-foreground">
                              ({t("pos.dialog.void_item.note_required_tag")})
                            </span>
                          )}
                        </span>
                        {active && (
                          <CheckIcon className="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                        )}
                      </button>
                    );
                  })}
                </div>
              </div>

              {/* Note textarea — required when the picked reason demands it. */}
              {selected && (
                <div className="space-y-2">
                  <Label
                    htmlFor="void-item-note"
                    className="text-sm font-semibold"
                  >
                    {t("pos.dialog.void_item.note_label")}{" "}
                    <span
                      className={cn(
                        "text-[11px] font-normal",
                        selected.requires_note && trimmedNote.length === 0
                          ? "text-amber-600 dark:text-amber-400"
                          : "text-muted-foreground",
                      )}
                    >
                      (
                      {selected.requires_note
                        ? t("pos.dialog.void_item.note_required_tag")
                        : t("pos.dialog.void_item.note_optional_tag")}
                      )
                    </span>
                  </Label>
                  <Textarea
                    id="void-item-note"
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    rows={2}
                    placeholder={t("pos.dialog.void_item.note_placeholder")}
                    className="min-h-[60px] resize-none rounded-lg text-sm"
                  />
                </div>
              )}

              {/* Secondary escape hatch — free text without a master row. */}
              <button
                type="button"
                onClick={() => setManualOverride(true)}
                className="cursor-pointer text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
              >
                {t("pos.dialog.void_item.manual_toggle")}
              </button>
            </>
          ) : (
            <>
              {/* Legacy free-text path — primary when the reason list is
                  empty/unreachable, secondary (via toggle) otherwise. */}
              <div className="space-y-2">
                <div className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                  {t("pos.dialog.void_item.preset_label")}
                </div>
                <div className="flex flex-wrap gap-1.5">
                  {presetReasons.map((preset) => (
                    <button
                      key={preset}
                      type="button"
                      onClick={() => setManualReason(preset)}
                      className={cn(
                        "cursor-pointer rounded-full border-2 px-3.5 py-1.5 text-xs font-medium transition-all",
                        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
                        trimmedManual === preset
                          ? "border-amber-500 bg-amber-500/10 text-amber-700 shadow-sm dark:border-amber-400 dark:text-amber-300"
                          : "border-border/60 bg-card text-muted-foreground hover:-translate-y-0.5 hover:border-amber-400/60 hover:text-foreground hover:shadow-sm",
                      )}
                    >
                      {preset}
                    </button>
                  ))}
                </div>
              </div>

              {/* Reason textarea + char-count + progress bar */}
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <Label
                    htmlFor="void-item-reason"
                    className="text-sm font-semibold"
                  >
                    {t("pos.dialog.void_item.reason_label")}
                  </Label>
                  {/* #1188 — the counter only describes the free-text gate. A
                      chosen preset bypasses it, so showing "4 / 5+" next to an
                      enabled confirm button would just contradict itself. */}
                  {!presetChosen && (
                    <span
                      className={cn(
                        "text-[11px] font-semibold tabular-nums",
                        valid
                          ? "text-emerald-600 dark:text-emerald-400"
                          : "text-amber-600 dark:text-amber-400",
                      )}
                    >
                      {manualCharCount} / {MIN_REASON}+ {t("common.characters")}
                    </span>
                  )}
                </div>
                <Textarea
                  id="void-item-reason"
                  value={manualReason}
                  onChange={(e) => setManualReason(e.target.value)}
                  rows={3}
                  placeholder={t("pos.dialog.void_item.reason_placeholder")}
                  className="min-h-[80px] resize-none rounded-lg text-sm"
                />
                {/* Progress bar — fills as user types toward MIN_REASON. Same
                    pattern as VoidOrderDialog so both dialogs feel consistent. */}
                <div className="h-1 overflow-hidden rounded-full bg-muted">
                  <div
                    className={cn(
                      "h-full transition-all duration-200",
                      valid ? "bg-emerald-500" : "bg-amber-500",
                    )}
                    style={{ width: `${manualProgress}%` }}
                  />
                </div>
              </div>

              {hasPicker && (
                <button
                  type="button"
                  onClick={() => setManualOverride(false)}
                  className="cursor-pointer text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                >
                  {t("pos.dialog.void_item.picker_toggle")}
                </button>
              )}
            </>
          )}
        </div>

        <DialogFooter className="grid shrink-0 grid-cols-2 gap-2 border-t bg-muted/20 px-4 py-3 sm:px-6">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={submitting}
            className="h-11 rounded-lg"
          >
            {t("common.cancel")}
          </Button>
          <Button
            onClick={handleConfirm}
            disabled={!valid || submitting}
            className={cn(
              "h-11 gap-1.5 rounded-lg text-base font-semibold",
              "bg-amber-500 text-white hover:bg-amber-600",
              "dark:bg-amber-500 dark:hover:bg-amber-600",
              "disabled:bg-amber-500/50 disabled:hover:bg-amber-500/50",
            )}
          >
            {submitting && <Spinner className="size-4" />}
            {submitting
              ? t("pos.dialog.void_item.canceling")
              : t("pos.dialog.void_item.confirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
