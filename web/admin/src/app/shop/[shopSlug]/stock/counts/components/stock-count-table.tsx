"use client";

import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import { Badge } from "@godxjp/ui";
import { DataTable } from "@/components/shared/data-table";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import { StockCountScope, StockCountStatus, type StockCount } from "@/services/stock-count-service";

export interface StockCountListTableProps {
  shopSlug: string;
  data: StockCount[];
  emptyMessage?: string;
}

function StatusBadge({ status }: { status: StockCountStatus }) {
  const { t } = useTranslation();
  const STATUS_BADGE: Record<
    StockCountStatus,
    {
      variant: "default" | "outline" | "secondary" | "destructive";
      className?: string;
      label: string;
    }
  > = {
    [StockCountStatus.Draft]: { variant: "outline", label: t("shop.stock.counts.status.draft") },
    [StockCountStatus.InProgress]: {
      variant: "secondary",
      className: "bg-sky-100 text-sky-700 hover:bg-sky-100 dark:bg-sky-900 dark:text-sky-100",
      label: t("shop.stock.counts.status.in_progress"),
    },
    [StockCountStatus.PendingApproval]: {
      variant: "secondary",
      className:
        "bg-amber-100 text-amber-700 hover:bg-amber-100 dark:bg-amber-900 dark:text-amber-100",
      label: t("shop.stock.counts.status.pending_approval"),
    },
    [StockCountStatus.Approved]: {
      variant: "default",
      className:
        "bg-emerald-100 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900 dark:text-emerald-100",
      label: t("shop.stock.counts.status.approved"),
    },
    [StockCountStatus.Cancelled]: {
      variant: "destructive",
      label: t("shop.stock.counts.status.cancelled"),
    },
  };
  const { variant, className, label } = STATUS_BADGE[status];
  return (
    <Badge variant={variant} className={className}>
      {label}
    </Badge>
  );
}

function ScopeBadge({ scope }: { scope: StockCountScope }) {
  const { t } = useTranslation();
  const label =
    scope === StockCountScope.Full
      ? t("shop.stock.counts.scope.full")
      : t("shop.stock.counts.scope.partial");
  return (
    <Badge
      variant="outline"
      className={
        scope === StockCountScope.Full
          ? "border-slate-300 text-slate-700 dark:border-slate-600 dark:text-slate-200"
          : "border-slate-300 text-slate-600 dark:border-slate-600 dark:text-slate-300"
      }
    >
      {label}
    </Badge>
  );
}

export function StockCountListTable({ shopSlug, data, emptyMessage }: StockCountListTableProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const columns: ColumnDef<StockCount>[] = [
    {
      id: "code",
      header: t("shop.stock.counts.col.code"),
      size: 170,
      cell: ({ row }) => (
        <Link
          href={`/shop/${shopSlug}/stock/counts/${row.original.id}`}
          className="font-mono text-xs text-primary hover:underline"
        >
          {row.original.count_code}
        </Link>
      ),
    },
    {
      id: "warehouse",
      header: t("shop.stock.counts.col.warehouse"),
      size: 160,
      cell: ({ row }) => row.original.warehouse?.name ?? "—",
    },
    {
      id: "scope",
      header: t("shop.stock.counts.col.scope"),
      size: 100,
      cell: ({ row }) => <ScopeBadge scope={row.original.scope} />,
    },
    {
      id: "status",
      header: t("shop.stock.counts.col.status"),
      size: 150,
      cell: ({ row }) => <StatusBadge status={row.original.status} />,
    },
    {
      id: "items",
      header: t("shop.stock.counts.col.items"),
      size: 70,
      cell: ({ row }) => <span className="tabular-nums">{row.original.items?.length ?? 0}</span>,
    },
    {
      id: "created_at",
      header: t("shop.stock.counts.col.created"),
      size: 150,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {formatDate(row.original.created_at, locale, timezone)}
        </span>
      ),
    },
  ];

  return (
    <DataTable
      columns={columns}
      data={data}
      emptyMessage={emptyMessage ?? t("shop.stock.counts.empty_list")}
    />
  );
}
