"use client";

import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import { Badge } from "@godxjp/ui";
import { DataTable } from "@/components/shared/data-table";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import { ProductionOrderStatus, type ProductionOrder } from "@/services/production-order-service";

export interface ProductionOrderListTableProps {
  shopSlug: string;
  data: ProductionOrder[];
  emptyMessage?: string;
}

// Shares the colour semantics with MaterialBatch's table so the two
// production-side lists feel consistent.
function useStatusBadgeMap() {
  const { t } = useTranslation();
  const STATUS_BADGE: Record<
    ProductionOrderStatus,
    {
      variant: "default" | "outline" | "secondary" | "destructive";
      className?: string;
      label: string;
    }
  > = {
    [ProductionOrderStatus.Draft]: {
      variant: "outline",
      label: t("shop.production.orders.status.draft"),
    },
    [ProductionOrderStatus.Pending]: {
      variant: "secondary",
      label: t("shop.production.orders.status.pending"),
    },
    [ProductionOrderStatus.Approved]: {
      variant: "secondary",
      className: "bg-sky-100 text-sky-700 hover:bg-sky-100 dark:bg-sky-900 dark:text-sky-100",
      label: t("shop.production.orders.status.approved"),
    },
    [ProductionOrderStatus.InProgress]: {
      variant: "secondary",
      className:
        "bg-orange-100 text-orange-700 hover:bg-orange-100 dark:bg-orange-900 dark:text-orange-100",
      label: t("shop.production.orders.status.in_progress"),
    },
    [ProductionOrderStatus.Completed]: {
      variant: "default",
      className:
        "bg-emerald-100 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900 dark:text-emerald-100",
      label: t("shop.production.orders.status.completed"),
    },
    [ProductionOrderStatus.Cancelled]: {
      variant: "destructive",
      label: t("shop.production.orders.status.cancelled"),
    },
  };
  return STATUS_BADGE;
}

function StatusBadge({ status }: { status: ProductionOrderStatus }) {
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

export function ProductionOrderListTable({
  shopSlug,
  data,
  emptyMessage,
}: ProductionOrderListTableProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const fallbackEmpty = t("shop.production.orders.empty_list");
  const columns: ColumnDef<ProductionOrder>[] = [
    {
      id: "code",
      header: t("shop.production.orders.col.code"),
      size: 170,
      cell: ({ row }) => (
        <Link
          href={`/shop/${shopSlug}/production/orders/${row.original.id}`}
          className="font-mono text-xs text-primary hover:underline"
        >
          {row.original.order_code}
        </Link>
      ),
    },
    {
      id: "output",
      header: t("shop.production.orders.col.output_variant"),
      size: 220,
      cell: ({ row }) => row.original.outputVariant?.name ?? row.original.outputVariant?.sku ?? "—",
    },
    {
      id: "warehouse",
      header: t("shop.production.orders.col.warehouse"),
      size: 150,
      cell: ({ row }) => row.original.warehouse?.name ?? "—",
    },
    {
      id: "planned",
      header: t("shop.production.orders.col.planned"),
      size: 130,
      cell: ({ row }) => (
        <span className="tabular-nums">
          {formatNumber(row.original.planned_quantity)} {row.original.output_unit}
        </span>
      ),
    },
    {
      id: "actual",
      header: t("shop.production.orders.col.actual"),
      size: 130,
      cell: ({ row }) => {
        if (row.original.status !== ProductionOrderStatus.Completed) return "—";
        return (
          <span className="font-medium tabular-nums">
            {formatNumber(row.original.actual_quantity)} {row.original.output_unit}
          </span>
        );
      },
    },
    {
      id: "status",
      header: t("shop.production.orders.col.status"),
      size: 120,
      cell: ({ row }) => <StatusBadge status={row.original.status} />,
    },
    {
      id: "created_at",
      header: t("shop.production.orders.col.created"),
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
