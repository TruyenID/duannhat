"use client";

import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import { ArrowRight } from "lucide-react";
import { Badge } from "@godxjp/ui";
import { DataTable } from "@/components/shared/data-table";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import { StockTransferStatus, type StockTransfer } from "@/services/stock-transfer-service";

export interface StockTransferListTableProps {
  shopSlug: string;
  data: StockTransfer[];
  emptyMessage?: string;
}

function StatusBadge({ status }: { status: StockTransferStatus }) {
  const { t } = useTranslation();
  const STATUS_BADGE: Record<
    StockTransferStatus,
    {
      variant: "default" | "outline" | "secondary" | "destructive";
      className?: string;
      label: string;
    }
  > = {
    [StockTransferStatus.Draft]: {
      variant: "outline",
      label: t("shop.stock.transfers.status.draft"),
    },
    [StockTransferStatus.Pending]: {
      variant: "secondary",
      label: t("shop.stock.transfers.status.pending"),
    },
    [StockTransferStatus.Approved]: {
      variant: "secondary",
      className: "bg-sky-100 text-sky-700 hover:bg-sky-100 dark:bg-sky-900 dark:text-sky-100",
      label: t("shop.stock.transfers.status.approved"),
    },
    [StockTransferStatus.InTransit]: {
      variant: "secondary",
      className:
        "bg-orange-100 text-orange-700 hover:bg-orange-100 dark:bg-orange-900 dark:text-orange-100",
      label: t("shop.stock.transfers.status.in_transit"),
    },
    [StockTransferStatus.Completed]: {
      variant: "default",
      className:
        "bg-emerald-100 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900 dark:text-emerald-100",
      label: t("shop.stock.transfers.status.completed"),
    },
    [StockTransferStatus.Cancelled]: {
      variant: "destructive",
      label: t("shop.stock.transfers.status.cancelled"),
    },
  };
  const { variant, className, label } = STATUS_BADGE[status];
  return (
    <Badge variant={variant} className={className}>
      {label}
    </Badge>
  );
}

export function StockTransferListTable({
  shopSlug,
  data,
  emptyMessage,
}: StockTransferListTableProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const columns: ColumnDef<StockTransfer>[] = [
    {
      id: "code",
      header: t("shop.stock.transfers.col.code"),
      size: 160,
      cell: ({ row }) => (
        <Link
          href={`/shop/${shopSlug}/stock/transfers/${row.original.id}`}
          className="font-mono text-xs text-primary hover:underline"
        >
          {row.original.transfer_code}
        </Link>
      ),
    },
    {
      id: "source",
      header: t("shop.stock.transfers.col.source"),
      size: 160,
      cell: ({ row }) => row.original.sourceWarehouse?.name ?? "—",
    },
    {
      id: "arrow",
      header: "",
      size: 36,
      cell: () => <ArrowRight aria-hidden className="size-3.5 text-muted-foreground" />,
    },
    {
      id: "destination",
      header: t("shop.stock.transfers.col.destination"),
      size: 160,
      cell: ({ row }) => row.original.destinationWarehouse?.name ?? "—",
    },
    {
      id: "items",
      header: t("shop.stock.transfers.col.items"),
      size: 70,
      cell: ({ row }) => <span className="tabular-nums">{row.original.items?.length ?? 0}</span>,
    },
    {
      id: "status",
      header: t("shop.stock.transfers.col.status"),
      size: 120,
      cell: ({ row }) => <StatusBadge status={row.original.status} />,
    },
    {
      id: "created_at",
      header: t("shop.stock.transfers.col.created"),
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
      emptyMessage={emptyMessage ?? t("shop.stock.transfers.empty_list")}
    />
  );
}
