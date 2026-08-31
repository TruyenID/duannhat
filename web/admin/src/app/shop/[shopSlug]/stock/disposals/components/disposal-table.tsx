"use client";

import Link from "next/link";
import type { ColumnDef } from "@tanstack/react-table";
import { MoreHorizontal, CheckCircle2, SendHorizontal, XCircle, Trash2 } from "lucide-react";
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
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import {
  StockTransactionStatus,
  type StockTransaction,
} from "@/services/stock-transaction-service";

export interface DisposalListTableProps {
  shopSlug: string;
  data: StockTransaction[];
  emptyMessage?: string;
  onSubmit: (id: string) => void;
  onApprove: (id: string) => void;
  onCancel: (id: string) => void;
  onDelete: (id: string) => void;
}

function StatusBadge({ status }: { status: StockTransactionStatus }) {
  const { t } = useTranslation();
  const STATUS_BADGE: Record<
    StockTransactionStatus,
    {
      variant: "default" | "outline" | "secondary" | "destructive";
      className?: string;
      label: string;
    }
  > = {
    [StockTransactionStatus.Draft]: {
      variant: "outline",
      label: t("shop.stock.disposals.status.draft"),
    },
    [StockTransactionStatus.Pending]: {
      variant: "secondary",
      label: t("shop.stock.disposals.status.pending"),
    },
    [StockTransactionStatus.Approved]: {
      variant: "secondary",
      className: "bg-sky-100 text-sky-700 hover:bg-sky-100 dark:bg-sky-900 dark:text-sky-100",
      label: t("shop.stock.disposals.status.approved"),
    },
    [StockTransactionStatus.Completed]: {
      variant: "default",
      className:
        "bg-emerald-100 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900 dark:text-emerald-100",
      label: t("shop.stock.disposals.status.completed"),
    },
    [StockTransactionStatus.Cancelled]: {
      variant: "destructive",
      label: t("shop.stock.disposals.status.cancelled"),
    },
  };
  const { variant, className, label } = STATUS_BADGE[status];
  return (
    <Badge variant={variant} className={className}>
      {label}
    </Badge>
  );
}

// Workflow gate helpers — mirror the backend guards in
// StockTransactionService so the UI does not show actions that would be
// rejected anyway.
function canSubmit(status: StockTransactionStatus): boolean {
  return status === StockTransactionStatus.Draft;
}
function canApprove(status: StockTransactionStatus): boolean {
  return status === StockTransactionStatus.Pending;
}
function canCancel(status: StockTransactionStatus): boolean {
  return status === StockTransactionStatus.Draft || status === StockTransactionStatus.Pending;
}
function canDelete(status: StockTransactionStatus): boolean {
  return status === StockTransactionStatus.Draft || status === StockTransactionStatus.Cancelled;
}

export function DisposalListTable({
  shopSlug,
  data,
  emptyMessage,
  onSubmit,
  onApprove,
  onCancel,
  onDelete,
}: DisposalListTableProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const columns: ColumnDef<StockTransaction>[] = [
    {
      id: "code",
      header: t("shop.stock.disposals.col.code"),
      size: 170,
      cell: ({ row }) => (
        <Link
          href={`/shop/${shopSlug}/stock/disposals/${row.original.id}`}
          className="font-mono text-xs text-primary hover:underline"
        >
          {row.original.transaction_code}
        </Link>
      ),
    },
    {
      id: "warehouse",
      header: t("shop.stock.disposals.col.warehouse"),
      size: 180,
      cell: ({ row }) => row.original.warehouse?.name ?? "—",
    },
    {
      id: "items",
      header: t("shop.stock.disposals.col.items"),
      size: 70,
      cell: ({ row }) => <span className="tabular-nums">{row.original.items?.length ?? 0}</span>,
    },
    {
      id: "status",
      header: t("shop.stock.disposals.col.status"),
      size: 120,
      cell: ({ row }) => <StatusBadge status={row.original.status} />,
    },
    {
      id: "created_at",
      header: t("shop.stock.disposals.col.created"),
      size: 150,
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">
          {formatDate(row.original.created_at, locale, timezone)}
        </span>
      ),
    },
    {
      id: "actions",
      header: "",
      size: 60,
      cell: ({ row }) => {
        const tx = row.original;
        const hasAnyAction =
          canSubmit(tx.status) ||
          canApprove(tx.status) ||
          canCancel(tx.status) ||
          canDelete(tx.status);
        if (!hasAnyAction) return null;
        return (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="sm" className="size-7 p-0">
                <MoreHorizontal className="size-3.5" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {canSubmit(tx.status) && (
                <DropdownMenuItem onClick={() => onSubmit(tx.id)}>
                  <SendHorizontal className="mr-2 size-3.5" />
                  {t("shop.stock.disposals.menu.submit")}
                </DropdownMenuItem>
              )}
              {canApprove(tx.status) && (
                <DropdownMenuItem onClick={() => onApprove(tx.id)}>
                  <CheckCircle2 className="mr-2 size-3.5" />
                  {t("shop.stock.disposals.menu.approve")}
                </DropdownMenuItem>
              )}
              {canCancel(tx.status) && (
                <DropdownMenuItem onClick={() => onCancel(tx.id)}>
                  <XCircle className="mr-2 size-3.5" />
                  {t("shop.stock.disposals.menu.cancel")}
                </DropdownMenuItem>
              )}
              {canDelete(tx.status) && (
                <>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem
                    onClick={() => onDelete(tx.id)}
                    className="text-red-600 focus:text-red-600"
                  >
                    <Trash2 className="mr-2 size-3.5" />
                    {t("shop.stock.disposals.menu.delete")}
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
    <DataTable
      columns={columns}
      data={data}
      emptyMessage={emptyMessage ?? t("shop.stock.disposals.empty_list")}
    />
  );
}
