"use client";
import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import { EllipsisVertical, Pencil, RotateCcw, Trash2 } from "lucide-react";
import { DataTable } from "@/components/shared/data-table";
import { customerFullName, type Customer } from "@/services/customer-service";
import { StatusBadge } from "@godxjp/ui";
import { Button } from "@godxjp/ui";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";

export interface CustomerListTableProps {
  data: Customer[];
  shopSlug: string;
  /** First row index on the current page (1-based). Used to keep STT stable across pages. */
  fromIndex?: number;
  emptyMessage?: string;
  onDelete?: (id: string) => void;
  onRestore?: (id: string) => void;
}

export function CustomerListTable({
  data,
  shopSlug,
  fromIndex = 1,
  emptyMessage,
  onDelete,
  onRestore,
}: CustomerListTableProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

  const columns: ColumnDef<Customer>[] = [
    {
      id: "stt",
      header: t("shop.customers.col.stt"),
      size: 50,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">{fromIndex + row.index}</span>
      ),
    },
    {
      id: "name",
      header: t("common.name"),
      size: 220,
      cell: ({ row }) => (
        <Link
          href={`/shop/${shopSlug}/customers/${row.original.id}`}
          className="font-medium text-primary hover:underline"
        >
          {customerFullName(row.original) || "—"}
        </Link>
      ),
    },
    {
      id: "phone",
      header: t("common.phone"),
      size: 140,
      cell: ({ row }) => <span className="text-sm tabular-nums">{row.original.phone ?? "—"}</span>,
    },
    {
      id: "email",
      header: t("common.email"),
      size: 220,
      cell: ({ row }) => <span className="text-sm">{row.original.email ?? "—"}</span>,
    },
    {
      // #1718 — số dư ví điểm của khách. Là ví TOÀN BRAND (BR-PT04), không
      // phải điểm tích riêng ở chi nhánh này; nhãn giải thích nằm trong khối
      // "Điểm & đổi thưởng" ở trang chi tiết khách.
      id: "point_balance",
      header: t("hq.customers.col.points"),
      size: 90,
      cell: ({ row }) => (
        <span className="text-sm tabular-nums">
          {(row.original.point_balance ?? 0).toLocaleString()}
        </span>
      ),
    },
    {
      id: "status",
      header: t("common.status"),
      size: 90,
      cell: ({ row }) => <StatusBadge status={row.original.deleted_at ? "deleted" : "active"} />,
    },
    {
      accessorKey: "updated_at",
      header: t("common.updated"),
      size: 130,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {formatDate(row.original.updated_at, locale, timezone)}
        </span>
      ),
    },
    {
      id: "actions",
      size: 50,
      header: t("common.action"),
      cell: ({ row }) => {
        const c = row.original;
        return (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="size-7">
                <EllipsisVertical className="size-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {c.deleted_at ? (
                <DropdownMenuItem onClick={() => onRestore?.(c.id)}>
                  <RotateCcw className="mr-2 size-3.5" /> {t("common.restore")}
                </DropdownMenuItem>
              ) : (
                <>
                  <DropdownMenuItem asChild>
                    <Link href={`/shop/${shopSlug}/customers/${c.id}/edit`}>
                      <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                    </Link>
                  </DropdownMenuItem>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem className="text-destructive" onClick={() => onDelete?.(c.id)}>
                    <Trash2 className="mr-2 size-3.5" /> {t("common.delete")}
                  </DropdownMenuItem>
                </>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        );
      },
    },
  ];

  return (
    <div data-slot="customer-list-table">
      <DataTable
        columns={columns}
        data={data}
        emptyMessage={emptyMessage ?? t("shop.customers.empty")}
      />
    </div>
  );
}
