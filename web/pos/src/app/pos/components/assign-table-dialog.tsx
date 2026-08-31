/**
 * AssignTableDialog — lets staff attach one OR multiple tables to an order
 * whose tables[] is currently empty (floating order). Called from OrderCart's
 * [Gán bàn →] action; confirm fires PUT /orders/{id}/init with the full
 * selected table_ids[] via useOrderFieldSave (first-write-wins).
 *
 * Replaces the pre-plan-007 BindTableDialog which bound a single table at
 * tab-create time.
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
import { Armchair, UsersIcon } from "lucide-react";
import { useTranslation } from "@/providers/app-provider";
import type { TableResource } from "../types";
import { TablePicker } from "./table-picker";
import { Spinner } from "@/components/ui/spinner";

export interface AssignTableDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  tables: TableResource[];
  onConfirm: (pickedTables: TableResource[]) => void | Promise<void>;
}

export function AssignTableDialog({
  open,
  onOpenChange,
  tables,
  onConfirm,
}: AssignTableDialogProps) {
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
    const picked = tables.filter((t) => pickedIds.has(t.id));
    if (picked.length === 0) return;
    setSubmitting(true);
    try {
      await onConfirm(picked);
      setPickedIds(new Set());
      onOpenChange(false);
    } finally {
      setSubmitting(false);
    }
  }

  const pickedTables = tables.filter((t) => pickedIds.has(t.id));
  const totalSeats = pickedTables.reduce((sum, t) => sum + t.seat_count, 0);

  return (
    <Dialog
      open={open}
      onOpenChange={(o) => {
        if (!o) setPickedIds(new Set());
        onOpenChange(o);
      }}
    >
      <DialogContent className="flex max-h-[90vh] w-[95vw] !max-w-5xl flex-col p-0">
        <DialogHeader className="flex flex-row items-start gap-3 shrink-0 border-b px-6 py-4">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
            <Armchair className="size-5" />
          </span>
          <div className="flex-1 space-y-1">
            <div className="flex items-center gap-1.5">
              <DialogTitle>{t("pos.dialog.assign_table.title")}</DialogTitle>
              <HelpButton topic="assign-table" className="size-7" />
            </div>
            <DialogDescription>
              {t("pos.dialog.assign_table.desc")}
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
                  maxHeightClass="h-72"
                />
              </div>
            </div>

            {/* Right column — selected summary */}
            <div className="space-y-2">
              <Label>{t("pos.dialog.assign_table.selected")}</Label>
              <div className="space-y-3 rounded-md border bg-muted/30 px-4 py-3 min-h-[7rem]">
                {pickedTables.length > 0 ? (
                  <div className="flex flex-wrap gap-1.5">
                    {pickedTables.map((tb) => (
                      <span
                        key={tb.id}
                        className="rounded-md bg-primary/10 px-2 py-1 text-sm font-bold text-primary"
                      >
                        {tb.name ?? tb.code}
                      </span>
                    ))}
                  </div>
                ) : (
                  <div className="text-sm text-muted-foreground">—</div>
                )}
                <div className="flex items-center gap-4 border-t pt-2 text-xs">
                  <span className="flex items-center gap-1 text-muted-foreground">
                    <Armchair className="size-3.5" />
                    <span className="font-semibold tabular-nums text-foreground">
                      {pickedTables.length}
                    </span>{" "}
                    {t("pos.dialog.create_order.table").toLowerCase()}
                  </span>
                  <span className="flex items-center gap-1 text-muted-foreground">
                    <UsersIcon className="size-3.5" />
                    <span className="font-semibold tabular-nums text-foreground">
                      {totalSeats}
                    </span>{" "}
                    {t("pos.table_picker.seat_unit")}
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
            style={{ padding: "12px 25px", borderRadius: "10px" }}
          >
            {t("common.cancel")}
          </Button>
          <Button
            onClick={handleConfirm}
            disabled={pickedIds.size === 0 || submitting}
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
