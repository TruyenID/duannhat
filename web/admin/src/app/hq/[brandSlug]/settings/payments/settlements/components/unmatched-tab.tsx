"use client";

import { useMemo } from "react";
import type { ColumnDef } from "@tanstack/react-table";
import { Badge, Input, Label } from "@godxjp/ui";

import { DataTable } from "@/components/shared/data-table";
import { Pagination } from "@/components/shared/pagination";
import { settlementService } from "@/services/settlement-service";
import { formatDateTime } from "@/lib/date";
import { useSettlementRows } from "@/hooks/api/use-settlements";
import type { SettlementRow } from "@/services/settlement-service";
import { useTimezone, useTranslation } from "@/providers/app-provider";
import { distinctValues, humanizeCode, type SettlementTabProps } from "../lib/settlement-view";
import { MinorAmount } from "./minor-amount";
import { SettlementPanel } from "./settlement-panel";
import { SettlementToolbar } from "./settlement-toolbar";

export interface UnmatchedTabProps extends SettlementTabProps {
  settledFrom: string;
  settledTo: string;
}

/**
 * Settlement lines the reconciler could not tie to any order payment — the
 * `unmatched=1` slice of the settlement index (`order_payment_id IS NULL`).
 *
 * These are the rows that cost real money to ignore: the gateway charged, or
 * paid out, something this system has no record of. The tab is hard-wired to
 * `unmatched: true` rather than offering a toggle, so the reading is never
 * ambiguous — every row on screen is an exception, and an empty table here is
 * the good outcome, not a missing filter.
 */
export function UnmatchedTab({
  brandSlug,
  connections,
  connectionId,
  status,
  page,
  perPage,
  settledFrom,
  settledTo,
  setFilter,
  setPage,
}: UnmatchedTabProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

  const filters = useMemo(
    () => ({
      page,
      per_page: perPage,
      unmatched: true,
      connection_id: connectionId === "all" ? undefined : connectionId,
      status: status === "all" ? undefined : status,
      settled_from: settledFrom || undefined,
      settled_to: settledTo || undefined,
    }),
    [page, perPage, connectionId, status, settledFrom, settledTo]
  );

  const { data, isLoading, isFetching, isError, error, refetch } = useSettlementRows(
    brandSlug,
    filters
  );

  const rows = useMemo(() => data?.data ?? [], [data]);
  const statusOptions = useMemo(() => distinctValues(rows, (r) => r.status), [rows]);

  const columns: ColumnDef<SettlementRow>[] = useMemo(
    () => [
      {
        id: "settled_at",
        header: t("hq.settlements.field.provider_settled_at"),
        cell: ({ row }) => (
          <span className="text-xs">
            {row.original.provider_settled_at
              ? formatDateTime(row.original.provider_settled_at, locale, timezone)
              : "—"}
          </span>
        ),
      },
      {
        id: "provider",
        header: t("hq.settlements.field.provider"),
        cell: ({ row }) => humanizeCode(row.original.provider),
      },
      {
        id: "kind",
        header: t("hq.settlements.field.kind"),
        cell: ({ row }) => <Badge variant="outline">{humanizeCode(row.original.kind)}</Badge>,
      },
      {
        id: "external_ref",
        header: t("hq.settlements.field.external_ref"),
        cell: ({ row }) => (
          <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
            {row.original.external_ref ?? "—"}
          </code>
        ),
      },
      {
        id: "gross",
        header: t("hq.settlements.field.gross"),
        cell: ({ row }) => (
          <MinorAmount minor={row.original.gross_minor} currency={row.original.currency} />
        ),
      },
      {
        id: "fee",
        header: t("hq.settlements.field.fee"),
        cell: ({ row }) => (
          <MinorAmount minor={row.original.fee_minor} currency={row.original.currency} muteZero />
        ),
      },
      {
        id: "net",
        header: t("hq.settlements.field.net"),
        cell: ({ row }) => (
          <MinorAmount
            minor={row.original.net_minor}
            currency={row.original.currency}
            className="font-medium"
          />
        ),
      },
      {
        id: "status",
        header: t("common.status"),
        cell: ({ row }) => <Badge variant="secondary">{humanizeCode(row.original.status)}</Badge>,
      },
      {
        id: "source",
        header: t("hq.settlements.field.source"),
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">{humanizeCode(row.original.source)}</span>
        ),
      },
    ],
    [t, locale, timezone]
  );

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

    await settlementService.downloadCsv(brandSlug, "settlements", {
      ...exportFilters,
        unmatched: true,
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
      >
        <div className="flex items-center gap-1.5">
          <Label htmlFor="settled-from" className="text-xs text-muted-foreground">
            {t("hq.settlements.filter.settled_from")}
          </Label>
          <Input
            id="settled-from"
            type="date"
            value={settledFrom}
            onChange={(e) => setFilter("settled_from", e.target.value)}
            className="h-8 w-36 text-xs"
          />
        </div>
        <div className="flex items-center gap-1.5">
          <Label htmlFor="settled-to" className="text-xs text-muted-foreground">
            {t("hq.settlements.filter.settled_to")}
          </Label>
          <Input
            id="settled-to"
            type="date"
            value={settledTo}
            onChange={(e) => setFilter("settled_to", e.target.value)}
            className="h-8 w-36 text-xs"
          />
        </div>
      </SettlementToolbar>

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
        <DataTable
          columns={columns}
          data={rows}
          emptyMessage={t("hq.settlements.unmatched.empty")}
        />
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
