"use client";

import { useMemo } from "react";
import type { ColumnDef } from "@tanstack/react-table";
import { Badge } from "@godxjp/ui";

import { DataTable } from "@/components/shared/data-table";
import { Pagination } from "@/components/shared/pagination";
import { settlementService } from "@/services/settlement-service";
import { formatDateTime } from "@/lib/date";
import { useSettlementBatches } from "@/hooks/api/use-settlements";
import type { SettlementBatchRow } from "@/services/settlement-service";
import { useTimezone, useTranslation } from "@/providers/app-provider";
import { distinctValues, humanizeCode, type SettlementTabProps } from "../lib/settlement-view";
import { SettlementPanel } from "./settlement-panel";
import { SettlementToolbar } from "./settlement-toolbar";

/**
 * Provider report files that have been imported, with their match tally.
 *
 * `orphan_count > 0` is the operational signal on this tab: rows the provider
 * billed that the reconciler could not tie to a payment we recorded. It gets a
 * badge, not red text — an orphan is a thing to go look at, not a system error.
 */
export function BatchesTab({
  brandSlug,
  connections,
  connectionId,
  status,
  page,
  perPage,
  setFilter,
  setPage,
}: SettlementTabProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

  const filters = useMemo(
    () => ({
      page,
      per_page: perPage,
      connection_id: connectionId === "all" ? undefined : connectionId,
      status: status === "all" ? undefined : status,
    }),
    [page, perPage, connectionId, status]
  );

  const { data, isLoading, isFetching, isError, error, refetch } = useSettlementBatches(
    brandSlug,
    filters
  );

  const rows = useMemo(() => data?.data ?? [], [data]);
  const statusOptions = useMemo(() => distinctValues(rows, (r) => r.status), [rows]);

  const columns: ColumnDef<SettlementBatchRow>[] = useMemo(
    () => [
      {
        id: "cycle",
        header: t("hq.settlements.field.cycle_label"),
        cell: ({ row }) => <span className="font-medium">{row.original.cycle_label ?? "—"}</span>,
      },
      {
        id: "provider",
        header: t("hq.settlements.field.provider"),
        cell: ({ row }) => humanizeCode(row.original.provider),
      },
      {
        id: "rows",
        header: t("hq.settlements.field.row_count"),
        cell: ({ row }) => <span className="tabular-nums">{row.original.row_count}</span>,
      },
      {
        id: "matched",
        header: t("hq.settlements.field.matched_count"),
        cell: ({ row }) => <span className="tabular-nums">{row.original.matched_count}</span>,
      },
      {
        id: "orphans",
        header: t("hq.settlements.field.orphan_count"),
        cell: ({ row }) =>
          row.original.orphan_count > 0 ? (
            <Badge color="warning" variant="soft" className="tabular-nums">
              {row.original.orphan_count}
            </Badge>
          ) : (
            <span className="text-muted-foreground tabular-nums">0</span>
          ),
      },
      {
        id: "status",
        header: t("common.status"),
        cell: ({ row }) => <Badge variant="secondary">{humanizeCode(row.original.status)}</Badge>,
      },
      {
        id: "imported",
        header: t("hq.settlements.field.imported_at"),
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">
            {row.original.imported_at
              ? formatDateTime(row.original.imported_at, locale, timezone)
              : "—"}
          </span>
        ),
      },
    ],
    [t, locale, timezone]
  );

  // Raw ids + integer counts, not the rendered labels: an accounting export is
  // read by a spreadsheet, and a localized label cannot be joined back to the
  // provider's own report.
  /**
   * Xuất CSV do SERVER sinh (#1157).
   *
   * Bản trước dựng CSV tại đây từ `rows` — tức chỉ những dòng đang hiển thị —
   * và đặt số trang vào tên file (`..._page-3.csv`). Đó là cách trung thực nhất
   * có thể làm khi backend chưa có endpoint export, nhưng nó vẫn để kế toán nộp
   * một phần dữ liệu: file mở được, cộng ra một con số, và con số đó sai mà
   * không có dấu hiệu nào.
   *
   * Endpoint server không phân trang, nên gửi nguyên bộ lọc đang áp và BỎ
   * `page`/`per_page` — giữ chúng lại là tái tạo đúng lỗi vừa sửa.
   */
  async function handleExport() {
    const { page: _page, per_page: _perPage, ...exportFilters } = filters;

    await settlementService.downloadCsv(brandSlug, "batches", {
      ...exportFilters,
    });
  }

  return (
    <div className="flex flex-col gap-3">
      <SettlementToolbar
        scopeNote={t("hq.settlements.export.scope_note_server")}
        connections={connections}
        connectionId={connectionId}
        onConnectionChange={(v) => setFilter("connection_id", v)}
        statusOptions={statusOptions}
        status={status}
        onStatusChange={(v) => setFilter("status", v)}
        onExport={handleExport}
        exportDisabled={rows.length === 0}
      />

      <SettlementPanel
        isLoading={isLoading}
        isFetching={isFetching}
        isError={isError}
        error={error}
        hasData={data !== undefined}
        isEmpty={rows.length === 0}
        columns={columns.length}
        onRetry={() => void refetch()}
      >
        <DataTable columns={columns} data={rows} emptyMessage={t("hq.settlements.batches.empty")} />
      </SettlementPanel>

      {data ? (
        <Pagination
          meta={data.meta}
          page={page}
          onPageChange={setPage}
          perPage={perPage}
          onPerPageChange={(v) => setFilter("per_page", String(v))}
        />
      ) : null}
    </div>
  );
}
