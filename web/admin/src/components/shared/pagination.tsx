"use client";

import { Button } from "@godxjp/ui";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
  from?: number | null;
  to?: number | null;
}

export interface PaginationProps {
  meta: PaginationMeta;
  page: number;
  onPageChange: (page: number) => void;
  /** Available rows-per-page options. Default: [10, 25, 50] */
  rowsPerPageOptions?: number[];
  /** Current rows per page value */
  perPage?: number;
  /** Called when user changes rows per page */
  onPerPageChange?: (perPage: number) => void;
}

const DEFAULT_ROWS_OPTIONS = [10, 25, 50];

/**
 * Generate visible page numbers with ellipsis.
 * Always shows first, last, and pages around current.
 */
function getPageNumbers(current: number, last: number): (number | "...")[] {
  if (last <= 5) {
    return Array.from({ length: last }, (_, i) => i + 1);
  }

  const pages: (number | "...")[] = [];

  if (current <= 3) {
    pages.push(1, 2, 3, 4, "...", last);
  } else if (current >= last - 2) {
    pages.push(1, "...", last - 3, last - 2, last - 1, last);
  } else {
    pages.push(1, "...", current - 1, current, current + 1, "...", last);
  }

  return pages;
}

export function Pagination({
  meta,
  page,
  onPageChange,
  rowsPerPageOptions = DEFAULT_ROWS_OPTIONS,
  perPage,
  onPerPageChange,
}: PaginationProps) {
  const { t } = useTranslation();

  if (meta.total === 0) return null;

  const pageNumbers = getPageNumbers(page, meta.last_page);

  return (
    <div data-slot="pagination" className="mt-3 flex items-center justify-between text-xs">
      {/* Left: Showing X-Y of Z results */}
      <span className="text-muted-foreground">
        {meta.from != null && meta.to != null
          ? t("common.showing", {
              from: meta.from,
              to: meta.to,
              total: meta.total,
            })
          : `${meta.total} total`}
      </span>

      {/* Right: Rows select + Previous + page numbers + Next */}
      <div className="flex items-center gap-2">
        {/* Rows per page */}
        {onPerPageChange && (
          <div className="flex items-center gap-1.5">
            <span className="text-muted-foreground">{t("common.rows")}</span>
            <Select
              value={String(perPage ?? meta.per_page)}
              onValueChange={(v) => onPerPageChange(Number(v))}
            >
              <SelectTrigger className="h-7 w-[72px] text-xs">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {rowsPerPageOptions.map((n) => (
                  <SelectItem key={n} value={String(n)}>
                    {n}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}

        {/* Previous */}
        <Button
          variant="outline"
          size="sm"
          className="h-7 px-3 text-xs"
          disabled={page <= 1}
          onClick={() => onPageChange(Math.max(1, page - 1))}
        >
          {t("common.previous")}
        </Button>

        {/* Page numbers */}
        {meta.last_page > 1 &&
          pageNumbers.map((p, i) =>
            p === "..." ? (
              <span key={`ellipsis-${i}`} className="px-1 text-muted-foreground">
                ...
              </span>
            ) : (
              <Button
                key={p}
                variant={p === page ? "default" : "outline"}
                size="sm"
                className="h-7 w-7 p-0 text-xs"
                onClick={() => onPageChange(p)}
              >
                {p}
              </Button>
            )
          )}

        {/* Next */}
        <Button
          variant="outline"
          size="sm"
          className="h-7 px-3 text-xs"
          disabled={page >= meta.last_page}
          onClick={() => onPageChange(Math.min(meta.last_page, page + 1))}
        >
          {t("common.next")}
        </Button>
      </div>
    </div>
  );
}
