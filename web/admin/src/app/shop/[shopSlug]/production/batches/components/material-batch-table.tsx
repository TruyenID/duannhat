"use client";

import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import { Badge, Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@godxjp/ui";
import { DataTable } from "@/components/shared/data-table";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import { MaterialBatchStatus, type MaterialBatch } from "@/services/material-batch-service";

export interface MaterialBatchListTableProps {
  shopSlug: string;
  data: MaterialBatch[];
  emptyMessage?: string;
}

// Legacy Inertia Ant colours:
//   draft=default, pending=processing, approved=blue, in_progress=orange,
//   completed=green, cancelled=red
function useStatusBadgeMap() {
  const { t } = useTranslation();
  const STATUS_BADGE: Record<
    MaterialBatchStatus,
    {
      variant: "default" | "outline" | "secondary" | "destructive";
      className?: string;
      label: string;
    }
  > = {
    [MaterialBatchStatus.Draft]: {
      variant: "outline",
      label: t("shop.production.batches.status.draft"),
    },
    [MaterialBatchStatus.Pending]: {
      variant: "secondary",
      label: t("shop.production.batches.status.pending"),
    },
    [MaterialBatchStatus.Approved]: {
      variant: "secondary",
      className: "bg-sky-100 text-sky-700 hover:bg-sky-100 dark:bg-sky-900 dark:text-sky-100",
      label: t("shop.production.batches.status.approved"),
    },
    [MaterialBatchStatus.InProgress]: {
      variant: "secondary",
      className:
        "bg-orange-100 text-orange-700 hover:bg-orange-100 dark:bg-orange-900 dark:text-orange-100",
      label: t("shop.production.batches.status.in_progress"),
    },
    [MaterialBatchStatus.Completed]: {
      variant: "default",
      className:
        "bg-emerald-100 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900 dark:text-emerald-100",
      label: t("shop.production.batches.status.completed"),
    },
    [MaterialBatchStatus.Cancelled]: {
      variant: "destructive",
      label: t("shop.production.batches.status.cancelled"),
    },
  };
  return STATUS_BADGE;
}

function StatusBadge({ status }: { status: MaterialBatchStatus }) {
  const STATUS_BADGE = useStatusBadgeMap();
  const { variant, className, label } = STATUS_BADGE[status];
  return (
    <Badge variant={variant} className={className}>
      {label}
    </Badge>
  );
}

function formatNumber(n: number | null | undefined): string {
  if (n == null) return "—";
  return Number(n).toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 4,
  });
}

export function MaterialBatchListTable({
  shopSlug,
  data,
  emptyMessage,
}: MaterialBatchListTableProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const fallbackEmpty = t("shop.production.batches.empty_list");
  const columns: ColumnDef<MaterialBatch>[] = [
    {
      id: "code",
      header: t("shop.production.batches.col.code"),
      size: 170,
      cell: ({ row }) => (
        <Link
          href={`/shop/${shopSlug}/production/batches/${row.original.id}`}
          className="font-mono text-xs text-primary hover:underline"
        >
          {row.original.batch_code}
        </Link>
      ),
    },
    {
      id: "material",
      header: t("shop.production.batches.col.material"),
      size: 200,
      // MaterialBatch.material is loaded by the backend via eager-loading.
      // Fall back to the FK if a lean endpoint skipped the relation.
      cell: ({ row }) => {
        const batch = row.original as MaterialBatch & {
          material?: { name?: string } | null;
        };
        return batch.material?.name ?? "—";
      },
    },
    {
      id: "warehouse",
      header: t("shop.production.batches.col.warehouse"),
      size: 150,
      cell: ({ row }) => row.original.warehouse?.name ?? "—",
    },
    {
      id: "multiplier",
      header: t("shop.production.batches.col.multiplier"),
      size: 80,
      cell: ({ row }) => (
        <span className="text-muted-foreground tabular-nums">
          ×{formatNumber(row.original.multiplier)}
        </span>
      ),
    },
    {
      id: "planned",
      header: t("shop.production.batches.col.planned"),
      size: 130,
      cell: ({ row }) => (
        <span className="tabular-nums">
          {formatNumber(row.original.planned_yield)} {row.original.yield_unit}
        </span>
      ),
    },
    {
      id: "actual",
      header: t("shop.production.batches.col.actual"),
      size: 130,
      cell: ({ row }) => {
        if (row.original.status !== MaterialBatchStatus.Completed) return "—";
        return (
          <span className="font-medium tabular-nums">
            {formatNumber(row.original.actual_yield)} {row.original.yield_unit}
          </span>
        );
      },
    },
    {
      id: "variance_reason",
      header: t("shop.production.batches.col.variance_reason"),
      size: 200,
      cell: ({ row }) => {
        const reason = row.original.yield_variance_reason;
        if (!reason) return <span className="text-muted-foreground">—</span>;
        return (
          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="line-clamp-1 max-w-[200px] cursor-help text-xs text-amber-700">
                  {reason}
                </span>
              </TooltipTrigger>
              <TooltipContent className="max-w-sm text-xs whitespace-pre-wrap">
                {reason}
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>
        );
      },
    },
    {
      id: "status",
      header: t("shop.production.batches.col.status"),
      size: 120,
      cell: ({ row }) => <StatusBadge status={row.original.status} />,
    },
    {
      id: "created_at",
      header: t("shop.production.batches.col.created"),
      size: 150,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {formatDate(row.original.created_at, locale, timezone)}
        </span>
      ),
    },
  ];

  return <DataTable columns={columns} data={data} emptyMessage={emptyMessage ?? fallbackEmpty} />;
}
