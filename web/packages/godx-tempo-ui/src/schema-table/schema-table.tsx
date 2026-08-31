/**
 * SchemaTable — Metadata-driven data table component.
 *
 * Reads Omnify-generated `{entity}Metadata` and automatically builds columns
 * with correct labels, sorting, type-aware cell rendering, and row actions.
 * Built on @tanstack/react-table (optional peer dep).
 *
 * Requires deep import:
 *   import { SchemaTable } from '@omnifyjp/ui/components/display/schema-table'
 */

import * as React from "react";
import {
  useReactTable,
  getCoreRowModel,
  flexRender,
  type ColumnDef,
} from "@tanstack/react-table";
import {
  ArrowUpDown, ArrowUp, ArrowDown, MoreHorizontal, Search,
  ChevronLeft, ChevronRight, X, SlidersHorizontal, Columns3, Filter,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { useLocale } from "@/internal/ui-hooks";
import { Button } from "../button";
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from "../table";
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuItem,
  DropdownMenuSeparator, DropdownMenuTrigger, DropdownMenuCheckboxItem, DropdownMenuLabel,
} from "../dropdown-menu";
import { Input } from "../input";
import { Switch } from "../switch";
import { Label } from "../label";
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "../select";
import {
  Popover, PopoverContent, PopoverTrigger,
} from "../popover";
import type { FieldMeta, SchemaMetadata, SchemaFieldOption } from "../schema-field/schema-field";

// ─── Types ───────────────────────────────────────────────────────────────────

type LocaleLabels = Record<string, string>;

export type SchemaColumnDef<T = Record<string, unknown>> =
  | string
  | {
      field: string;
      label?: string;
      sortable?: boolean;
      render?: (value: unknown, row: T) => React.ReactNode;
      width?: string;
      align?: "left" | "center" | "right";
      hidden?: boolean;
    };

export interface SchemaTableAction<T = Record<string, unknown>> {
  label: string;
  onClick: (row: T) => void;
  icon?: React.ComponentType<{ className?: string }>;
  variant?: "default" | "destructive";
  hidden?: (row: T) => boolean;
  separator?: boolean;
}

export interface SchemaTablePagination {
  page: number;
  perPage: number;
  total: number;
}

/**
 * Filter state emitted by SchemaTable.
 * Supports both built-in keys and arbitrary field filters via `[key: string]`.
 */
export interface SchemaTableFilters {
  search?: string;
  sort?: string;
  page?: number;
  per_page?: number;
  /** Arbitrary field filters — e.g. `{ status: "active", is_active: true }` */
  [key: string]: unknown;
}

/**
 * Defines a filterable field shown in the advanced filter bar.
 *
 * - **String shorthand**: `"status"` — auto-detect from metadata (enum → select, boolean → switch).
 * - **Object**: Override label, type, options.
 */
export type SchemaFilterDef =
  | string
  | {
      field: string;
      label?: string;
      /** Override filter input type. Auto-detected from metadata if omitted. */
      type?: "select" | "boolean" | "text";
      /** Options for select filters. Auto-generated from enumValues if omitted. */
      options?: SchemaFieldOption[];
      /** Placeholder for select trigger. */
      placeholder?: string;
    };

export interface SchemaTableProps<T = Record<string, unknown>> {
  metadata: SchemaMetadata;
  data: T[];
  columns?: SchemaColumnDef<T>[];
  actions?: SchemaTableAction<T>[];
  pagination?: SchemaTablePagination;
  filters?: SchemaTableFilters;
  onFiltersChange?: (filters: SchemaTableFilters) => void;
  showSearch?: boolean;
  searchPlaceholder?: string;
  showPagination?: boolean;
  pageSizeOptions?: number[];
  emptyText?: string;
  loading?: boolean;
  loadingRows?: number;
  rowKey?: string;
  className?: string;
  onRowClick?: (row: T) => void;
  /**
   * Fields that support sorting. Only these columns show sort icons.
   * If omitted, **no columns are sortable** unless explicitly set via
   * `{ field: "name", sortable: true }` in the columns prop.
   *
   * @example
   * ```tsx
   * sortableFields={["code", "name", "created_at"]}
   * ```
   */
  sortableFields?: string[];
  /**
   * Filterable fields shown as dropdowns/toggles in the toolbar.
   * Auto-detects input type from metadata: enum → select, boolean → switch.
   *
   * @example
   * ```tsx
   * filterableFields={["status", "is_active", { field: "zone_id", options: zoneLookup }]}
   * ```
   */
  filterableFields?: SchemaFilterDef[];
  /**
   * Show column visibility toggle button. Defaults to `false`.
   */
  showColumnToggle?: boolean;
  /**
   * Show active filter tags below the toolbar. Defaults to `true` when
   * filterableFields is set.
   */
  showFilterTags?: boolean;
  /**
   * Toolbar slot — render custom buttons/controls in the toolbar area
   * (right side, next to search).
   */
  toolbarRight?: React.ReactNode;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function resolveLabel(labels: LocaleLabels | undefined, locale: string, fallback: string): string {
  if (!labels || Object.keys(labels).length === 0) return fallback;
  return labels[locale] ?? labels["en"] ?? Object.values(labels)[0] ?? fallback;
}

function humanize(field: string): string {
  return field.replace(/_id$/, "").replace(/_/g, " ").replace(/^./, (c) => c.toUpperCase());
}

function renderCellValue(value: unknown, fieldMeta: FieldMeta | undefined, locale: string): React.ReactNode {
  if (value === null || value === undefined) return <span className="text-muted-foreground">—</span>;
  if (!fieldMeta) return String(value);

  switch (fieldMeta.type) {
    case "boolean":
      return (
        <span className={cn("inline-flex items-center gap-1 text-xs font-medium", value ? "text-emerald-600" : "text-muted-foreground")}>
          <span className={cn("h-1.5 w-1.5 rounded-full", value ? "bg-emerald-500" : "bg-muted-foreground/40")} />
          {value ? "Yes" : "No"}
        </span>
      );
    case "enum":
      if (fieldMeta.enumLabels && typeof value === "string") return resolveLabel(fieldMeta.enumLabels[value], locale, value);
      return String(value);
    case "date":
      if (typeof value === "string") { try { return new Intl.DateTimeFormat(locale === "ja" ? "ja-JP" : locale === "vi" ? "vi-VN" : "en-US").format(new Date(value)); } catch { return value; } }
      return String(value);
    case "datetime":
      if (typeof value === "string") { try { return new Intl.DateTimeFormat(locale === "ja" ? "ja-JP" : locale === "vi" ? "vi-VN" : "en-US", { dateStyle: "short", timeStyle: "short" }).format(new Date(value)); } catch { return value; } }
      return String(value);
    case "string": case "text":
      if (fieldMeta.translatable && typeof value === "object" && value !== null) { const map = value as Record<string, string>; return map[locale] ?? map["en"] ?? Object.values(map)[0] ?? "—"; }
      return String(value);
    default:
      return String(value);
  }
}

/** Built-in filter keys that are NOT field filters. */
const BUILTIN_KEYS = new Set(["search", "sort", "page", "per_page", "with_trashed", "only_trashed"]);

// ─── Component ───────────────────────────────────────────────────────────────

/**
 * Metadata-driven data table with advanced features.
 *
 * ## Features
 *
 * - **Column auto-gen** from metadata or explicit columns prop
 * - **Custom cell render** — antd-style `render: (val, row) => ReactNode`
 * - **Server-side sort/search/paginate** — emits `onFiltersChange`
 * - **Advanced filters** — per-field dropdowns/toggles from `filterableFields`
 * - **Filter tags** — active filters shown as removable badges
 * - **Column visibility toggle** — show/hide columns via dropdown
 * - **Row actions** — dropdown menu per row
 * - **Locale-aware** — labels, enums, dates in current locale
 *
 * @example
 * ```tsx
 * <SchemaTable
 *   metadata={productMetadata}
 *   data={products}
 *   columns={["name", "status", "is_hidden", "created_at"]}
 *   filterableFields={["status", "is_hidden"]}
 *   showColumnToggle
 *   actions={[{ label: "Edit", onClick: handleEdit }]}
 *   pagination={{ page: 1, perPage: 25, total: 100 }}
 *   filters={filters}
 *   onFiltersChange={setFilters}
 * />
 * ```
 */
export function SchemaTable<T extends Record<string, unknown> = Record<string, unknown>>({
  metadata, data, columns: columnsProp, actions, pagination,
  filters = {}, onFiltersChange, showSearch = true, searchPlaceholder,
  showPagination, pageSizeOptions = [10, 25, 50, 100], emptyText,
  loading, loadingRows = 5, rowKey = "id", className, onRowClick,
  sortableFields, filterableFields, showColumnToggle = false, showFilterTags, toolbarRight,
}: SchemaTableProps<T>) {
  const { currentLocale } = useLocale();
  const [searchInput, setSearchInput] = React.useState(filters.search ?? "");
  const searchTimerRef = React.useRef<ReturnType<typeof setTimeout>>(undefined);
  const [hiddenColumns, setHiddenColumns] = React.useState<Set<string>>(new Set());

  const shouldShowFilterTags = showFilterTags ?? (filterableFields && filterableFields.length > 0);

  React.useEffect(() => { setSearchInput(filters.search ?? ""); }, [filters.search]);

  /**
   * Emit a clean filter object — strips undefined/null/"" keys so the
   * caller can spread it directly into API query params.
   */
  const emitFilters = React.useCallback((raw: Record<string, unknown>) => {
    const clean: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(raw)) {
      if (v !== undefined && v !== null && v !== "") clean[k] = v;
    }
    onFiltersChange?.(clean as SchemaTableFilters);
  }, [onFiltersChange]);

  const handleSearchChange = React.useCallback((value: string) => {
    setSearchInput(value);
    clearTimeout(searchTimerRef.current);
    searchTimerRef.current = setTimeout(() => {
      emitFilters({ ...filters, search: value || undefined, page: 1 });
    }, 300);
  }, [filters, emitFilters]);

  const currentSort = filters.sort as string | undefined;
  const handleSort = React.useCallback((field: string) => {
    let newSort: string | undefined;
    if (currentSort === field) newSort = `-${field}`;
    else if (currentSort === `-${field}`) newSort = undefined;
    else newSort = field;
    emitFilters({ ...filters, sort: newSort, page: 1 });
  }, [filters, currentSort, emitFilters]);

  const setFieldFilter = React.useCallback((field: string, value: unknown) => {
    const next: Record<string, unknown> = { ...filters, page: 1 };
    if (value === undefined || value === "" || value === null) delete next[field];
    else next[field] = value;
    emitFilters(next);
  }, [filters, emitFilters]);

  const clearFieldFilter = React.useCallback((field: string) => {
    const next: Record<string, unknown> = { ...filters };
    delete next[field];
    emitFilters({ ...next, page: 1 });
  }, [filters, emitFilters]);

  const clearAllFilters = React.useCallback(() => {
    emitFilters({ page: 1, per_page: filters.per_page });
  }, [filters.per_page, emitFilters]);

  // ── Active field filters (for tags) ─────────────────────────────────────
  const activeFieldFilters = React.useMemo(() => {
    return Object.entries(filters).filter(
      ([key, val]) => !BUILTIN_KEYS.has(key) && val !== undefined && val !== "" && val !== null,
    );
  }, [filters]);

  // ── Resolve filterable fields ───────────────────────────────────────────
  const resolvedFilterFields = React.useMemo(() => {
    if (!filterableFields) return [];
    return filterableFields.map((f) => {
      const isString = typeof f === "string";
      const field = isString ? f : f.field;
      const obj = isString ? undefined : f;
      const fm = metadata.fields[field];

      let type: "select" | "boolean" | "text" = "text";
      if (obj?.type) type = obj.type;
      else if (fm?.type === "boolean") type = "boolean";
      else if (fm?.type === "enum" || fm?.enumValues) type = "select";

      const label = obj?.label ?? (fm ? resolveLabel(fm.label, currentLocale, humanize(field)) : humanize(field));

      let options: SchemaFieldOption[] | undefined = obj?.options;
      if (!options && fm?.enumValues) {
        options = fm.enumValues.map((v) => ({
          value: v,
          label: fm.enumLabels ? resolveLabel(fm.enumLabels[v], currentLocale, v) : v,
        }));
      }

      const placeholder = obj?.placeholder ?? label;

      return { field, type, label, options, placeholder };
    });
  }, [filterableFields, metadata.fields, currentLocale]);

  // ── Build columns ───────────────────────────────────────────────────────
  const resolvedColumns = React.useMemo(() => {
    const cols: SchemaColumnDef<T>[] = columnsProp ??
      Object.keys(metadata.fields).filter((f) => {
        const fm = metadata.fields[f];
        return !f.endsWith("_id") || fm.relation;
      });
    return cols;
  }, [columnsProp, metadata.fields]);

  // Column label map for toggle + filter tags
  const columnLabelMap = React.useMemo(() => {
    const map: Record<string, string> = {};
    for (const col of resolvedColumns) {
      const field = typeof col === "string" ? col : col.field;
      const obj = typeof col === "string" ? undefined : col;
      const fm = metadata.fields[field];
      map[field] = obj?.label ?? (fm ? resolveLabel(fm.label, currentLocale, humanize(field)) : humanize(field));
    }
    return map;
  }, [resolvedColumns, metadata.fields, currentLocale]);

  const tanstackColumns = React.useMemo<ColumnDef<T>[]>(() => {
    const result: ColumnDef<T>[] = [];

    for (const col of resolvedColumns) {
      const isString = typeof col === "string";
      const field = isString ? col : col.field;
      const obj = isString ? undefined : col;
      const fieldMeta = metadata.fields[field];

      if (obj?.hidden || hiddenColumns.has(field)) continue;

      const label = columnLabelMap[field] ?? humanize(field);
      // Sort icon only shows when explicitly enabled:
      // 1. Column def has `sortable: true`
      // 2. Or field is listed in `sortableFields` prop
      const sortable = obj?.sortable !== undefined
        ? obj.sortable
        : sortableFields ? sortableFields.includes(field) : false;
      const align = obj?.align || "left";
      const width = obj?.width;
      const customRender = obj?.render;

      result.push({
        id: field,
        accessorFn: (row) => row[field],
        header: () => {
          if (!sortable) return <span>{label}</span>;
          const isSorted = currentSort === field || currentSort === `-${field}`;
          const isDesc = currentSort === `-${field}`;
          return (
            <button type="button" onClick={() => handleSort(field)} className="flex items-center gap-1 hover:text-foreground transition-colors -ml-1 px-1">
              {label}
              {!isSorted && <ArrowUpDown className="h-3.5 w-3.5 text-muted-foreground/50" />}
              {isSorted && !isDesc && <ArrowUp className="h-3.5 w-3.5" />}
              {isSorted && isDesc && <ArrowDown className="h-3.5 w-3.5" />}
            </button>
          );
        },
        cell: ({ row }) => {
          const value = row.original[field];
          if (customRender) return customRender(value, row.original);
          return renderCellValue(value, fieldMeta, currentLocale);
        },
        meta: { align, width },
      });
    }

    if (actions && actions.length > 0) {
      result.push({
        id: "__actions",
        header: () => null,
        cell: ({ row }) => {
          const visible = actions.filter((a) => !a.hidden?.(row.original));
          if (visible.length === 0) return null;
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="h-8 w-8" onClick={(e) => e.stopPropagation()}>
                  <MoreHorizontal className="h-4 w-4" /><span className="sr-only">Actions</span>
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {visible.map((action, i) => (
                  <React.Fragment key={i}>
                    {action.separator && i > 0 && <DropdownMenuSeparator />}
                    <DropdownMenuItem onClick={(e) => { e.stopPropagation(); action.onClick(row.original); }} className={cn(action.variant === "destructive" && "text-destructive focus:text-destructive")}>
                      {action.icon && <action.icon className="mr-2 h-4 w-4" />}{action.label}
                    </DropdownMenuItem>
                  </React.Fragment>
                ))}
              </DropdownMenuContent>
            </DropdownMenu>
          );
        },
        meta: { align: "right" as const, width: "w-12" },
      });
    }

    return result;
  }, [resolvedColumns, metadata.fields, currentLocale, currentSort, handleSort, actions, hiddenColumns, columnLabelMap]);

  const table = useReactTable({
    data, columns: tanstackColumns, getCoreRowModel: getCoreRowModel(),
    manualSorting: true, manualPagination: true,
    rowCount: pagination?.total,
    getRowId: (row) => String(row[rowKey] ?? ""),
  });

  const pag = pagination;
  const totalPages = pag ? Math.ceil(pag.total / pag.perPage) : 0;
  const shouldShowPagination = showPagination ?? !!pag;

  // ── Render ──────────────────────────────────────────────────────────────
  return (
    <div data-slot="schema-table" className={cn("flex flex-col gap-3", className)}>

      {/* ═══ Toolbar ═══ */}
      <div className="flex flex-wrap items-center gap-2">
        {/* Search */}
        {showSearch && (
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input
              value={searchInput}
              onChange={(e) => handleSearchChange((e.target as HTMLInputElement).value)}
              placeholder={searchPlaceholder ?? resolveLabel({ ja: "検索...", en: "Search...", vi: "Tim kiem..." }, currentLocale, "Search...")}
              className="pl-9" size="sm"
            />
          </div>
        )}

        {/* Advanced Filters */}
        {resolvedFilterFields.length > 0 && (
          <Popover>
            <PopoverTrigger asChild>
              <Button variant="outline" size="sm" className="gap-1.5">
                <SlidersHorizontal className="h-3.5 w-3.5" />
                {resolveLabel({ ja: "フィルター", en: "Filters", vi: "Bo loc" }, currentLocale, "Filters")}
                {activeFieldFilters.length > 0 && (
                  <span className="ml-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[10px] font-semibold text-primary-foreground">
                    {activeFieldFilters.length}
                  </span>
                )}
              </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-72 p-4">
              <div className="flex flex-col gap-3">
                <p className="text-sm font-medium">
                  {resolveLabel({ ja: "フィルター", en: "Filters", vi: "Bo loc" }, currentLocale, "Filters")}
                </p>
                {resolvedFilterFields.map((ff) => (
                  <div key={ff.field} className="flex flex-col gap-1.5">
                    {ff.type === "boolean" ? (
                      <div className="flex items-center justify-between">
                        <Label className="text-sm font-normal">{ff.label}</Label>
                        <Switch
                          checked={filters[ff.field] === true || filters[ff.field] === "1"}
                          onCheckedChange={(v) => setFieldFilter(ff.field, v ? true : undefined)}
                        />
                      </div>
                    ) : ff.type === "select" && ff.options ? (
                      <>
                        <Label className="text-xs text-muted-foreground">{ff.label}</Label>
                        <Select
                          value={typeof filters[ff.field] === "string" ? (filters[ff.field] as string) : ""}
                          onValueChange={(v) => setFieldFilter(ff.field, v || undefined)}
                        >
                          <SelectTrigger className="h-8">
                            <SelectValue placeholder={ff.placeholder} />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="">
                              {resolveLabel({ ja: "すべて", en: "All", vi: "Tat ca" }, currentLocale, "All")}
                            </SelectItem>
                            {ff.options.map((opt) => (
                              <SelectItem key={opt.value} value={opt.value}>{opt.label}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </>
                    ) : (
                      <>
                        <Label className="text-xs text-muted-foreground">{ff.label}</Label>
                        <Input
                          value={typeof filters[ff.field] === "string" ? (filters[ff.field] as string) : ""}
                          onChange={(e) => setFieldFilter(ff.field, (e.target as HTMLInputElement).value || undefined)}
                          placeholder={ff.placeholder}
                          size="sm"
                        />
                      </>
                    )}
                  </div>
                ))}
                {activeFieldFilters.length > 0 && (
                  <Button variant="ghost" size="sm" onClick={clearAllFilters} className="mt-1">
                    <X className="mr-1.5 h-3.5 w-3.5" />
                    {resolveLabel({ ja: "すべてクリア", en: "Clear all", vi: "Xoa tat ca" }, currentLocale, "Clear all")}
                  </Button>
                )}
              </div>
            </PopoverContent>
          </Popover>
        )}

        {/* Column Visibility Toggle */}
        {showColumnToggle && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="sm" className="gap-1.5">
                <Columns3 className="h-3.5 w-3.5" />
                {resolveLabel({ ja: "列", en: "Columns", vi: "Cot" }, currentLocale, "Columns")}
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
              <DropdownMenuLabel className="text-xs">
                {resolveLabel({ ja: "表示する列", en: "Toggle columns", vi: "An/hien cot" }, currentLocale, "Toggle columns")}
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
              {resolvedColumns.map((col) => {
                const field = typeof col === "string" ? col : col.field;
                const label = columnLabelMap[field] ?? humanize(field);
                const isVisible = !hiddenColumns.has(field);
                return (
                  <DropdownMenuCheckboxItem
                    key={field}
                    checked={isVisible}
                    onCheckedChange={(checked) => {
                      setHiddenColumns((prev) => {
                        const next = new Set(prev);
                        if (checked) next.delete(field);
                        else next.add(field);
                        return next;
                      });
                    }}
                  >
                    {label}
                  </DropdownMenuCheckboxItem>
                );
              })}
            </DropdownMenuContent>
          </DropdownMenu>
        )}

        {/* Toolbar right slot */}
        {toolbarRight}
      </div>

      {/* ═══ Filter Tags ═══ */}
      {shouldShowFilterTags && activeFieldFilters.length > 0 && (
        <div className="flex flex-wrap items-center gap-1.5" data-slot="filter-tags">
          <Filter className="h-3.5 w-3.5 text-muted-foreground" />
          {activeFieldFilters.map(([field, value]) => {
            const fm = metadata.fields[field];
            const label = fm ? resolveLabel(fm.label, currentLocale, humanize(field)) : humanize(field);
            let displayValue = String(value);
            if (fm?.enumLabels && typeof value === "string") {
              displayValue = resolveLabel(fm.enumLabels[value], currentLocale, value);
            } else if (fm?.type === "boolean") {
              displayValue = value ? "Yes" : "No";
            }
            return (
              <span
                key={field}
                className="inline-flex items-center gap-1 rounded-md border bg-muted/50 px-2 py-0.5 text-xs text-muted-foreground"
              >
                <span className="font-medium text-foreground">{label}:</span>
                {displayValue}
                <button
                  type="button"
                  onClick={() => clearFieldFilter(field)}
                  className="ml-0.5 rounded-sm hover:bg-muted-foreground/20 p-0.5"
                >
                  <X className="h-3 w-3" />
                </button>
              </span>
            );
          })}
          <button
            type="button"
            onClick={clearAllFilters}
            className="text-xs text-muted-foreground hover:text-foreground underline-offset-2 hover:underline"
          >
            {resolveLabel({ ja: "すべてクリア", en: "Clear all", vi: "Xoa tat ca" }, currentLocale, "Clear all")}
          </button>
        </div>
      )}

      {/* ═══ Table ═══ */}
      <div className="rounded-md border">
        <Table>
          <TableHeader>
            {table.getHeaderGroups().map((hg) => (
              <TableRow key={hg.id}>
                {hg.headers.map((header) => {
                  const meta = header.column.columnDef.meta as { align?: string; width?: string } | undefined;
                  return (
                    <TableHead key={header.id} className={cn(meta?.width, meta?.align === "center" && "text-center", meta?.align === "right" && "text-right")}>
                      {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                    </TableHead>
                  );
                })}
              </TableRow>
            ))}
          </TableHeader>
          <TableBody>
            {loading ? (
              Array.from({ length: loadingRows }).map((_, i) => (
                <TableRow key={`skeleton-${i}`}>
                  {tanstackColumns.map((_, j) => <TableCell key={j}><div className="h-4 w-full animate-pulse rounded bg-muted" /></TableCell>)}
                </TableRow>
              ))
            ) : table.getRowModel().rows.length > 0 ? (
              table.getRowModel().rows.map((row) => (
                <TableRow key={row.id} className={cn(onRowClick && "cursor-pointer")} onClick={() => onRowClick?.(row.original)}>
                  {row.getVisibleCells().map((cell) => {
                    const meta = cell.column.columnDef.meta as { align?: string; width?: string } | undefined;
                    return (
                      <TableCell key={cell.id} className={cn(meta?.width, meta?.align === "center" && "text-center", meta?.align === "right" && "text-right")}>
                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                      </TableCell>
                    );
                  })}
                </TableRow>
              ))
            ) : (
              <TableRow>
                <TableCell colSpan={tanstackColumns.length} className="h-24 text-center text-muted-foreground">
                  {emptyText ?? resolveLabel({ ja: "データがありません", en: "No results", vi: "Khong co du lieu" }, currentLocale, "No results")}
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </div>

      {/* ═══ Pagination ═══ */}
      {shouldShowPagination && pag && (
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <span>
              {resolveLabel({ ja: "表示", en: "Showing", vi: "Hien thi" }, currentLocale, "Showing")}
              {" "}{Math.min((pag.page - 1) * pag.perPage + 1, pag.total)}–{Math.min(pag.page * pag.perPage, pag.total)} / {pag.total}
            </span>
            <Select value={String(pag.perPage)} onValueChange={(v) => emitFilters({ ...filters, per_page: Number(v), page: 1 })}>
              <SelectTrigger className="h-8 w-[70px]"><SelectValue /></SelectTrigger>
              <SelectContent>{pageSizeOptions.map((s) => <SelectItem key={s} value={String(s)}>{s}</SelectItem>)}</SelectContent>
            </Select>
          </div>
          <div className="flex items-center gap-1">
            <Button variant="outline" size="icon" className="h-8 w-8" disabled={pag.page <= 1} onClick={() => emitFilters({ ...filters, page: pag.page - 1 })}>
              <ChevronLeft className="h-4 w-4" />
            </Button>
            <span className="px-2 text-sm text-muted-foreground">{pag.page} / {totalPages}</span>
            <Button variant="outline" size="icon" className="h-8 w-8" disabled={pag.page >= totalPages} onClick={() => emitFilters({ ...filters, page: pag.page + 1 })}>
              <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
