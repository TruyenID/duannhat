"use client";

import type { ReactNode } from "react";
import { Search, X } from "lucide-react";
import { Button } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import { Checkbox } from "@godxjp/ui";
import { Skeleton } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";

export interface ListPageToolbarProps {
  /** Current search input value (local, not debounced) */
  search: string;
  /** Called on every keystroke */
  onSearchChange: (value: string) => void;
  /** Search placeholder — defaults to `t("common.search_placeholder")` */
  searchPlaceholder?: string;

  /** Filter controls rendered between search and trashed checkbox */
  children?: ReactNode;

  /** Show trashed toggle */
  showTrashed?: boolean;
  onShowTrashedChange?: (value: boolean) => void;

  /** Whether any filter is active (non-default). Shows "Clear filters" button */
  hasActiveFilters?: boolean;
  /** Called when user clicks "Clear filters" */
  onClearFilters?: () => void;

  /** Loading state — shows skeleton toolbar when true */
  isLoading?: boolean;

  /**
   * Number of currently selected rows. When > 0, the toolbar swaps its body:
   * search + filter dropdowns disappear and bulkActions renders instead.
   * Row height stays identical — no layout jump.
   */
  selectedCount?: number;
  /**
   * Bulk-action buttons to render when selectedCount > 0.
   * Do NOT include a "Clear selection" button — the header checkbox handles that.
   */
  bulkActions?: ReactNode;
}

export function ListPageToolbar({
  search,
  onSearchChange,
  searchPlaceholder,
  children,
  showTrashed,
  onShowTrashedChange,
  hasActiveFilters,
  onClearFilters,
  isLoading,
  selectedCount = 0,
  bulkActions,
}: ListPageToolbarProps) {
  const { t } = useTranslation();

  return (
    <div data-slot="list-page-toolbar">
      <div className="mb-3 flex h-9 items-center gap-2 overflow-x-auto">
        {selectedCount > 0 && bulkActions ? (
          <>
            {bulkActions}
            <span className="text-xs text-muted-foreground">
              {t("common.selected_count", { n: selectedCount })}
            </span>
          </>
        ) : (
          <>
            {/* Search — always mounted so focus survives loading state swaps */}
            <div className="relative max-w-xs flex-1">
              <Search className="absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder={searchPlaceholder ?? t("common.search_placeholder")}
                className="h-8 pl-8 text-sm"
                value={search}
                onChange={(e) => onSearchChange(e.target.value)}
              />
            </div>

            {isLoading ? (
              <>
                <Skeleton className="h-8 w-36" />
                <Skeleton className="h-8 w-36" />
                <Skeleton className="h-8 w-28" />
              </>
            ) : (
              <>
                {/* Filter controls (Select, etc.) */}
                {children}

                {/* Show trashed */}
                {onShowTrashedChange && (
                  <label className="flex items-center gap-1.5 text-xs">
                    <Checkbox
                      checked={showTrashed ?? false}
                      onCheckedChange={(v) => onShowTrashedChange(v === true)}
                    />
                    {t("common.show_trashed")}
                  </label>
                )}

                {/* Clear all filters */}
                {hasActiveFilters && onClearFilters && (
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 gap-1 text-xs text-muted-foreground"
                    onClick={onClearFilters}
                  >
                    <X className="size-3.5" />
                    {t("common.clear_filters")}
                  </Button>
                )}
              </>
            )}
          </>
        )}
      </div>
    </div>
  );
}
