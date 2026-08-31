"use client";

import { EllipsisVertical, SlidersHorizontal } from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";
import {
  Badge,
  Button,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import { DataTable } from "@/components/shared/data-table";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import { StockAlertStatus, StockAlertType, type StockAlert } from "@/services/stock-alert-service";

export interface StockAlertListTableProps {
  data: StockAlert[];
  emptyMessage?: string;
  /**
   * Plan-024 — invoked when the user clicks the "Configure threshold"
   * action on an alert row. The page-level parent owns the sheet open
   * state and pre-fills it from the alert. Pass `undefined` to hide
   * the action column entirely (e.g. when the user lacks the
   * stock-levels.update permission).
   */
  onConfigureThreshold?: (alert: StockAlert) => void;
}

function TypeBadge({ type }: { type: StockAlertType }) {
  const { t } = useTranslation();
  const map: Record<StockAlertType, { className: string; label: string }> = {
    [StockAlertType.LowStock]: {
      className:
        "bg-amber-100 text-amber-700 hover:bg-amber-100 dark:bg-amber-900 dark:text-amber-100",
      label: t("shop.stock.alerts.type.low_stock"),
    },
    [StockAlertType.OutOfStock]: {
      className: "bg-red-100 text-red-700 hover:bg-red-100 dark:bg-red-900 dark:text-red-100",
      label: t("shop.stock.alerts.type.out_of_stock"),
    },
  };
  const { className, label } = map[type];
  return <Badge className={className}>{label}</Badge>;
}

function StatusBadge({ status }: { status: StockAlertStatus }) {
  const { t } = useTranslation();
  const map: Record<
    StockAlertStatus,
    { variant: "default" | "outline" | "destructive"; label: string }
  > = {
    [StockAlertStatus.Active]: {
      variant: "destructive",
      label: t("shop.stock.alerts.status.active"),
    },
    [StockAlertStatus.Resolved]: {
      variant: "outline",
      label: t("shop.stock.alerts.status.resolved"),
    },
  };
  const { variant, label } = map[status];
  return <Badge variant={variant}>{label}</Badge>;
}

function itemLabel(alert: StockAlert): { sku: string; name: string; type: "variant" | "material" } {
  if (alert.productSku) {
    return {
      sku: alert.productSku.sku ?? "—",
      name: alert.productSku.name ?? alert.productSku.sku ?? "—",
      type: "variant",
    };
  }
  if (alert.material) {
    return {
      sku: alert.material.sku ?? "—",
      name: alert.material.name ?? "—",
      type: "material",
    };
  }
  return { sku: "—", name: "—", type: "variant" };
}

export function StockAlertListTable({
  data,
  emptyMessage,
  onConfigureThreshold,
}: StockAlertListTableProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const itemTypeLabel = (type: "variant" | "material") =>
    type === "variant"
      ? t("shop.stock.alerts.item_type.variant")
      : t("shop.stock.alerts.item_type.material");
  const columns: ColumnDef<StockAlert>[] = [
    {
      id: "type",
      header: t("shop.stock.alerts.col.alert"),
      size: 130,
      cell: ({ row }) => <TypeBadge type={row.original.alert_type} />,
    },
    {
      id: "sku",
      header: t("shop.stock.alerts.col.sku"),
      size: 130,
      cell: ({ row }) => <span className="font-mono text-xs">{itemLabel(row.original).sku}</span>,
    },
    {
      id: "name",
      header: t("shop.stock.alerts.col.name"),
      size: 240,
      cell: ({ row }) => itemLabel(row.original).name,
    },
    {
      id: "item_type",
      header: t("shop.stock.alerts.col.type"),
      size: 90,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground capitalize">
          {itemTypeLabel(itemLabel(row.original).type)}
        </span>
      ),
    },
    {
      id: "warehouse",
      header: t("shop.stock.alerts.col.warehouse"),
      size: 160,
      cell: ({ row }) => row.original.warehouse?.name ?? "—",
    },
    {
      id: "status",
      header: t("shop.stock.alerts.col.status"),
      size: 100,
      cell: ({ row }) => <StatusBadge status={row.original.status} />,
    },
    {
      id: "created_at",
      header: t("shop.stock.alerts.col.raised"),
      size: 150,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {formatDate(row.original.created_at, locale, timezone)}
        </span>
      ),
    },
    {
      id: "resolved_at",
      header: t("shop.stock.alerts.col.resolved"),
      size: 150,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {formatDate(row.original.resolved_at, locale, timezone)}
        </span>
      ),
    },
  ];

  // Plan-024 — append actions column only when the parent passed the
  // configure-threshold callback (i.e. the user has the permission).
  if (onConfigureThreshold) {
    columns.push({
      id: "actions",
      header: "",
      size: 60,
      cell: ({ row }) => {
        const alert = row.original;
        // Hide the row's action menu when the underlying StockLevel
        // could not be resolved (historical alert pointing at a
        // soft-deleted level).
        if (!alert.stock_level_id) return null;
        return (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="sm" className="size-7 p-0">
                <EllipsisVertical className="size-3.5" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onClick={() => onConfigureThreshold(alert)}>
                <SlidersHorizontal className="mr-2 size-3.5" />
                {t("shop.stock.alerts.menu.configure_threshold")}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        );
      },
    });
  }

  return (
    <DataTable
      columns={columns}
      data={data}
      emptyMessage={emptyMessage ?? t("shop.stock.alerts.empty_list")}
    />
  );
}
