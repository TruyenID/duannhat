/**
 * plan-056 — the reason a dish is going off the menu.
 *
 * ## The one rule this component exists to hold
 *
 * It must NEVER stand between a cashier and taking a sold-out dish off the
 * menu. Four preset chips mean the common case is a single tap with no typing;
 * the free-text box is optional; there is **no minimum length**, no format
 * check, and no "reason too short". Over-long text is trimmed silently at 255
 * (the server column) rather than refused.
 *
 * That is a deliberate trade: a slightly vague reason in a report costs nothing,
 * while a validation error mid-service costs a customer's order.
 *
 * Turning something back ON never opens this dialog — there is nothing to
 * explain, and asking would double the taps on the action staff take most.
 *
 * ## Why the layout looks the way it does
 *
 * Three visual decisions, each fixing something that made the first cut hard to
 * read at arm's length on a tablet:
 *
 *   · **Exactly ONE solid button on screen.** The selected reason chip used to
 *     be solid primary — the same weight as the confirm button — so the eye had
 *     two candidates for "the thing I press" and had to read both. Selection is
 *     now a tint + ring + corner check; the only filled control is the action.
 *   · **Amber appears ONLY for the section-wide switch-off.** Turning a whole
 *     course off mid-service is the most damaging mis-tap on this screen. If the
 *     single-dish case — a routine, one-tap-reversible action done dozens of
 *     times a shift — were also dressed in warning colours, the colour would
 *     stop meaning anything by the time it mattered.
 *   · **Chip labels are left-aligned and may wrap.** Centred single-line labels
 *     fit Vietnamese and Japanese and then clipped in English ("Out of
 *     ingredients"). Left-aligned wrapping text survives all three, and reads
 *     faster as a list of options besides.
 */

import { useState } from "react";
import { Check, Info, PowerOff, TriangleAlert } from "lucide-react";
import {
  Button,
  Dialog,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Spinner,
  Textarea,
} from "@godxjp/ui";
// DialogContent ONLY from here — the pos-web-local wrapper adds the per-dialog
// error boundary that keeps a render crash from taking the cashier's open order
// with it. `dialog-boundary.arch.test.ts` fails the build if this is imported
// from @godxjp/ui instead.
import { DialogContent } from "@/components/ui/dialog";
import { useTranslation } from "@/providers/app-provider";
import { cn } from "@/lib/utils";
import { HelpButton } from "@/help/help-button";

/** Server column width. Trimmed here so a long note can never 422. */
export const REASON_MAX_LENGTH = 255;

/**
 * The four presets, as i18n KEYS.
 *
 * Keys, not literal strings: the reason is stored verbatim and later read back
 * in a report, so it has to be written in the language the shop actually uses —
 * resolving at render time means a Vietnamese shop stores Vietnamese.
 */
const REASON_PRESET_KEYS = [
  "menu_availability.reason.out_of_stock",
  "menu_availability.reason.out_of_ingredients",
  "menu_availability.reason.stopped_today",
  "menu_availability.reason.other",
] as const;

/** The last preset switches the free-text box from optional to the source. */
const OTHER_KEY = "menu_availability.reason.other";

export interface DisableReasonDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** What is being turned off — shown so a mis-tap is obvious before confirming. */
  targetName: string;
  /** Non-null for the section button; renders the "N dishes" warning. */
  affectedCount?: number | null;
  isPending?: boolean;
  onConfirm: (reason: string) => void;
}

export function DisableReasonDialog({
  open,
  onOpenChange,
  targetName,
  affectedCount = null,
  isPending = false,
  onConfirm,
}: DisableReasonDialogProps) {
  const { t } = useTranslation();
  const [selectedKey, setSelectedKey] = useState<string>(REASON_PRESET_KEYS[0]);
  const [note, setNote] = useState("");

  // Reset per opening. Carrying the previous dish's reason over is how a shop
  // ends up with "hết nguyên liệu" stamped on something that simply stopped
  // selling — and that reason is what a report reads back months later.
  //
  // Adjusted DURING RENDER (React's documented "adjusting state when a prop
  // changes" pattern) rather than in an effect. An effect would paint one frame
  // carrying the previous dish's answer before correcting itself, which on a
  // tablet is long enough to tap Confirm on — and `react-hooks/set-state-in-effect`
  // flags it for exactly that reason.
  const [wasOpen, setWasOpen] = useState(open);
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) {
      setSelectedKey(REASON_PRESET_KEYS[0]);
      setNote("");
    }
  }

  const isOther = selectedKey === OTHER_KEY;
  const isSection = affectedCount != null;

  /**
   * A preset alone is a complete answer. With "Khác" the note becomes the
   * reason — and if the cashier leaves it blank we still send the word "Khác"
   * rather than blocking, because an unspecific reason beats an unsold dish.
   */
  const resolveReason = (): string => {
    const trimmedNote = note.trim();
    if (isOther) {
      return (trimmedNote || t(OTHER_KEY)).slice(0, REASON_MAX_LENGTH);
    }
    const preset = t(selectedKey);

    return (trimmedNote ? `${preset} — ${trimmedNote}` : preset).slice(
      0,
      REASON_MAX_LENGTH,
    );
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="gap-0 overflow-hidden p-0 sm:max-w-lg"
        data-slot="disable-reason-dialog"
        data-testid="disable-reason-dialog"
      >
        {/* The house dialog shape used across pos-web: p-0 content, a bordered
            header carrying a size-10 icon badge, a padded body, and a tinted
            footer strip. Matching it is not only tidiness — `DialogContent`
            renders its own ✕ at `top-4 right-4`, so anything parked in the
            far-right of the header lands UNDER it. That is what put the ✕ on
            top of the `?` glyph in the first cut; the help button belongs
            inline beside the title, exactly as the other dialogs place it. */}
        <DialogHeader className="flex flex-row items-start gap-3 border-b px-5 py-4">
          {/* Says what KIND of action this is before a word is read. Muted, not
              amber: see the docblock — the warning colour is reserved for the
              section case so it still means something when it appears. */}
          <span
            className="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
            aria-hidden="true"
          >
            <PowerOff className="size-5" />
          </span>

          <div className="min-w-0 flex-1 space-y-1">
            <div className="flex items-start gap-1.5">
              {/* line-clamp-2, not truncate: a name that is long because it
                  carries a variant ("Phở bò · Lớn") is exactly the case where
                  reading the tail is how you catch a mis-tap. */}
              <DialogTitle className="line-clamp-2 text-base leading-snug">
                {t("menu_availability.disable_dialog.title", { name: targetName })}
              </DialogTitle>
              <HelpButton topic="menu-availability" className="size-7 shrink-0" />
            </div>
            <DialogDescription className="text-xs">
              {t("menu_availability.disable_dialog.description")}
            </DialogDescription>
          </div>
        </DialogHeader>

        <div className="flex flex-col gap-4 px-5 py-4">
          {isSection && (
            <div
              className="flex items-start gap-2.5 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 dark:border-amber-500/30 dark:bg-amber-500/10"
              data-testid="disable-reason-section-warning"
            >
              <TriangleAlert className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
              <p className="text-xs leading-relaxed text-amber-900 dark:text-amber-100">
                {t("menu_availability.disable_dialog.section_description", {
                  count: affectedCount,
                })}
              </p>
            </div>
          )}

          <div>
            <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
              {t("menu_availability.disable_dialog.reason_label")}
            </p>
            <div className="grid grid-cols-2 gap-2">
              {REASON_PRESET_KEYS.map((key) => {
                const selected = key === selectedKey;

                return (
                  <button
                    // type="button" — this dialog can render inside a <form> and a
                    // bare <button> defaults to submit, which would fire the form
                    // instead of choosing a reason.
                    type="button"
                    key={key}
                    onClick={() => setSelectedKey(key)}
                    aria-pressed={selected}
                    className={cn(
                      // min-h-[52px] rather than a scale step: the chip must
                      // clear the 44px touch minimum even when its label wraps
                      // to two lines, and h-11 would clip the second line.
                      "relative flex min-h-[52px] items-center rounded-lg border py-2.5 pl-3 pr-8 text-left text-sm font-medium leading-snug transition-colors",
                      "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1",
                      selected
                        ? "border-primary bg-primary/10 text-foreground ring-1 ring-primary"
                        : "border-input bg-background text-foreground hover:bg-muted",
                    )}
                    data-testid={`reason-chip-${key}`}
                  >
                    <span className="min-w-0">{t(key)}</span>
                    {selected && (
                      <span
                        className="absolute right-2 top-2 flex size-4 items-center justify-center rounded-full bg-primary text-primary-foreground"
                        aria-hidden="true"
                      >
                        <Check className="size-3" strokeWidth={3} />
                      </span>
                    )}
                  </button>
                );
              })}
            </div>
          </div>

          <div>
            <div className="mb-1.5 flex items-baseline justify-between gap-2">
              <label
                htmlFor="disable-reason-note"
                className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"
              >
                {isOther
                  ? t("menu_availability.disable_dialog.note_label_required_ish")
                  : t("menu_availability.disable_dialog.note_label")}
              </label>
              {/* Shown only once there is something to count. A permanent
                  "0/255" reads as a quota to fill, and this box is optional. */}
              {note.length > 0 && (
                <span className="shrink-0 text-[11px] tabular-nums text-muted-foreground">
                  {note.length}/{REASON_MAX_LENGTH}
                </span>
              )}
            </div>
            <Textarea
              id="disable-reason-note"
              rows={2}
              value={note}
              maxLength={REASON_MAX_LENGTH}
              onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) =>
                setNote(e.target.value)
              }
              placeholder={t("menu_availability.disable_dialog.note_placeholder")}
              // Deliberately NOT autoFocus, even when "Khác" is selected: on a
              // tablet the software keyboard would slide up over the confirm
              // button, so the one-tap path would start by needing a dismiss.
              className="resize-none"
              data-testid="reason-note"
            />
          </div>

          <p className="flex items-start gap-2 text-[11px] leading-relaxed text-muted-foreground">
            <Info className="mt-px size-3.5 shrink-0" aria-hidden="true" />
            {t("menu_availability.disable_dialog.orders_unaffected")}
          </p>
        </div>

        <DialogFooter className="gap-2 border-t bg-muted/30 px-5 py-3">
          <Button
            type="button"
            variant="outline"
            className="h-11 flex-1 sm:flex-none"
            onClick={() => onOpenChange(false)}
            disabled={isPending}
          >
            {t("common.cancel")}
          </Button>
          <Button
            type="button"
            className="h-11 flex-1 gap-1.5 sm:flex-none"
            // NEVER disabled on "the reason is not good enough". The only thing
            // that disables it is a write already in flight.
            disabled={isPending}
            onClick={() => onConfirm(resolveReason())}
            data-testid="reason-confirm"
          >
            {isPending ? (
              <Spinner className="size-4" />
            ) : (
              <PowerOff className="size-4" />
            )}
            {t("menu_availability.disable_dialog.confirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
