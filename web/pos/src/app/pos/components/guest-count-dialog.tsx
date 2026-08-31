/**
 * GuestCountDialog — single-field dialog for entering or editing the
 * order's guest_count. Caller decides endpoint via useOrderFieldSave
 * (init for first-time, update for edit). Plan-007 DESIGN §"OrderCart
 * header — deferred fill-in and edits".
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
} from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { HelpButton } from "@/help/help-button";
import { ArrowRightIcon, MinusIcon, PlusIcon, UsersIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/providers/app-provider";
import { Spinner } from "@/components/ui/spinner";

export interface GuestCountDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Current value (null means not yet entered). */
  value: number | null;
  onConfirm: (value: number) => Promise<void>;
}

// Common party sizes — one-tap for the 80% case. Uneven list (1,2,4,6,8,10)
// matches how staff actually count tables; odd single-digit sizes are rare
// enough to warrant manual entry.
const QUICK_SIZES = [1, 2, 4, 6, 8, 10];

export function GuestCountDialog({
  open,
  onOpenChange,
  value,
  onConfirm,
}: GuestCountDialogProps) {
  const { t } = useTranslation();
  const [count, setCount] = useState<number>(value ?? 1);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (open) setCount(value ?? 1);
  }, [open, value]);

  async function handleConfirm() {
    if (count < 1) return;
    setSubmitting(true);
    try {
      await onConfirm(count);
      onOpenChange(false);
    } finally {
      setSubmitting(false);
    }
  }

  const isEditing = value !== null;
  const hasChanged = isEditing && value !== count;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md p-0">
        <DialogHeader className="flex flex-row items-start gap-3 border-b px-6 py-4">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-sky-500/10 text-sky-600 dark:text-sky-400">
            <UsersIcon className="size-5" />
          </span>
          <div className="flex-1 space-y-1">
            <div className="flex items-start gap-1.5">
              <DialogTitle>
                {isEditing ? t("pos.dialog.guest_count.title_edit") : t("pos.dialog.guest_count.title_new")}
              </DialogTitle>
              <HelpButton topic="guest-count" className="size-7" />
            </div>
            <DialogDescription>
              {t("pos.dialog.guest_count.desc")}
            </DialogDescription>
          </div>
        </DialogHeader>

        <div className="space-y-5 px-6 py-6">
          {/* Big focal stepper — touch-friendly for POS */}
          <div className="flex flex-col items-center gap-2">
            <div className="flex items-center justify-center gap-4">
              <Button
                type="button"
                variant="outline"
                size="icon"
                className="size-14 rounded-full shadow-sm"
                onClick={() => setCount((n) => Math.max(1, n - 1))}
                disabled={count <= 1}
                aria-label={t("common.decrease")}
              >
                <MinusIcon className="size-6" />
              </Button>
              <Input
                id="guest-count-input"
                value={String(count)}
                onChange={(e) => {
                  const raw = e.target.value.trim();
                  const parsed = parseInt(raw, 10);
                  if (!Number.isNaN(parsed) && parsed >= 1) setCount(parsed);
                }}
                className="h-20 w-28 rounded-xl border-2 text-center text-5xl font-bold tabular-nums shadow-sm"
                inputMode="numeric"
              />
              <Button
                type="button"
                variant="outline"
                size="icon"
                className="size-14 rounded-full shadow-sm"
                onClick={() => setCount((n) => n + 1)}
                aria-label={t("common.increase")}
              >
                <PlusIcon className="size-6" />
              </Button>
            </div>
            <div className="flex items-center gap-1 text-xs text-muted-foreground">
              <UsersIcon className="size-3" />
              <span>{t("pos.dialog.guest_count.unit")}</span>
            </div>
            {/* Reserved diff slot — always present so modal height stays
                stable when stepping +/- toggles `hasChanged`. */}
            <div className="mt-1 flex h-6 items-center">
              {hasChanged && (
                <div className="flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-[11px] tabular-nums">
                  <span className="text-muted-foreground">{t("pos.dialog.guest_count.previous")}</span>
                  <span className="font-semibold text-foreground">{value}</span>
                  <ArrowRightIcon className="size-3 text-muted-foreground" />
                  <span className="font-semibold text-primary">{count}</span>
                  <span
                    className={cn(
                      "ml-0.5 font-semibold",
                      count > (value ?? 0) ? "text-emerald-600 dark:text-emerald-400" : "text-amber-600 dark:text-amber-400",
                    )}
                  >
                    ({count > (value ?? 0) ? "+" : ""}
                    {count - (value ?? 0)})
                  </span>
                </div>
              )}
            </div>
          </div>

          {/* Quick-pick chips */}
          <div className="space-y-2">
            <div className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
              {t("pos.dialog.guest_count.quick")}
            </div>
            <div className="grid grid-cols-6 gap-1.5">
              {QUICK_SIZES.map((n) => {
                const picked = count === n;
                return (
                  <button
                    key={n}
                    type="button"
                    onClick={() => setCount(n)}
                    className={cn(
                      "h-11 cursor-pointer rounded-lg border-2 text-base font-bold tabular-nums transition-all",
                      "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
                      picked
                        ? "border-primary bg-primary text-primary-foreground shadow-md scale-105"
                        : "border-border/60 bg-card text-muted-foreground hover:-translate-y-0.5 hover:border-primary/50 hover:text-foreground hover:shadow-sm",
                    )}
                  >
                    {n}
                  </button>
                );
              })}
            </div>
          </div>
        </div>

        <DialogFooter className="border-t bg-muted/30 px-6 py-3">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={submitting}
            style={{ padding: "12px 25px", borderRadius: "10px" }}
          >
            {t("common.cancel")}
          </Button>
          <Button
            onClick={handleConfirm}
            disabled={submitting || count < 1 || (isEditing && !hasChanged)}
            style={{ padding: "12px 35px", borderRadius: "10px" }}
          >
            {submitting && <Spinner className="size-4" />}
            {submitting ? t("common.saving") : t("common.confirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
