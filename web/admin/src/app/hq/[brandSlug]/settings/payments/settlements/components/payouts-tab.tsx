"use client";

import { useMemo } from "react";
import type { ColumnDef } from "@tanstack/react-table";
import { Badge } from "@godxjp/ui";

import { DataTable } from "@/components/shared/data-table";
import { Pagination } from "@/components/shared/pagination";
import { settlementService } from "@/services/settlement-service";
import { formatDate, formatDateTime } from "@/lib/date";
import { useSettlementPayouts } from "@/hooks/api/use-settlements";
import type { SettlementPayoutRow } from "@/services/settlement-service";
import { useTimezone, useTranslation } from "@/providers/app-provider";
import { distinctValues, humanizeCode, type SettlementTabProps } from "../lib/settlement-view";
import { MinorAmount } from "./minor-amount";
import { SettlementPanel } from "./settlement-panel";
import { SettlementToolbar } from "./settlement-toolbar";

/**
 * Money that actually left the gateway for a bank account.
 *
 * `net_minor` is the figure that should equal the bank deposit, so it is the
 * emphasised column. Gross and fee sit beside it as the provider reported them
 * — the UI does not check that gross − fee = net, because if the provider's own
 * report disagrees with itself that is a fact to surface, not a sum to correct.
 */
export function PayoutsTab({
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

  const { data, isLoading, isFetching, isError, error, refetch } = useSettlementPayouts(
    brandSlug,
    filters
  );

  const rows = useMemo(() => data?.data ?? [], [data]);
  const statusOptions = useMemo(() => distinctValues(rows, (r) => r.status), [rows]);

  const columns: ColumnDef<SettlementPayoutRow>[] = useMemo(
    () => [
      {
        id: "external_payout_id",
        header: t("hq.settlements.field.external_payout_id"),
        cell: ({ row }) => (
          <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
            {row.original.external_payout_id ?? "—"}
          </code>
        ),
      },
      {
        id: "provider",
        header: t("hq.settlements.field.provider"),
        cell: ({ row }) => humanizeCode(row.original.provider),
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
        id: "expected",
        header: t("hq.settlements.field.expected_arrival"),
        cell: ({ row }) => (
          <span className="text-xs">
            {row.original.expected_arrival_date
              ? formatDate(row.original.expected_arrival_date, locale, timezone)
              : "—"}
          </span>
        ),
      },
      {
        id: "paid_at",
        header: t("hq.settlements.field.paid_at"),
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">
            {row.original.paid_at ? formatDateTime(row.original.paid_at, locale, timezone) : "—"}
          </span>
        ),
      },
      {
        id: "bank_ref",
        header: t("hq.settlements.field.bank_ref"),
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">{row.original.bank_ref ?? "—"}</span>
        ),
      },
    ],
    [t, locale, timezone]
  );

  // Amounts export as the raw minor integer plus a currency column. A formatted
  // "¥1,234" lands in Excel as text, and "1.234" is ambiguous between a
  // thousands group and a decimal point depending on who opens the file.
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

    await settlementService.downloadCsv(brandSlug, "payouts", {
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
        <DataTable columns={columns} data={rows} emptyMessage={t("hq.settlements.payouts.empty")} />
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
