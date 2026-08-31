/**
 * UnmergeTableDialog — remove ONE OR MORE tables from a merged order
 * (tables.length >= 2). Backend enforces TB-08: cannot unmerge the last
 * table from a dine-in order. UI prevents picking ALL tables (must keep
 * at least one) so the constraint is satisfied by construction. Parent
 * fires unmerge per-id sequentially — failure midway leaves whatever
 * succeeded.
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
} from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { HelpButton } from "@/help/help-button";
import { Armchair, CheckIcon, MinusIcon, SplitIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/providers/app-provider";
import type { TableSummary } from "../types";
import { Spinner } from "@/components/ui/spinner";

export interface UnmergeTableDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  tables: TableSummary[];
  onConfirm: (tableIds: string[]) => Promise<void>;
}

export function UnmergeTableDialog({
  open,
  onOpenChange,
  tables,
  onConfirm,
}: UnmergeTableDialogProps) {
  const { t } = useTranslation();
  const [pickedIds, setPickedIds] = useState<Set<string>>(new Set());
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!open) setPickedIds(new Set());
  }, [open]);

  function toggle(id: string) {
    setPickedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      // Block selecting the LAST remaining table — TB-08 backend rule.
      else if (next.size < tables.length - 1) next.add(id);
      return next;
    });
  }

  async function handleConfirm() {
    if (pickedIds.size === 0) return;
    setSubmitting(true);
    try {
      await onConfirm(Array.from(pickedIds));
      setPickedIds(new Set());
      onOpenChange(false);
    } finally {
      setSubmitting(false);
    }
  }

  const remainingCount = tables.length - pickedIds.size;
  const maxReached = pickedIds.size >= tables.length - 1;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[90vh] w-[95vw] !max-w-5xl flex-col p-0">
        <DialogHeader className="flex flex-row items-start gap-3 shrink-0 border-b px-6 py-4">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-destructive/10 text-destructive">
            <SplitIcon className="size-5" />
          </span>
          <div className="flex-1 space-y-1">
            <div className="flex items-center gap-1.5">
              <DialogTitle>{t("pos.dialog.unmerge_table.title")}</DialogTitle>
              <HelpButton topic="unmerge-table" className="size-7" />
            </div>
            <DialogDescription>
              {t("pos.dialog.unmerge_table.desc")}
            </DialogDescription>
          </div>
        </DialogHeader>

        <div className="flex-1 overflow-y-auto px-6 py-4">
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-[3fr_2fr]">
            {/* Left column — table grid (multi-select) */}
            <div className="space-y-2">
              <Label>
                {t("pos.dialog.create_order.table")}{" "}
                {pickedIds.size > 0 && (
                  <span className="text-muted-foreground font-normal">
                    ({pickedIds.size} {t("pos.dialog.create_order.table_selected")})
                  </span>
                )}
              </Label>
              <div className="rounded-md border p-4 h-72 overflow-y-auto">
                <div className="grid grid-cols-[repeat(auto-fill,minmax(120px,1fr))] gap-3">
                  {tables.map((tb) => {
                    const picked = pickedIds.has(tb.id);
                    // Disable if: not already picked AND would exceed N-1 cap
                    const disabled = !picked && maxReached;
                    return (
                      <button
                        key={tb.id}
                        type="button"
                        disabled={disabled}
                        onClick={() => toggle(tb.id)}
                        aria-pressed={picked}
                        className={cn(
                          "group relative flex aspect-5/4 cursor-pointer flex-col items-center justify-center rounded-xl border-2 p-3 text-center transition-all duration-150",
                          "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
                          "disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:translate-y-0 disabled:hover:border-border/60 disabled:hover:shadow-none",
                          picked
                            ? "border-destructive bg-destructive/10 shadow-md ring-4 ring-destructive/10"
                            : "border-border/60 bg-card hover:-translate-y-0.5 hover:border-destructive/50 hover:shadow-md",
                        )}
                      >
                        <Armchair
                          aria-hidden
                          className={cn(
                            "pointer-events-none absolute inset-0 m-auto size-14 transition-opacity",
                            picked ? "text-destructive/10" : "text-muted-foreground/6 group-hover:text-destructive/10",
                          )}
                          strokeWidth={1.5}
                        />
                        {picked && (
                          <span className="absolute right-1.5 top-1.5 flex size-5 items-center justify-center rounded-full bg-destructive text-destructive-foreground shadow-md">
                            <CheckIcon className="size-3" strokeWidth={3} />
                          </span>
                        )}
                        <div className={cn(
                          "relative z-10 text-xl font-bold leading-none tracking-tight",
                          picked ? "text-destructive line-through decoration-destructive/40" : "text-foreground",
                        )}>
                          {tb.name ?? tb.code}
                        </div>
                      </button>
                    );
                  })}
                </div>
              </div>
              <p className="text-[11px] text-muted-foreground">
                {t("pos.dialog.unmerge_table.min_one_hint")}
              </p>
            </div>

            {/* Right column — keep / remove summary */}
            <div className="space-y-2">
              <Label>{t("pos.dialog.unmerge_table.title")}</Label>
              <div className="space-y-3 rounded-md border bg-muted/30 px-4 py-3">
                <div>
                  <div className="mb-1.5 text-[10px] uppercase tracking-wide text-muted-foreground">
                    {t("pos.dialog.unmerge_table.keep")}
                  </div>
                  <div className="flex flex-wrap gap-1.5">
                    {tables
                      .filter((tb) => !pickedIds.has(tb.id))
                      .map((tb) => (
                        <span
                          key={tb.id}
                          className="rounded-md bg-background px-2 py-1 text-sm font-bold text-foreground shadow-sm"
                        >
                          {tb.name ?? tb.code}
                        </span>
                      ))}
                  </div>
                </div>
                <div className="flex items-center gap-1.5 border-t pt-2 text-destructive">
                  <MinusIcon className="size-4" />
                  <span className="text-[10px] font-semibold uppercase tracking-wide">
                    {t("pos.dialog.unmerge_table.confirm")}
                  </span>
                </div>
                {pickedIds.size > 0 ? (
                  <div className="flex flex-wrap gap-1.5">
                    {tables
                      .filter((tb) => pickedIds.has(tb.id))
                      .map((tb) => (
                        <span
                          key={tb.id}
                          className="rounded-md bg-destructive/10 px-2 py-1 text-sm font-bold text-destructive shadow-sm line-through decoration-destructive/40"
                        >
                          {tb.name ?? tb.code}
                        </span>
                      ))}
                  </div>
                ) : (
                  <span className="text-sm text-muted-foreground">—</span>
                )}
                <div className="flex items-center gap-4 border-t pt-2 text-xs">
                  <span className="flex items-center gap-1 text-muted-foreground">
                    <Armchair className="size-3.5" />
                    <span className="font-semibold tabular-nums text-foreground">
                      {remainingCount}
                    </span>{" "}
                    {t("pos.dialog.create_order.table").toLowerCase()}{" "}
                    <span className="text-muted-foreground/70">
                      ({tables.length} → {remainingCount})
                    </span>
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <DialogFooter className="shrink-0 border-t bg-muted/30 px-6 py-3">
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={submitting}
            style={{padding: "12px 25px", borderRadius: "10px"}}
          >
            {t("common.cancel")}
          </Button>
          <Button
            variant="destructive"
            onClick={handleConfirm}
            disabled={pickedIds.size === 0 || submitting}
            style={{padding: "12px 35px", borderRadius: "10px"}}
          >
            {submitting && <Spinner className="size-4" />}
            {submitting ? t("pos.dialog.unmerge_table.state") : t("pos.dialog.unmerge_table.confirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
