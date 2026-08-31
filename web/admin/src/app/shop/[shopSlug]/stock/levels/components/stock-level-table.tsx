"use client";

import type { ColumnDef } from "@tanstack/react-table";
import { Badge } from "@godxjp/ui";
import { DataTable } from "@/components/shared/data-table";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import type { StockLevel } from "@/services/stock-level-service";

export interface StockLevelListTableProps {
  data: StockLevel[];
  emptyMessage?: string;
}

type StatusTone = "normal" | "low" | "out";

// Mirrors the `stock_status` enum computed on the backend (server-side
// authoritative). Recomputed on the client so rows already fetched are still
// colour-coded correctly when a filter is applied without a refetch.
function computeStatus(level: StockLevel): StatusTone {
  const qty = Number(level.quantity);
  if (qty <= 0) return "out";
  if (level.min_stock != null && qty <= Number(level.min_stock)) return "low";
  return "normal";
}

function StatusBadge({ status }: { status: StatusTone }) {
  const { t } = useTranslation();
  const map = {
    normal: { variant: "default" as const, label: t("shop.stock.levels.status.in_stock") },
    low: { variant: "outline" as const, label: t("shop.stock.levels.status.low") },
    out: { variant: "destructive" as const, label: t("shop.stock.levels.status.out") },
  };
  const { variant, label } = map[status];
  return <Badge variant={variant}>{label}</Badge>;
}

// A StockLevel row points to either a ProductSku (sellable variant) or a
// Material (raw input) — exactly one. Collapse both into a uniform
// { sku, name, type } triplet for the table.
function itemLabel(level: StockLevel): { sku: string; name: string; type: "variant" | "material" } {
  if (level.productSku) {
    return {
      sku: level.productSku.sku ?? "—",
      name: level.productSku.name ?? level.productSku.sku ?? "—",
      type: "variant",
    };
  }
  if (level.material) {
    return {
      sku: level.material.sku ?? "—",
      name: level.material.name ?? "—",
      type: "material",
    };
  }
  return { sku: "—", name: "—", type: "variant" };
}

export function StockLevelListTable({
  data,
  emptyMessage = "No stock levels match the current filters.",
}: StockLevelListTableProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const columns: ColumnDef<StockLevel>[] = [
    {
      id: "sku",
      header: "SKU",
      size: 130,
      cell: ({ row }) => {
        const { sku } = itemLabel(row.original);
        return <span className="font-mono text-xs">{sku}</span>;
      },
    },
    {
      id: "name",
      header: t("common.name"),
      size: 240,
      cell: ({ row }) => itemLabel(row.original).name,
    },
    {
      id: "type",
      header: t("common.type"),
      size: 90,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground capitalize">
          {itemLabel(row.original).type}
        </span>
      ),
    },
    {
      id: "warehouse",
      header: t("common.warehouse"),
      size: 140,
      cell: ({ row }) => row.original.warehouse?.name ?? "—",
    },
    {
      // Plan-017 MOD-4 — lot identity. NULL row = legacy pre-plan-017
      // bucket; rendered as a muted "Legacy" pill so ops can spot
      // un-tracked stock at a glance.
      id: "lot",
      header: t("shop.stock.levels.col.lot"),
      size: 160,
      cell: ({ row }) => {
        const lot = (
          row.original as unknown as {
            materialLot?: { lot_code?: string; expiry_date?: string | null };
          }
        ).materialLot;
        const lotId = (row.original as unknown as { material_lot_id?: string | null })
          .material_lot_id;
        const isMaterialRow = !row.original.productSku;
        if (!lotId) {
          return isMaterialRow ? (
            <Badge variant="outline" className="text-muted-foreground">
              {t("shop.stock.levels.legacy_lot")}
            </Badge>
          ) : (
            <span className="text-xs text-muted-foreground">—</span>
          );
        }
        return <span className="font-mono text-xs">{lot?.lot_code ?? lotId.slice(0, 8)}</span>;
      },
    },
    {
      id: "expiry",
      header: t("shop.stock.levels.col.expiry"),
      size: 120,
      cell: ({ row }) => {
        const lot = (
          row.original as unknown as {
            materialLot?: { expiry_date?: string | null };
          }
        ).materialLot;
        if (!lot?.expiry_date) {
          return <span className="text-xs text-muted-foreground">—</span>;
        }
        return (
          <span className="text-xs tabular-nums">
            {formatDate(lot.expiry_date, locale, timezone)}
          </span>
        );
      },
    },
    {
      id: "quantity",
      header: t("shop.stock.levels.col.qty"),
      size: 90,
      cell: ({ row }) => (
        <span className="font-medium tabular-nums">
          {Number(row.original.quantity).toLocaleString()}
        </span>
      ),
    },
    {
      id: "unit",
      header: t("shop.stock.levels.col.unit"),
      size: 60,
      cell: ({ row }) => row.original.unit ?? "—",
    },
    {
      id: "min_max",
      header: t("shop.stock.levels.col.min_max"),
      size: 110,
      cell: ({ row }) => {
        const { min_stock, max_stock } = row.original;
        if (min_stock == null && max_stock == null) return "—";
        return (
          <span className="text-xs text-muted-foreground tabular-nums">
            {min_stock ?? "—"} / {max_stock ?? "—"}
          </span>
        );
      },
    },
    {
      id: "status",
      header: t("common.status"),
      size: 100,
      cell: ({ row }) => <StatusBadge status={computeStatus(row.original)} />,
    },
  ];

  return <DataTable columns={columns} data={data} emptyMessage={emptyMessage} />;
}
