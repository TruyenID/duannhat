"use client";

import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import { Badge } from "@godxjp/ui";
import { DataTable } from "@/components/shared/data-table";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import {
  StockTransactionStatus,
  StockTransactionType,
  type StockTransaction,
} from "@/services/stock-transaction-service";

export interface StockTransactionListTableProps {
  shopSlug: string;
  data: StockTransaction[];
  emptyMessage?: string;
}

function StatusBadge({ status }: { status: StockTransactionStatus }) {
  const { t } = useTranslation();
  const map: Record<
    StockTransactionStatus,
    { variant: "default" | "outline" | "secondary" | "destructive"; label: string }
  > = {
    [StockTransactionStatus.Draft]: {
      variant: "outline",
      label: t("shop.stock.transactions.status.draft"),
    },
    [StockTransactionStatus.Pending]: {
      variant: "secondary",
      label: t("shop.stock.transactions.status.pending"),
    },
    [StockTransactionStatus.Approved]: {
      variant: "default",
      label: t("shop.stock.transactions.status.approved"),
    },
    [StockTransactionStatus.Completed]: {
      variant: "default",
      label: t("shop.stock.transactions.status.completed"),
    },
    [StockTransactionStatus.Cancelled]: {
      variant: "destructive",
      label: t("shop.stock.transactions.status.cancelled"),
    },
  };
  const { variant, label } = map[status];
  return <Badge variant={variant}>{label}</Badge>;
}

function TypeBadge({ type }: { type: StockTransactionType }) {
  const { t } = useTranslation();
  const map: Record<StockTransactionType, { className: string; label: string }> = {
    [StockTransactionType.StockIn]: {
      className:
        "bg-emerald-100 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900 dark:text-emerald-100",
      label: t("shop.stock.transactions.stock_in"),
    },
    [StockTransactionType.StockOut]: {
      className:
        "bg-orange-100 text-orange-700 hover:bg-orange-100 dark:bg-orange-900 dark:text-orange-100",
      label: t("shop.stock.transactions.stock_out"),
    },
  };
  const { className, label } = map[type];
  return <Badge className={className}>{label}</Badge>;
}

function formatSubType(subType: string | undefined): string {
  if (!subType) return "—";
  return subType.replace(/_/g, " ");
}

export function StockTransactionListTable({
  shopSlug,
  data,
  emptyMessage,
}: StockTransactionListTableProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const columns: ColumnDef<StockTransaction>[] = [
    {
      id: "code",
      header: t("shop.stock.transactions.col.code"),
      size: 160,
      cell: ({ row }) => (
        <Link
          href={`/shop/${shopSlug}/stock/transactions/${row.original.id}`}
          className="font-mono text-xs text-primary hover:underline"
        >
          {row.original.transaction_code}
        </Link>
      ),
    },
    {
      id: "type",
      header: t("shop.stock.transactions.col.type"),
      size: 100,
      cell: ({ row }) => <TypeBadge type={row.original.type} />,
    },
    {
      id: "sub_type",
      header: t("shop.stock.transactions.col.sub_type"),
      size: 120,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground capitalize">
          {formatSubType(row.original.sub_type)}
        </span>
      ),
    },
    {
      id: "warehouse",
      header: t("shop.stock.transactions.col.warehouse"),
      size: 160,
      cell: ({ row }) => row.original.warehouse?.name ?? "—",
    },
    {
      id: "items",
      header: t("shop.stock.transactions.col.items"),
      size: 70,
      cell: ({ row }) => <span className="tabular-nums">{row.original.items?.length ?? 0}</span>,
    },
    {
      id: "status",
      header: t("shop.stock.transactions.col.status"),
      size: 110,
      cell: ({ row }) => <StatusBadge status={row.original.status} />,
    },
    {
      id: "created_at",
      header: t("shop.stock.transactions.col.created"),
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
      emptyMessage={emptyMessage ?? t("shop.stock.transactions.empty_list")}
    />
  );
}
