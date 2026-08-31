"use client";

import { useParams } from "next/navigation";
import { useMemo, useState } from "react";
import { localDateString } from "@/lib/date";
import { AlertCircle, ArrowDown, ArrowUp, ArrowUpDown, Download, Search } from "lucide-react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { useSupplierYieldReport } from "@/hooks/api/use-reports";
import { useTranslation } from "@/providers/app-provider";
import type { SupplierYieldRow } from "@/services/report-service";
import {
  Alert,
  AlertDescription,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Input,
  Spinner,
} from "@godxjp/ui";

function defaultDateRange() {
  const from = new Date();
  from.setDate(from.getDate() - 30);
  // localDateString (LOCAL date), not toISOString (UTC) — in +offset zones the
  // UTC date is yesterday before 09:00 local, silently dropping today's data.
  return {
    date_from: localDateString(from),
    date_to: localDateString(),
  };
}

type SortKey = keyof Pick<
  SupplierYieldRow,
  | "supplier_name"
  | "lots_count"
  | "total_qty_consumed"
  | "total_actual_yield"
  | "yield_percent"
  | "variance_from_planned"
>;

const COLUMNS: Array<{ key: SortKey; labelKey: string; align: "left" | "right" }> = [
  { key: "supplier_name", labelKey: "col_supplier", align: "left" },
  { key: "lots_count", labelKey: "col_lots", align: "right" },
  { key: "total_qty_consumed", labelKey: "col_consumed", align: "right" },
  { key: "total_actual_yield", labelKey: "col_yield", align: "right" },
  { key: "yield_percent", labelKey: "col_yield_percent", align: "right" },
  { key: "variance_from_planned", labelKey: "col_variance", align: "right" },
];

/** RFC-4180 field escaping for CSV export. */
function csvCell(value: string | number): string {
  const s = String(value);
  return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

export default function SupplierYieldReportPage() {
  const { t } = useTranslation();
  const params = useParams<{ brandSlug: string }>();

  const defaults = defaultDateRange();
  const [dateFrom, setDateFrom] = useState(defaults.date_from);
  const [dateTo, setDateTo] = useState(defaults.date_to);
  const [supplierName, setSupplierName] = useState("");

  const [activeFilters, setActiveFilters] = useState({
    date_from: defaults.date_from,
    date_to: defaults.date_to,
    supplier_name: undefined as string | undefined,
  });

  const [sortKey, setSortKey] = useState<SortKey | null>(null);
  const [sortDir, setSortDir] = useState<"asc" | "desc">("asc");

  const { data, isLoading, isError, refetch } = useSupplierYieldReport(
    params.brandSlug,
    activeFilters
  );

  function handleSearch() {
    setActiveFilters({
      date_from: dateFrom,
      date_to: dateTo,
      supplier_name: supplierName.trim() || undefined,
    });
  }

  function toggleSort(key: SortKey) {
    if (sortKey === key) {
      setSortDir((d) => (d === "asc" ? "desc" : "asc"));
    } else {
      setSortKey(key);
      setSortDir("asc");
    }
  }

  const suppliers = useMemo(() => data?.suppliers ?? [], [data]);

  // Client-side sort over the already-filtered rows (TC-REPYIELD-105). String
  // column sorts lexicographically; numeric columns compare as numbers
  // (total_qty_consumed / total_actual_yield arrive as numeric strings).
  const sortedSuppliers = useMemo(() => {
    if (!sortKey) return suppliers;
    const arr = [...suppliers];
    arr.sort((a, b) => {
      const cmp =
        sortKey === "supplier_name"
          ? String(a[sortKey]).localeCompare(String(b[sortKey]))
          : Number(a[sortKey]) - Number(b[sortKey]);
      return sortDir === "asc" ? cmp : -cmp;
    });
    return arr;
  }, [suppliers, sortKey, sortDir]);

  // Export the currently-loaded (filtered + sorted) rows as CSV. Filename
  // carries the active date range so the file reflects the applied filter
  // (TC-REPYIELD-106). BOM prefix keeps Japanese supplier names readable in
  // Excel.
  function handleExportCsv() {
    const header = COLUMNS.map((c) => csvCell(t(`report.supplier_yield.${c.labelKey}`)));
    const lines = [
      header.join(","),
      ...sortedSuppliers.map((r) =>
        [
          r.supplier_name,
          r.lots_count,
          r.total_qty_consumed,
          r.total_actual_yield,
          r.yield_percent,
          r.variance_from_planned,
        ]
          .map(csvCell)
          .join(",")
      ),
    ];
    const csv = "\uFEFF" + lines.join("\n");
    const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `supplier-yield_${activeFilters.date_from}_${activeFilters.date_to}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  }

  return (
    <>
      <PageHeader
        title={t("report.supplier_yield.title")}
        description={t("report.supplier_yield.description")}
      />
      <PageContent>
        <Card>
          <CardHeader>
            <CardTitle>{t("report.supplier_yield.title")}</CardTitle>
          </CardHeader>
          <CardContent>
            {/* Filters */}
            <div className="mb-6 flex flex-wrap items-end gap-4">
              <div className="flex flex-col gap-1">
                <label className="text-sm font-medium">
                  {t("report.supplier_yield.date_from")}
                </label>
                <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
              </div>
              <div className="flex flex-col gap-1">
                <label className="text-sm font-medium">{t("report.supplier_yield.date_to")}</label>
                <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
              </div>
              <div className="flex flex-col gap-1">
                <label className="text-sm font-medium">
                  {t("report.supplier_yield.supplier_filter")}
                </label>
                <Input
                  type="text"
                  value={supplierName}
                  onChange={(e) => setSupplierName(e.target.value)}
                  placeholder={t("report.supplier_yield.supplier_placeholder")}
                />
              </div>
              <Button onClick={handleSearch}>
                <Search className="mr-2 size-4" />
                {t("report.supplier_yield.search")}
              </Button>
              <Button
                variant="outline"
                onClick={handleExportCsv}
                disabled={sortedSuppliers.length === 0}
              >
                <Download className="mr-2 size-4" />
                {t("report.supplier_yield.export")}
              </Button>
            </div>

            {/* Error state */}
            {isError ? (
              <Alert variant="destructive" className="mb-4">
                <AlertCircle className="size-4" />
                <AlertDescription className="flex items-center justify-between">
                  {t("common.error_loading")}
                  <Button variant="outline" size="sm" onClick={() => refetch()}>
                    {t("common.retry")}
                  </Button>
                </AlertDescription>
              </Alert>
            ) : null}

            {/* Table */}
            {isLoading ? (
              <div className="flex items-center justify-center py-12">
                <Spinner className="size-6" />
              </div>
            ) : isError ? null : sortedSuppliers.length === 0 ? (
              <p className="py-12 text-center text-sm text-muted-foreground">
                {t("report.supplier_yield.empty")}
              </p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left">
                      {COLUMNS.map((col) => {
                        const active = sortKey === col.key;
                        const Icon = !active
                          ? ArrowUpDown
                          : sortDir === "asc"
                            ? ArrowUp
                            : ArrowDown;
                        return (
                          <th
                            key={col.key}
                            className={`px-3 py-2 font-medium ${
                              col.align === "right" ? "text-right" : "text-left"
                            }`}
                          >
                            <button
                              type="button"
                              onClick={() => toggleSort(col.key)}
                              className={`inline-flex items-center gap-1 hover:text-foreground ${
                                col.align === "right" ? "flex-row-reverse" : ""
                              } ${active ? "text-foreground" : "text-muted-foreground"}`}
                            >
                              {t(`report.supplier_yield.${col.labelKey}`)}
                              <Icon
                                className={`size-3 ${active ? "" : "text-muted-foreground/50"}`}
                              />
                            </button>
                          </th>
                        );
                      })}
                    </tr>
                  </thead>
                  <tbody>
                    {sortedSuppliers.map((row) => (
                      <tr key={row.supplier_name} className="border-b">
                        <td className="px-3 py-2 font-medium">{row.supplier_name}</td>
                        <td className="px-3 py-2 text-right tabular-nums">
                          {row.lots_count.toLocaleString()}
                        </td>
                        <td className="px-3 py-2 text-right tabular-nums">
                          {Number(row.total_qty_consumed).toLocaleString()}
                        </td>
                        <td className="px-3 py-2 text-right tabular-nums">
                          {Number(row.total_actual_yield).toLocaleString()}
                        </td>
                        <td className="px-3 py-2 text-right tabular-nums">
                          {row.yield_percent.toLocaleString(undefined, {
                            minimumFractionDigits: 1,
                            maximumFractionDigits: 1,
                          })}
                          %
                        </td>
                        <td
                          className={`px-3 py-2 text-right font-medium tabular-nums ${
                            row.variance_from_planned > 0
                              ? "text-emerald-600"
                              : row.variance_from_planned < 0
                                ? "text-red-600"
                                : ""
                          }`}
                        >
                          {row.variance_from_planned > 0 ? "+" : ""}
                          {row.variance_from_planned.toLocaleString(undefined, {
                            minimumFractionDigits: 1,
                            maximumFractionDigits: 1,
                          })}
                          %
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>
      </PageContent>
    </>
  );
}
