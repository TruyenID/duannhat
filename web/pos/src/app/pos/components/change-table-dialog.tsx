/**
 * ChangeTableDialog — single-table swap. Only shown when the order has
 * exactly one table. On confirm runs useChangeTable(from, to) which does
 * merge(to) then unmerge(from). Result is tagged so callers surface the
 * right UI on partial failure — see DESIGN §"Change table flow".
 */

import { useState } from "react";
import {
  Alert,
  AlertDescription,
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
import { ArrowRightLeftIcon, ArrowRightIcon, AlertTriangleIcon } from "lucide-react";
import { useTranslation } from "@/providers/app-provider";
import type { TableResource, TableSummary } from "../types";
import type { ChangeTableResult } from "@/hooks/api/use-orders";
import { TablePicker } from "./table-picker";
import { Spinner } from "@/components/ui/spinner";

export interface ChangeTableDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  fromTable: TableSummary;
  tables: TableResource[];
  onConfirm: (fromTableId: string, toTableId: string) => Promise<ChangeTableResult>;
}

export function ChangeTableDialog({
  open,
  onOpenChange,
  fromTable,
  tables,
  onConfirm,
}: ChangeTableDialogProps) {
  const { t } = useTranslation();
  const [toId, setToId] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [mergeError, setMergeError] = useState<string | null>(null);

  async function handleConfirm() {
    if (!toId) return;
    setMergeError(null);
    setSubmitting(true);
    try {
      const result = await onConfirm(fromTable.id, toId);
      if (result.ok) {
        setToId(null);
        onOpenChange(false);
        return;
      }
      if (result.stage === "merge") {
        setMergeError(
          (result.error.body?.message as string | undefined) ??
            t("pos.dialog.change_table.error_merge"),
        );
        return;
      }
      // step 2 (unmerge) failed — close dialog and let the caller surface
      // the retry Alert inside OrderCart.
      setToId(null);
      onOpenChange(false);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(o) => {
        if (!o) {
          setToId(null);
          setMergeError(null);
        }
        onOpenChange(o);
      }}
    >
      <DialogContent className="flex max-h-[90vh] w-[95vw] !max-w-5xl flex-col p-0">
        <DialogHeader className="flex flex-row items-start gap-3 shrink-0 border-b px-6 py-4">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
            <ArrowRightLeftIcon className="size-5" />
          </span>
          <div className="flex-1 space-y-1">
            <div className="flex items-center gap-1.5">
              <DialogTitle>{t("pos.dialog.change_table.title")}</DialogTitle>
              <HelpButton topic="change-table" className="size-7" />
            </div>
            <DialogDescription>
              {t("pos.dialog.change_table.desc", { fromTable: fromTable.name ?? fromTable.code })}
            </DialogDescription>
          </div>
        </DialogHeader>

        <div className="flex-1 overflow-y-auto px-6 py-4">
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-[3fr_2fr]">
            {/* Left column — table picker */}
            <div className="space-y-2">
              <Label>{t("pos.dialog.create_order.table")}</Label>
              <div className="rounded-md border">
                <TablePicker
                  tables={tables}
                  selectedId={toId}
                  onSelect={(t) => setToId(t.id)}
                  disabledTableIds={new Set([fromTable.id])}
                  maxHeightClass="h-72"
                />
              </div>
            </div>

            {/* Right column — from→to summary + warning */}
            <div className="space-y-4">
              <div className="space-y-2">
                <Label>{t("pos.dialog.change_table.title")}</Label>
                <div className="rounded-md border bg-muted/30 px-4 py-4">
                  <div className="flex items-center justify-center gap-3">
                    <div className="flex flex-col items-center gap-1">
                      <span className="text-[10px] uppercase tracking-wide text-muted-foreground">
                        {t("pos.dialog.change_table.from")}
                      </span>
                      <span className="rounded-md bg-background px-3 py-2 text-base font-bold text-foreground shadow-sm">
                        {fromTable.name ?? fromTable.code}
                      </span>
                    </div>
                    <ArrowRightIcon className="mt-5 size-5 shrink-0 text-muted-foreground" />
                    <div className="flex flex-col items-center gap-1">
                      <span className="text-[10px] uppercase tracking-wide text-muted-foreground">
                        {t("pos.dialog.change_table.to")}
                      </span>
                      <span
                        className={
                          "rounded-md px-3 py-2 text-base font-bold shadow-sm " +
                          (toId
                            ? "bg-primary/10 text-primary"
                            : "bg-background text-muted-foreground")
                        }
                      >
                        {toId
                          ? (tables.find((tt) => tt.id === toId)?.name ??
                            tables.find((tt) => tt.id === toId)?.code ??
                            "—")
                          : "—"}
                      </span>
                    </div>
                  </div>
                </div>
              </div>

              {mergeError && (
                <Alert variant="destructive">
                  <AlertDescription>{mergeError}</AlertDescription>
                </Alert>
              )}

              <div className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-500/30 dark:bg-amber-500/5">
                <AlertTriangleIcon className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-500" />
                <p className="text-[11px] leading-relaxed text-amber-900 dark:text-amber-200">
                  {t("pos.dialog.change_table.warning", { fromTable: fromTable.name ?? fromTable.code }).replace(/^⚠\s*/, "")}
                </p>
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
            disabled={!toId || submitting}
            style={{padding: "12px 35px", borderRadius: "10px"}}
          >
            {submitting && <Spinner className="size-4" />}
            {submitting ? t("pos.dialog.change_table.state") : t("pos.dialog.change_table.confirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
