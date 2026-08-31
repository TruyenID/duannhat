/**
 * MergeTableDialog — add ONE more table to an order that already has
 * at least one table. Different from ChangeTableDialog (swap) and
 * AssignTableDialog (first-time assignment).
 *
 * Typical use: party grew, staff needs to join an adjacent table to
 * the existing check. Fires POST /orders/{id}/merge-table one or
 * more times (one per picked row). Parent orchestrates sequentially
 * so a failure midway leaves the order with whatever did succeed.
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
import { Armchair, PlusCircleIcon, PlusIcon, UsersIcon } from "lucide-react";
import { useTranslation } from "@/providers/app-provider";
import type { TableResource, TableSummary } from "../types";
import { TablePicker } from "./table-picker";
import { Spinner } from "@/components/ui/spinner";

export interface MergeTableDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Tables already on the order — shown disabled so staff can't pick them. */
  currentTables: TableSummary[];
  /** Full shop table list — picker filters out the currentTables internally. */
  tables: TableResource[];
  onConfirm: (pickedIds: string[]) => Promise<void>;
}

export function MergeTableDialog({
  open,
  onOpenChange,
  currentTables,
  tables,
  onConfirm,
}: MergeTableDialogProps) {
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
      else next.add(id);
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

  const currentIds = new Set(currentTables.map((t) => t.id));
  const currentCodes = currentTables.map((t) => t.name ?? t.code).join(", ");
  const pickedCodes = tables
    .filter((t) => pickedIds.has(t.id))
    .map((t) => t.name ?? t.code);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[90vh] w-[95vw] !max-w-5xl flex-col p-0">
        <DialogHeader className="flex flex-row items-start gap-3 shrink-0 border-b px-6 py-4">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-400">
            <PlusCircleIcon className="size-5" />
          </span>
          <div className="flex-1 space-y-1">
            <div className="flex items-center gap-1.5">
              <DialogTitle>{t("pos.dialog.merge_table.title")}</DialogTitle>
              <HelpButton topic="merge-table" className="size-7" />
            </div>
            <DialogDescription>
              {t("pos.dialog.merge_table.desc", { tables: currentCodes })}
            </DialogDescription>
          </div>
        </DialogHeader>

        <div className="flex-1 overflow-y-auto px-6 py-4">
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-[3fr_2fr]">
            {/* Left column — table picker */}
            <div className="space-y-2">
              <Label>
                {t("pos.dialog.create_order.table")}{" "}
                {pickedIds.size > 0 && (
                  <span className="text-muted-foreground font-normal">
                    ({pickedIds.size} {t("pos.dialog.create_order.table_selected")})
                  </span>
                )}
              </Label>
              <div className="rounded-md border">
                <TablePicker
                  multi
                  tables={tables}
                  selectedIds={pickedIds}
                  onToggle={(t) => toggle(t.id)}
                  disabledTableIds={currentIds}
                  maxHeightClass="h-72"
                />
              </div>
            </div>

            {/* Right column — current + merging summary */}
            <div className="space-y-2">
              <Label>{t("pos.dialog.merge_table.title")}</Label>
              <div className="space-y-3 rounded-md border bg-muted/30 px-4 py-3">
                <div>
                  <div className="mb-1.5 text-[10px] uppercase tracking-wide text-muted-foreground">
                    {t("pos.dialog.create_order.selected")}
                  </div>
                  <div className="flex flex-wrap gap-1.5">
                    {currentTables.map((tb) => (
                      <span
                        key={tb.id}
                        className="rounded-md bg-background px-2 py-1 text-sm font-bold text-foreground shadow-sm"
                      >
                        {tb.name ?? tb.code}
                      </span>
                    ))}
                  </div>
                </div>
                <div className="flex items-center gap-1.5 border-t pt-2 text-emerald-700 dark:text-emerald-400">
                  <PlusIcon className="size-4" />
                  <span className="text-[10px] font-semibold uppercase tracking-wide">
                    {t("pos.dialog.merge_table.merging").replace(":", "")}
                  </span>
                </div>
                {pickedCodes.length > 0 ? (
                  <div className="flex flex-wrap gap-1.5">
                    {pickedCodes.map((code) => (
                      <span
                        key={code}
                        className="rounded-md bg-emerald-100 px-2 py-1 text-sm font-bold text-emerald-700 shadow-sm dark:bg-emerald-500/15 dark:text-emerald-400"
                      >
                        {code}
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
                      {currentTables.length + pickedIds.size}
                    </span>{" "}
                    {t("pos.dialog.create_order.table").toLowerCase()}
                  </span>
                  <span className="flex items-center gap-1 text-muted-foreground">
                    <UsersIcon className="size-3.5" />
                    <span className="font-semibold tabular-nums text-foreground">
                      {tables
                        .filter((tb) => pickedIds.has(tb.id))
                        .reduce((sum, tb) => sum + tb.seat_count, 0)}
                    </span>{" "}
                    {t("pos.table_picker.seat_unit")} +
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
            onClick={handleConfirm}
            disabled={pickedIds.size === 0 || submitting}
            style={{padding: "12px 35px", borderRadius: "10px"}}
          >
            {submitting && <Spinner className="size-4" />}
            {submitting ? t("pos.dialog.merge_table.state") : t("pos.dialog.merge_table.confirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
