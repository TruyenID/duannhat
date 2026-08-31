"use client";

import { useParams } from "next/navigation";
import { useState } from "react";
import { Play } from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";

import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { Pagination } from "@/components/shared/pagination";
import { useRecallDrills, useRunDrill } from "@/hooks/api/use-recall-drills";
import { formatDateTime } from "@/lib/date";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import type { RecallDrill } from "@/services/recall-drill-service";
import { Button, Spinner, StatusBadge } from "@godxjp/ui";

export default function HqRecallDrillsPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const params = useParams<{ brandSlug: string }>();

  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  const { data, isLoading } = useRecallDrills(params.brandSlug, { page, per_page: perPage });
  const runDrill = useRunDrill(params.brandSlug);

  const columns: ColumnDef<RecallDrill>[] = [
    {
      header: "STT",
      cell: ({ row }) => row.index + 1 + (page - 1) * perPage,
      size: 60,
    },
    {
      accessorKey: "started_at",
      header: t("recall_drill.col_started_at"),
      cell: ({ row }) =>
        row.original.started_at ? formatDateTime(row.original.started_at, locale, timezone) : "—",
    },
    {
      accessorKey: "selected_lot",
      header: t("recall_drill.col_lot_code"),
      cell: ({ row }) => row.original.selected_lot?.lot_code ?? "—",
    },
    {
      accessorKey: "elapsed_seconds",
      header: t("recall_drill.col_elapsed"),
      cell: ({ row }) =>
        row.original.elapsed_seconds != null ? (
          <span className="tabular-nums">
            {t("recall_drill.elapsed_seconds", { seconds: row.original.elapsed_seconds })}
          </span>
        ) : (
          "—"
        ),
    },
    {
      accessorKey: "affected_lots_count",
      header: t("recall_drill.col_affected_lots"),
      cell: ({ row }) => <span className="tabular-nums">{row.original.affected_lots_count}</span>,
    },
    {
      accessorKey: "affected_orders_count",
      header: t("recall_drill.col_affected_orders"),
      cell: ({ row }) => <span className="tabular-nums">{row.original.affected_orders_count}</span>,
    },
    {
      accessorKey: "completeness_percent",
      header: t("recall_drill.col_completeness"),
      cell: ({ row }) => (
        <span className="tabular-nums">{Number(row.original.completeness_percent)}%</span>
      ),
    },
    {
      accessorKey: "completed_at",
      header: t("recall_drill.col_status"),
      cell: ({ row }) => (
        <StatusBadge
          status={
            row.original.completed_at
              ? t("recall_drill.status_completed")
              : t("recall_drill.status_running")
          }
        />
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t("recall_drill.page_title")}
        description={t("recall_drill.page_description")}
      >
        <Button disabled={runDrill.isPending} onClick={() => runDrill.mutate({})}>
          {runDrill.isPending ? (
            <Spinner className="mr-2 size-4" />
          ) : (
            <Play className="mr-2 size-4" />
          )}
          {t("recall_drill.run_button")}
        </Button>
      </PageHeader>
      <PageContent>
        {isLoading ? (
          <DataTableSkeleton columns={columns.length} rows={6} />
        ) : (
          <>
            <DataTable
              columns={columns}
              data={data?.data ?? []}
              emptyMessage={t("recall_drill.empty")}
            />
            {data?.meta ? (
              <Pagination
                meta={data.meta}
                page={page}
                onPageChange={setPage}
                perPage={perPage}
                onPerPageChange={(n) => {
                  setPerPage(n);
                  setPage(1);
                }}
              />
            ) : null}
          </>
        )}
      </PageContent>
    </>
  );
}
