"use client";

/**
 * TableStatusMenu — the clickable status badge on each table card.
 *
 * Wraps a colored badge in a dropdown that lists all 5 TableStatus values.
 * Selecting one fires `useChangeTableStatus()`, which handles toast + query
 * invalidation. Allowed for any authenticated user (Shop Staff included).
 */

import { Check } from "lucide-react";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import { cn } from "@/lib/utils";
import { useChangeTableStatus } from "@/hooks/api/use-tables";
import { useTranslation } from "@/providers/app-provider";
import {
  type TableStatus,
  TableStatusValues,
  getTableStatusLabel,
} from "@/types/models/enum/TableStatus";
import type { TableStatusValue } from "@/types/shop";

// ---------------------------------------------------------------------------
// Status colour palette (per frontend.md status colour rules)
// ---------------------------------------------------------------------------

const STATUS_CLASSES: Record<TableStatusValue, string> = {
  free: "bg-emerald-100 text-emerald-800 border-emerald-300 hover:bg-emerald-200 dark:bg-emerald-900/40 dark:text-emerald-200 dark:border-emerald-800",
  occupied:
    "bg-amber-100 text-amber-800 border-amber-300 hover:bg-amber-200 dark:bg-amber-900/40 dark:text-amber-200 dark:border-amber-800",
  reserved:
    "bg-blue-100 text-blue-800 border-blue-300 hover:bg-blue-200 dark:bg-blue-900/40 dark:text-blue-200 dark:border-blue-800",
  cleaning:
    "bg-gray-100 text-gray-700 border-gray-300 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700",
  out_of_service:
    "bg-red-100 text-red-800 border-red-300 hover:bg-red-200 dark:bg-red-900/40 dark:text-red-200 dark:border-red-800",
};

export function tableStatusBadgeClass(status: TableStatusValue): string {
  return STATUS_CLASSES[status];
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export interface TableStatusMenuProps {
  shopSlug: string;
  tableId: string;
  currentStatus: TableStatusValue;
  /** Disable interaction when the table is inactive. */
  disabled?: boolean;
}

export function TableStatusMenu({
  shopSlug,
  tableId,
  currentStatus,
  disabled = false,
}: TableStatusMenuProps) {
  const changeStatus = useChangeTableStatus(shopSlug);
  const { locale } = useTranslation();

  const handleSelect = (status: TableStatusValue) => {
    if (status === currentStatus || disabled) return;
    changeStatus.mutate({ id: tableId, data: { status } });
  };

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        disabled={disabled || changeStatus.isPending}
        className={cn(
          "inline-flex h-6 items-center rounded-full border px-2 text-xs font-medium transition-colors",
          "focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none",
          "disabled:cursor-not-allowed disabled:opacity-50",
          STATUS_CLASSES[currentStatus]
        )}
        data-testid={`table-status-${tableId}`}
      >
        {getTableStatusLabel(currentStatus as TableStatus, locale)}
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="w-40">
        {TableStatusValues.map((status) => (
          <DropdownMenuItem
            key={status}
            className="h-8 gap-2 text-sm"
            onClick={() => handleSelect(status)}
          >
            <span
              className={cn(
                "inline-block size-2 rounded-full",
                {
                  free: "bg-emerald-500",
                  occupied: "bg-amber-500",
                  reserved: "bg-blue-500",
                  cleaning: "bg-gray-400",
                  out_of_service: "bg-red-500",
                }[status]
              )}
            />
            <span className="flex-1">{getTableStatusLabel(status, locale)}</span>
            {status === currentStatus && <Check className="size-3.5 text-muted-foreground" />}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
