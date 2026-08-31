"use client";

import type { ColumnDef } from "@tanstack/react-table";
import { MoreHorizontal, Pencil, Power, PowerOff, RotateCcw, Trash2 } from "lucide-react";
import {
  Badge,
  Button,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import { DataTable } from "@/components/shared/data-table";
import { WarehouseType, type Warehouse } from "@/services/warehouse-service";
import { useTranslation } from "@/providers/app-provider";

export interface WarehouseListTableProps {
  data: Warehouse[];
  onEdit: (w: Warehouse) => void;
  onDelete: (w: Warehouse) => void;
  onRestore: (w: Warehouse) => void;
  onToggleActive: (w: Warehouse) => void;
  emptyMessage?: string;
}

const TYPE_BADGE: Record<WarehouseType, { className: string }> = {
  [WarehouseType.Main]: {
    className: "bg-sky-100 text-sky-700 hover:bg-sky-100 dark:bg-sky-900 dark:text-sky-100",
  },
  [WarehouseType.Branch]: {
    className:
      "bg-emerald-100 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900 dark:text-emerald-100",
  },
  [WarehouseType.Production]: {
    className:
      "bg-orange-100 text-orange-700 hover:bg-orange-100 dark:bg-orange-900 dark:text-orange-100",
  },
};

function TypeBadge({ type, label }: { type: WarehouseType; label: string }) {
  const { className } = TYPE_BADGE[type];
  return <Badge className={className}>{label}</Badge>;
}

export function WarehouseListTable({
  data,
  onEdit,
  onDelete,
  onRestore,
  onToggleActive,
  emptyMessage = "No warehouses match the current filters.",
}: WarehouseListTableProps) {
  const { t } = useTranslation();

  const typeLabel: Record<WarehouseType, string> = {
    [WarehouseType.Main]: t("shop.warehouses.type.main"),
    [WarehouseType.Branch]: t("shop.warehouses.type.branch"),
    [WarehouseType.Production]: t("shop.warehouses.type.production"),
  };

  const columns: ColumnDef<Warehouse>[] = [
    {
      id: "code",
      header: t("common.code"),
      size: 130,
      cell: ({ row }) => <span className="font-mono text-xs">{row.original.code}</span>,
    },
    {
      id: "name",
      header: t("common.name"),
      size: 240,
      cell: ({ row }) => <span className="font-medium">{row.original.name}</span>,
    },
    {
      id: "type",
      header: t("common.type"),
      size: 120,
      cell: ({ row }) => (
        <TypeBadge
          type={row.original.type}
          label={typeLabel[row.original.type] ?? row.original.type}
        />
      ),
    },
    {
      id: "branch",
      header: t("shop.warehouses.col.branch"),
      size: 150,
      // `Branch` generated type has no `name` field yet (backend has a
      // placeholder schema). The Eloquent response does include `name`
      // at runtime, so we cast through unknown to keep the display
      // useful until the schema is filled in.
      cell: ({ row }) => {
        const branch = row.original.branch as
          | { id: string; name?: string | null }
          | null
          | undefined;
        return branch?.name ?? (branch?.id ? branch.id.slice(0, 8) : "—");
      },
    },
    {
      id: "members",
      header: t("shop.warehouses.col.members"),
      size: 90,
      cell: ({ row }) => <span className="tabular-nums">{row.original.members?.length ?? 0}</span>,
    },
    {
      id: "status",
      header: t("common.status"),
      size: 110,
      cell: ({ row }) => {
        if (row.original.deleted_at) {
          return <Badge variant="destructive">{t("shop.warehouses.status.deleted")}</Badge>;
        }
        return row.original.is_active ? (
          <Badge className="bg-emerald-100 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900 dark:text-emerald-100">
            {t("common.active")}
          </Badge>
        ) : (
          <Badge variant="outline">{t("common.inactive")}</Badge>
        );
      },
    },
    {
      id: "actions",
      header: "",
      size: 60,
      cell: ({ row }) => {
        const w = row.original;
        const deleted = !!w.deleted_at;
        return (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="sm" className="size-7 p-0">
                <MoreHorizontal className="size-3.5" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {deleted ? (
                <DropdownMenuItem onClick={() => onRestore(w)}>
                  <RotateCcw className="mr-2 size-3.5" />
                  {t("common.restore")}
                </DropdownMenuItem>
              ) : (
                <>
                  <DropdownMenuItem onClick={() => onEdit(w)}>
                    <Pencil className="mr-2 size-3.5" />
                    {t("common.edit")}
                  </DropdownMenuItem>
                  <DropdownMenuItem onClick={() => onToggleActive(w)}>
                    {w.is_active ? (
                      <>
                        <PowerOff className="mr-2 size-3.5" />
                        {t("common.deactivate")}
                      </>
                    ) : (
                      <>
                        <Power className="mr-2 size-3.5" />
                        {t("common.activate")}
                      </>
                    )}
                  </DropdownMenuItem>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem
                    onClick={() => onDelete(w)}
                    className="text-red-600 focus:text-red-600"
                  >
                    <Trash2 className="mr-2 size-3.5" />
                    {t("common.delete")}
                  </DropdownMenuItem>
                </>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        );
      },
    },
  ];

  return <DataTable columns={columns} data={data} emptyMessage={emptyMessage} />;
}
