"use client";

import { Download } from "lucide-react";
import {
  Badge,
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  ScrollArea,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@godxjp/ui";

import { formatDateTime } from "@/lib/date";
import { useTranslation } from "@/providers/app-provider";
import { isLeafNode, isLotNode, type TraceLotNode, type TraceNode } from "@/services/trace-service";

// =========================================================================
//  Flatten the trace tree into affected entities (shared with the page)
// =========================================================================

export interface TraceRow {
  /** "lot" for a material lot, otherwise the leaf_type of a terminal node. */
  type: "lot" | "customer_order" | "disposal" | "manual_adjustment";
  lot_code: string;
  material_sku: string;
  warehouse: string;
  status: string;
  source: string;
  qty: string;
  unit: string;
  reference: string;
}

// Flatten the trace tree into a de-duplicated list of affected lots (by lot_id)
// plus terminal leaves (customer orders / disposals). Cycle/truncated markers
// are skipped — they're not affected entities.
export function flattenTrace(roots: TraceLotNode[]): TraceRow[] {
  const rows: TraceRow[] = [];
  const seenLots = new Set<string>();

  const addLot = (n: TraceLotNode) => {
    if (seenLots.has(n.lot_id)) return;
    seenLots.add(n.lot_id);
    rows.push({
      type: "lot",
      lot_code: n.lot_code,
      material_sku: n.material.sku ?? n.material.id,
      warehouse: n.warehouse.name,
      status: n.status,
      source: n.source,
      qty: String(n.qty_on_hand),
      unit: n.unit ?? "",
      reference: "",
    });
  };

  const walk = (nodes: TraceNode[]) => {
    for (const n of nodes) {
      if (isLotNode(n)) {
        addLot(n);
        walk(n.parents);
        walk(n.children);
      } else if (isLeafNode(n)) {
        rows.push({
          type: n.leaf_type,
          lot_code: "",
          material_sku: "",
          warehouse: "",
          status: "",
          source: "",
          qty: String(n.edge.qty_consumed),
          unit: n.edge.unit ?? "",
          reference: n.customer_order_id ?? n.edge.source_event_id ?? "",
        });
      }
    }
  };

  for (const root of roots) {
    addLot(root);
    walk(root.parents);
    walk(root.children);
  }
  return rows;
}

// =========================================================================
//  CSV serialisation (localised headers + values)
// =========================================================================

const csvEscape = (v: string) => `"${v.replace(/"/g, '""')}"`;

function downloadCsv(filename: string, csv: string) {
  // Prepend a UTF-8 BOM so Excel renders non-ASCII (ja/vi) correctly.
  const blob = new Blob(["﻿" + csv], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}

// =========================================================================
//  Dialog
// =========================================================================

export interface TraceExportDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Human label of what was traced (lot code / order id) — shown + in CSV. */
  subject: string;
  /** CSV filename (without metadata). */
  filename: string;
  rows: TraceRow[];
}

const STATUS_VARIANT: Record<string, "default" | "outline"> = {
  active: "default",
  quarantined: "outline",
  expired: "outline",
  depleted: "outline",
  disposed: "outline",
};

export function TraceExportDialog({
  open,
  onOpenChange,
  subject,
  filename,
  rows,
}: TraceExportDialogProps) {
  const { t, locale } = useTranslation();

  const typeLabel = (type: string) => t(`trace.entity_type.${type}`);
  const statusLabel = (status: string) => (status ? t(`material_lot.status.${status}`) : "");
  const sourceLabel = (source: string) => (source ? t(`trace.source.${source}`) : "");
  const qtyLabel = (r: TraceRow) => [r.qty, r.unit].filter(Boolean).join(" ");

  const handleExport = () => {
    const headers = [
      t("trace.export_col_no"),
      t("trace.export_col_type"),
      t("trace.export_col_lot_code"),
      t("trace.export_col_material"),
      t("trace.export_col_warehouse"),
      t("trace.export_col_status"),
      t("trace.export_col_source"),
      t("trace.export_col_qty"),
      t("trace.export_col_unit"),
      t("trace.export_col_reference"),
    ];

    const body = rows.map((r, i) => [
      String(i + 1),
      typeLabel(r.type),
      r.lot_code,
      r.material_sku,
      r.warehouse,
      statusLabel(r.status),
      sourceLabel(r.source),
      r.qty,
      r.unit,
      r.reference,
    ]);

    // A small metadata block above the table makes the spreadsheet
    // self-describing (what was traced, when, how many rows).
    const meta: string[][] = [
      [t("trace.export_dialog_title")],
      [t("trace.export_subject"), subject],
      [t("trace.export_generated_at"), formatDateTime(new Date().toISOString(), locale)],
      [t("trace.export_total_label"), String(rows.length)],
      [],
      headers,
      ...body,
    ];

    const csv = meta.map((line) => line.map(csvEscape).join(",")).join("\r\n");
    downloadCsv(filename, csv);
    onOpenChange(false);
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        data-slot="trace-export-dialog"
        className="flex max-h-[85vh] flex-col gap-0 p-0 sm:max-w-3xl"
      >
        <DialogHeader className="border-b px-6 py-4">
          <DialogTitle>{t("trace.export_dialog_title")}</DialogTitle>
          <DialogDescription>{t("trace.export_dialog_desc")}</DialogDescription>
        </DialogHeader>

        {/* Metadata summary */}
        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 px-6 py-4 text-sm sm:grid-cols-3">
          <div className="space-y-0.5">
            <dt className="text-xs text-muted-foreground">{t("trace.export_subject")}</dt>
            <dd className="truncate font-medium" title={subject}>
              {subject}
            </dd>
          </div>
          <div className="space-y-0.5">
            <dt className="text-xs text-muted-foreground">{t("trace.export_total_label")}</dt>
            <dd className="font-medium tabular-nums">{rows.length}</dd>
          </div>
          <div className="space-y-0.5">
            <dt className="text-xs text-muted-foreground">{t("trace.export_generated_at")}</dt>
            <dd className="font-medium tabular-nums">
              {formatDateTime(new Date().toISOString(), locale)}
            </dd>
          </div>
        </dl>

        {/* Preview table */}
        {rows.length === 0 ? (
          <p className="px-6 py-10 text-center text-sm text-muted-foreground">
            {t("trace.export_empty")}
          </p>
        ) : (
          <ScrollArea className="min-h-0 flex-1 border-t">
            <Table>
              <TableHeader className="sticky top-0 bg-background">
                <TableRow>
                  <TableHead className="w-12 text-right">{t("trace.export_col_no")}</TableHead>
                  <TableHead>{t("trace.export_col_type")}</TableHead>
                  <TableHead>{t("trace.export_col_lot_code")}</TableHead>
                  <TableHead>{t("trace.export_col_material")}</TableHead>
                  <TableHead>{t("trace.export_col_warehouse")}</TableHead>
                  <TableHead>{t("trace.export_col_status")}</TableHead>
                  <TableHead>{t("trace.export_col_source")}</TableHead>
                  <TableHead className="text-right">{t("trace.export_col_qty")}</TableHead>
                  <TableHead>{t("trace.export_col_reference")}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((r, i) => (
                  <TableRow key={`${r.type}-${r.lot_code}-${r.reference}-${i}`}>
                    <TableCell className="text-right text-muted-foreground tabular-nums">
                      {i + 1}
                    </TableCell>
                    <TableCell>
                      <Badge variant="outline">{typeLabel(r.type)}</Badge>
                    </TableCell>
                    <TableCell className="font-medium">{r.lot_code || "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{r.material_sku || "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{r.warehouse || "—"}</TableCell>
                    <TableCell>
                      {r.status ? (
                        <Badge variant={STATUS_VARIANT[r.status] ?? "outline"}>
                          {statusLabel(r.status)}
                        </Badge>
                      ) : (
                        "—"
                      )}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {sourceLabel(r.source) || "—"}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">{qtyLabel(r) || "—"}</TableCell>
                    <TableCell
                      className="max-w-[12rem] truncate font-mono text-xs"
                      title={r.reference}
                    >
                      {r.reference || "—"}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </ScrollArea>
        )}

        <DialogFooter className="border-t px-6 py-4">
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t("common.cancel")}
          </Button>
          <Button onClick={handleExport} disabled={rows.length === 0}>
            <Download className="mr-2 size-4" />
            {t("trace.export_download")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
