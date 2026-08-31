"use client";

/**
 * PrintQrPostersDialog — pick tables, then print their QR ordering posters.
 *
 * The template is 74×105mm (ISO A7). Posters tile edge-to-edge onto the chosen
 * paper size (A4 = 2×2 = 4, A3 = 4×4 = 16, …); the fit is derived per paper via
 * `postersPerSheet`, so:
 *     sheets = ceil(selectedTables / postersPerSheet(paper).perPage)
 *
 * Printing uses the classic portal-to-body + `@media print` trick: an offscreen
 * `#qr-poster-print-root` (a direct child of <body>) holds the paginated
 * posters; during print we hide every other body child and reveal only it.
 */

import { useMemo, useState } from "react";
import { createPortal } from "react-dom";
import { Printer } from "lucide-react";
import {
  Button,
  Checkbox,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  ScrollArea,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import type { TableResource, ZoneResource } from "@/types/shop";
import { POSTER_HEIGHT_MM, POSTER_WIDTH_MM, TableQrPoster } from "./table-qr-poster";

/**
 * Selectable print paper sizes (portrait, millimetres). The poster template is
 * a fixed 74×105mm (A7); how many tile onto a sheet is derived per paper size
 * (`postersPerSheet` below), so bigger paper simply fits more without shrinking
 * the QR. Explicit mm dims (not CSS keywords like `A4`) are used for `@page` so
 * the print grid always matches the physical sheet — and to sidestep ISO/JIS
 * B-size ambiguity (these B4/B5 values are the JIS sizes common on JP printers).
 */
export interface PaperSize {
  id: string;
  label: string;
  widthMm: number;
  heightMm: number;
}

export const PAPER_SIZES: PaperSize[] = [
  { id: "a4", label: "A4", widthMm: 210, heightMm: 297 },
  { id: "a3", label: "A3", widthMm: 297, heightMm: 420 },
  { id: "b4", label: "B4 (JIS)", widthMm: 257, heightMm: 364 },
  { id: "legal", label: "Legal", widthMm: 216, heightMm: 356 },
  { id: "letter", label: "Letter", widthMm: 216, heightMm: 279 },
  { id: "b5", label: "B5 (JIS)", widthMm: 182, heightMm: 257 },
  { id: "a5", label: "A5", widthMm: 148, heightMm: 210 },
];

export const DEFAULT_PAPER_ID = "a4";

/** How many 74×105mm posters tile onto a sheet (columns × rows), edge-to-edge. */
export function postersPerSheet(paper: PaperSize): { cols: number; rows: number; perPage: number } {
  const cols = Math.max(1, Math.floor(paper.widthMm / POSTER_WIDTH_MM));
  const rows = Math.max(1, Math.floor(paper.heightMm / POSTER_HEIGHT_MM));
  return { cols, rows, perPage: cols * rows };
}

export interface PrintQrPostersDialogProps {
  shopSlug: string;
  /** Footer brand label on each poster (defaults to the shop name). */
  brandLabel?: string;
  zones: ZoneResource[];
  tables: TableResource[];
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

function chunk<T>(items: T[], size: number): T[][] {
  const pages: T[][] = [];
  for (let i = 0; i < items.length; i += size) pages.push(items.slice(i, i + size));
  return pages;
}

export function PrintQrPostersDialog({
  shopSlug,
  brandLabel,
  zones,
  tables,
  open,
  onOpenChange,
}: PrintQrPostersDialogProps) {
  const { t } = useTranslation();

  const [paperId, setPaperId] = useState(DEFAULT_PAPER_ID);
  const paper = useMemo(
    () => PAPER_SIZES.find((p) => p.id === paperId) ?? PAPER_SIZES[0],
    [paperId],
  );
  const { cols, perPage } = useMemo(() => postersPerSheet(paper), [paper]);

  // Only tables that carry a QR token can be printed.
  const printable = useMemo(() => tables.filter((tb) => Boolean(tb.qr_token)), [tables]);
  const excludedCount = tables.length - printable.length;

  const [selected, setSelected] = useState<Set<string>>(new Set());

  // Reset selection to "all printable" each time the dialog opens. Uses the
  // React-recommended render-phase reset (track previous `open`) rather than an
  // effect, so there's no cascading-render round-trip.
  const [prevOpen, setPrevOpen] = useState(open);
  if (open !== prevOpen) {
    setPrevOpen(open);
    if (open) setSelected(new Set(printable.map((tb) => tb.id)));
  }

  const selectedTables = useMemo(
    () => printable.filter((tb) => selected.has(tb.id)),
    [printable, selected],
  );
  const sheets = Math.ceil(selectedTables.length / perPage);

  const zonesWithTables = useMemo(
    () =>
      zones
        .map((zone) => ({ zone, items: printable.filter((tb) => tb.zone.id === zone.id) }))
        .filter((g) => g.items.length > 0),
    [zones, printable],
  );

  const toggle = (id: string) =>
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });

  const toggleZone = (ids: string[], on: boolean) =>
    setSelected((prev) => {
      const next = new Set(prev);
      for (const id of ids) {
        if (on) next.add(id);
        else next.delete(id);
      }
      return next;
    });

  const allSelected = printable.length > 0 && selected.size === printable.length;
  const setAll = (on: boolean) =>
    setSelected(on ? new Set(printable.map((tb) => tb.id)) : new Set());

  const handlePrint = () => {
    if (selectedTables.length === 0) return;
    window.print();
  };

  const preview = selectedTables[0];
  const pages = chunk(selectedTables, perPage);

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>{t("shop.tables.print_qr.title")}</DialogTitle>
            <DialogDescription>{t("shop.tables.print_qr.description")}</DialogDescription>
          </DialogHeader>

          {printable.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">
              {t("shop.tables.print_qr.no_printable")}
            </p>
          ) : (
            <div className="grid gap-4 sm:grid-cols-[1fr_auto]">
              {/* Selection list */}
              <div className="min-w-0">
                <label className="mb-2 flex cursor-pointer items-center gap-2 text-sm font-medium">
                  <Checkbox
                    checked={allSelected}
                    onCheckedChange={(v) => setAll(v === true)}
                    aria-label={t("shop.tables.print_qr.select_all")}
                  />
                  {t("shop.tables.print_qr.select_all")}
                </label>
                <ScrollArea className="h-64 rounded-md border">
                  <div className="space-y-3 p-3">
                    {zonesWithTables.map(({ zone, items }) => {
                      const ids = items.map((tb) => tb.id);
                      const zoneOn = ids.every((id) => selected.has(id));
                      return (
                        <div key={zone.id}>
                          <label className="flex cursor-pointer items-center gap-2 text-xs font-semibold text-muted-foreground">
                            <Checkbox
                              checked={zoneOn}
                              onCheckedChange={(v) => toggleZone(ids, v === true)}
                              aria-label={zone.name}
                            />
                            {zone.name}
                            <span className="font-normal">({items.length})</span>
                          </label>
                          <div className="mt-1.5 grid grid-cols-2 gap-1.5 pl-6 sm:grid-cols-3">
                            {items.map((tb) => (
                              <label
                                key={tb.id}
                                className="flex cursor-pointer items-center gap-1.5 text-sm"
                              >
                                <Checkbox
                                  checked={selected.has(tb.id)}
                                  onCheckedChange={() => toggle(tb.id)}
                                  aria-label={tb.code}
                                />
                                <span className="truncate">{tb.code}</span>
                              </label>
                            ))}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </ScrollArea>
              </div>

              {/* Live preview of one poster (scaled down) */}
              <div className="hidden sm:block">
                <p className="mb-2 text-xs font-medium text-muted-foreground">
                  {t("shop.tables.print_qr.preview")}
                </p>
                <div
                  className="overflow-hidden rounded-md border bg-muted/30"
                  style={{ width: "44.4mm", height: "63mm" }}
                >
                  {preview && (
                    <div style={{ transform: "scale(0.6)", transformOrigin: "top left" }}>
                      <TableQrPoster
                        shopSlug={shopSlug}
                        qrToken={preview.qr_token}
                        tableCode={preview.code}
                        brandLabel={brandLabel}
                      />
                    </div>
                  )}
                </div>
              </div>
            </div>
          )}

          {printable.length > 0 && (
            <div className="flex flex-wrap items-center justify-between gap-3">
              <p className="text-sm text-muted-foreground">
                {t("shop.tables.print_qr.summary", {
                  tables: selectedTables.length,
                  sheets,
                  paper: paper.label,
                })}
                {excludedCount > 0 &&
                  ` · ${t("shop.tables.print_qr.excluded", { count: excludedCount })}`}
              </p>
              <label className="flex items-center gap-2 text-sm font-medium">
                {t("shop.tables.print_qr.paper_size")}
                <Select value={paperId} onValueChange={setPaperId}>
                  <SelectTrigger className="h-8 w-44 text-xs">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {PAPER_SIZES.map((p) => (
                      <SelectItem key={p.id} value={p.id}>
                        {p.label} · {t("shop.tables.print_qr.per_page", {
                          count: postersPerSheet(p).perPage,
                        })}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </label>
            </div>
          )}

          <DialogFooter>
            <Button variant="outline" size="sm" onClick={() => onOpenChange(false)}>
              {t("common.cancel")}
            </Button>
            <Button size="sm" onClick={handlePrint} disabled={selectedTables.length === 0}>
              <Printer className="mr-1.5 size-3.5" />
              {t("shop.tables.print_qr.print", { sheets })}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Off-screen print surface — revealed only by the @media print rules.
          Only mounts once the dialog is open (always post-hydration), so no
          SSR/mounted guard is needed. */}
      {open &&
        selectedTables.length > 0 &&
        typeof document !== "undefined" &&
        createPortal(
          <div id="qr-poster-print-root" style={{ display: "none" }}>
            <style>{printCss(paper)}</style>
            {pages.map((pageTables, pageIdx) => (
              <div
                key={pageIdx}
                style={{
                  width: `${paper.widthMm}mm`,
                  height: `${paper.heightMm}mm`,
                  boxSizing: "border-box",
                  display: "grid",
                  gridTemplateColumns: `repeat(${cols}, ${POSTER_WIDTH_MM}mm)`,
                  gridAutoRows: `${POSTER_HEIGHT_MM}mm`,
                  justifyContent: "center",
                  alignContent: "center",
                  pageBreakAfter: pageIdx < pages.length - 1 ? "always" : "auto",
                  breakAfter: pageIdx < pages.length - 1 ? "page" : "auto",
                }}
              >
                {pageTables.map((tb) => (
                  <TableQrPoster
                    key={tb.id}
                    shopSlug={shopSlug}
                    qrToken={tb.qr_token}
                    tableCode={tb.code}
                    brandLabel={brandLabel}
                    className="outline outline-[0.2mm] outline-dashed outline-gray-300"
                  />
                ))}
              </div>
            ))}
          </div>,
          document.body,
        )}
    </>
  );
}

/** Print CSS with an `@page` size pinned to the chosen paper (explicit mm). */
function printCss(paper: PaperSize): string {
  return `
@media print {
  @page { size: ${paper.widthMm}mm ${paper.heightMm}mm; margin: 0; }
  html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
  body > *:not(#qr-poster-print-root) { display: none !important; }
  #qr-poster-print-root { display: block !important; }
}
`;
}
