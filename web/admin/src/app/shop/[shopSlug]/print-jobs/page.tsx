"use client";

/**
 * Print jobs — the shop's ledger screen (plan-052 M2 / T2.2, #1166).
 *
 * What this screen is: a RECORD of every slip the shop tried to print, and the
 * one place a human closes a line the pipeline could not settle.
 *
 * What it deliberately is NOT: a retry console. A `ws_lan` job belongs to the
 * workstation, which owns that queue and retries per the matrix on its own
 * (DESIGN §1b); Cloud showing a "retry" button for it would be a button that
 * lies. And a money document is never re-sent by any machine at all — an
 * ACK-lost receipt is indistinguishable from a printed one, and guessing wrong
 * puts two originals of one インボイス in the world (RISKS PR1). So the only
 * write on this page is `resolve`, which writes a note, not paper.
 *
 * The column that earns its width is `confidence`: `printed` on a raw ESC/POS
 * socket means "the bytes left", not "the paper came out" (P-33). The two are
 * rendered in different colours, with different icons, side by side.
 *
 * Date filters are the BRANCH's business day (#1091) — a Hanoi manager opening
 * the Tokyo shop must see Tokyo's day, so the UI forwards two plain YYYY-MM-DD
 * strings and lets the backend resolve them against `branches.timezone`.
 */

import { useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import type { ColumnDef } from "@tanstack/react-table";
import {
  Badge,
  Button,
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@godxjp/ui";
import { ChevronDown, FileText, ListFilter, X } from "lucide-react";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { Pagination } from "@/components/shared/pagination";
import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import { formatDateTime } from "@/lib/date";
import { useTimezone, useTranslation } from "@/providers/app-provider";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { usePrintJobs } from "@/hooks/api/use-print-jobs";
import {
  PRINT_JOB_KINDS,
  PRINT_JOB_STATUSES,
  PRINT_TRANSPORTS,
  queueOwnerFor,
  shortenError,
} from "@/lib/print-jobs";
import type { PrintJob, PrintJobKind, PrintJobStatus } from "@/types/models/PrintJob";
import { MetaPill, PrintJobConfidenceBadge, PrintJobStatusBadge } from "./components/job-badges";
import { PrintJobSummaryStrip } from "./components/summary-strip";

const FILTER_DEFAULTS = {
  status: "",
  kind: "",
  transport: "all",
  printer_id: "all",
  from: "",
  to: "",
  per_page: "25",
};

/** URL keeps the multi-selects as comma lists so a filter link is copy-pasteable. */
function parseList(value: string): string[] {
  return value
    .split(",")
    .map((entry) => entry.trim())
    .filter(Boolean);
}

export default function ShopPrintJobsPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

  const {
    filters: urlFilters,
    page,
    setFilter,
    setPage,
    resetFilters,
  } = useSearchFilters(FILTER_DEFAULTS);

  const statuses = useMemo(() => parseList(urlFilters.status), [urlFilters.status]);
  const kinds = useMemo(() => parseList(urlFilters.kind), [urlFilters.kind]);

  const [showFilters, setShowFilters] = useState(true);

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  const apiFilters = useMemo(
    () => ({
      page,
      per_page: Number(urlFilters.per_page) || 25,
      status: statuses.length ? (statuses as PrintJobStatus[]) : undefined,
      kind: kinds.length ? (kinds as PrintJobKind[]) : undefined,
      transport:
        urlFilters.transport === "all"
          ? undefined
          : (urlFilters.transport as (typeof PRINT_TRANSPORTS)[number]),
      printer_id: urlFilters.printer_id === "all" ? undefined : urlFilters.printer_id,
      from: urlFilters.from || undefined,
      to: urlFilters.to || undefined,
    }),
    [
      page,
      urlFilters.per_page,
      urlFilters.transport,
      urlFilters.printer_id,
      urlFilters.from,
      urlFilters.to,
      statuses,
      kinds,
    ]
  );

  const { data: response, isLoading, isFetching, refetch } = usePrintJobs(shopSlug, apiFilters);
  const jobs = useMemo(() => response?.data ?? [], [response]);

  // Printer choices come from the rows we already have plus the silent-printer
  // report, so the filter never offers a machine this branch does not own.
  const printerOptions = useMemo(() => {
    const map = new Map<string, string>();
    for (const job of jobs) {
      if (job.printer_id) map.set(job.printer_id, job.printer_name || job.printer_id);
    }
    for (const printer of response?.meta?.silent_printers ?? []) {
      map.set(printer.printer_id, printer.printer_name);
    }
    return [...map.entries()];
  }, [jobs, response?.meta?.silent_printers]);

  const toggleIn = (key: "status" | "kind", value: string) => {
    const current = key === "status" ? statuses : kinds;
    const next = current.includes(value)
      ? current.filter((entry) => entry !== value)
      : [...current, value];
    setFilter(key, next.join(","));
  };

  const columns: ColumnDef<PrintJob>[] = useMemo(
    () => [
      {
        id: "event_at",
        header: t("print_jobs.col.event_at"),
        size: 150,
        cell: ({ row }) => (
          <Link
            href={`/shop/${shopSlug}/print-jobs/${row.original.id}`}
            className="flex flex-col text-primary hover:underline"
          >
            <span className="text-xs font-medium">
              {row.original.event_at
                ? formatDateTime(row.original.event_at, locale, timezone)
                : "—"}
            </span>
            {row.original.printed_reported_at === null && (
              <span className="text-[10px] text-muted-foreground">
                {t("print_jobs.col.event_at_queued")}
              </span>
            )}
          </Link>
        ),
      },
      {
        id: "kind",
        header: t("print_jobs.col.kind"),
        size: 150,
        cell: ({ row }) => (
          <div className="flex flex-wrap items-center gap-1">
            <span className="text-xs font-medium">
              {row.original.kind ? t(`print_jobs.kind.${row.original.kind}`) : "—"}
            </span>
            {row.original.is_money_document && (
              <MetaPill emphasis>{t("print_jobs.money_document")}</MetaPill>
            )}
            {(row.original.reprint_no ?? 1) > 1 && (
              <MetaPill>
                {t("print_jobs.reprint_no", { no: row.original.reprint_no ?? 1 })}
              </MetaPill>
            )}
          </div>
        ),
      },
      {
        id: "printer",
        header: t("print_jobs.col.printer"),
        size: 170,
        cell: ({ row }) => (
          <div className="flex flex-col gap-0.5">
            <span className="text-xs">{row.original.printer_name || "—"}</span>
            <span className="text-[10px] text-muted-foreground">
              {row.original.transport ? t(`print_jobs.transport.${row.original.transport}`) : "—"}
              {" · "}
              {t(`print_jobs.queue_owner.${queueOwnerFor(row.original.transport)}`)}
            </span>
          </div>
        ),
      },
      {
        id: "status",
        header: t("print_jobs.col.status"),
        size: 210,
        cell: ({ row }) => (
          <div className="flex flex-wrap items-center gap-1">
            <PrintJobStatusBadge status={row.original.status} />
            <PrintJobConfidenceBadge label={row.original.confidence_label} />
          </div>
        ),
      },
      {
        id: "attempts",
        header: t("print_jobs.col.attempts"),
        size: 80,
        cell: ({ row }) => (
          <span className="text-xs tabular-nums">{row.original.attempts ?? 0}</span>
        ),
      },
      {
        id: "last_error",
        header: t("print_jobs.col.last_error"),
        size: 240,
        cell: ({ row }) => {
          const short = shortenError(row.original.last_error);
          if (!short) return <span className="text-xs text-muted-foreground">—</span>;
          return (
            <span
              className="font-mono text-[11px] text-muted-foreground"
              title={row.original.last_error ?? undefined}
            >
              {short}
            </span>
          );
        },
      },
      {
        id: "resolution",
        header: t("print_jobs.col.resolution"),
        size: 130,
        cell: ({ row }) => {
          const resolution = row.original.resolution;
          if (!resolution?.resolution) {
            return <span className="text-xs text-muted-foreground">—</span>;
          }
          return (
            <Badge variant="soft" color="info" className="text-[10px]">
              {t(`print_jobs.resolution.${resolution.resolution}`)}
            </Badge>
          );
        },
      },
      {
        id: "actions",
        header: t("common.action"),
        size: 80,
        cell: ({ row }) => (
          <Button asChild size="sm" variant="ghost" className="h-7 gap-1 text-xs">
            <Link href={`/shop/${shopSlug}/print-jobs/${row.original.id}`}>
              <FileText className="size-3.5" />
              {t("print_jobs.col.open")}
            </Link>
          </Button>
        ),
      },
    ],
    [t, locale, timezone, shopSlug]
  );

  return (
    <>
      <PageHeader
        title={t("print_jobs.title")}
        description={t("print_jobs.description")}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button
          size="sm"
          variant="outline"
          className="h-7 gap-1 text-xs"
          onClick={() => setShowFilters((value) => !value)}
        >
          <ListFilter className="size-3.5" />
          {t("print_jobs.filters.toggle")}
        </Button>
        <HelpPanel
          title={t("print_jobs.title")}
          subtitle={t("help.panel.shop_print_jobs.subtitle")}
          purpose={t("help.panel.shop_print_jobs.purpose")}
          usage={[
            t("help.panel.shop_print_jobs.usage.1"),
            t("help.panel.shop_print_jobs.usage.2"),
            t("help.panel.shop_print_jobs.usage.3"),
          ]}
          checks={[
            t("help.panel.shop_print_jobs.checks.1"),
            t("help.panel.shop_print_jobs.checks.2"),
            t("help.panel.shop_print_jobs.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_print_jobs.glossary.confidence.term"),
              description: t("help.panel.shop_print_jobs.glossary.confidence.desc"),
            },
            {
              term: t("help.panel.shop_print_jobs.glossary.queue_owner.term"),
              description: t("help.panel.shop_print_jobs.glossary.queue_owner.desc"),
            },
            {
              term: t("help.panel.shop_print_jobs.glossary.needs_attention.term"),
              description: t("help.panel.shop_print_jobs.glossary.needs_attention.desc"),
            },
          ]}
        />
      </PageHeader>

      <PageContent className="flex flex-col gap-3">
        <p className="text-xs text-muted-foreground">{t("print_jobs.intro")}</p>

        <PrintJobSummaryStrip
          aging={response?.meta?.aging}
          silentPrinters={response?.meta?.silent_printers}
          activeStatuses={statuses}
          onFilterStatus={(next) => setFilter("status", next.join(","))}
        />

        {showFilters && (
          <div className="flex flex-wrap items-end gap-2 rounded-md border border-border bg-card px-3 py-2">
            {/* Status — multi-select. `needs_attention` is the reason this page exists. */}
            <div className="flex flex-col gap-1">
              <span className="text-[11px] text-muted-foreground">
                {t("print_jobs.filters.status")}
              </span>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-8 w-48 justify-between text-xs"
                    data-testid="filter-status"
                  >
                    {statuses.length === 0
                      ? t("print_jobs.filters.all_statuses")
                      : statuses.map((status) => t(`print_jobs.status.${status}`)).join(", ")}
                    <ChevronDown className="size-3.5" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-56">
                  <DropdownMenuLabel className="text-xs">
                    {t("print_jobs.filters.status")}
                  </DropdownMenuLabel>
                  <DropdownMenuSeparator />
                  {PRINT_JOB_STATUSES.map((status) => (
                    <DropdownMenuCheckboxItem
                      key={status}
                      checked={statuses.includes(status)}
                      onCheckedChange={() => toggleIn("status", status)}
                      onSelect={(event) => event.preventDefault()}
                      className="text-xs"
                    >
                      {t(`print_jobs.status.${status}`)}
                    </DropdownMenuCheckboxItem>
                  ))}
                </DropdownMenuContent>
              </DropdownMenu>
            </div>

            {/* Kind — multi-select. */}
            <div className="flex flex-col gap-1">
              <span className="text-[11px] text-muted-foreground">
                {t("print_jobs.filters.kind")}
              </span>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-8 w-48 justify-between text-xs"
                    data-testid="filter-kind"
                  >
                    {kinds.length === 0
                      ? t("print_jobs.filters.all_kinds")
                      : kinds.map((kind) => t(`print_jobs.kind.${kind}`)).join(", ")}
                    <ChevronDown className="size-3.5" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="w-56">
                  <DropdownMenuLabel className="text-xs">
                    {t("print_jobs.filters.kind")}
                  </DropdownMenuLabel>
                  <DropdownMenuSeparator />
                  {PRINT_JOB_KINDS.map((kind) => (
                    <DropdownMenuCheckboxItem
                      key={kind}
                      checked={kinds.includes(kind)}
                      onCheckedChange={() => toggleIn("kind", kind)}
                      onSelect={(event) => event.preventDefault()}
                      className="text-xs"
                    >
                      {t(`print_jobs.kind.${kind}`)}
                    </DropdownMenuCheckboxItem>
                  ))}
                </DropdownMenuContent>
              </DropdownMenu>
            </div>

            <div className="flex flex-col gap-1">
              <span className="text-[11px] text-muted-foreground">
                {t("print_jobs.filters.printer")}
              </span>
              <Select
                value={urlFilters.printer_id}
                onValueChange={(value) => setFilter("printer_id", value)}
              >
                <SelectTrigger className="h-8 w-44 text-xs" data-testid="filter-printer">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">{t("print_jobs.filters.all_printers")}</SelectItem>
                  {printerOptions.map(([id, name]) => (
                    <SelectItem key={id} value={id}>
                      {name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="flex flex-col gap-1">
              <span className="text-[11px] text-muted-foreground">
                {t("print_jobs.filters.transport")}
              </span>
              <Select
                value={urlFilters.transport}
                onValueChange={(value) => setFilter("transport", value)}
              >
                <SelectTrigger className="h-8 w-40 text-xs" data-testid="filter-transport">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">{t("print_jobs.filters.all_transports")}</SelectItem>
                  {PRINT_TRANSPORTS.map((transport) => (
                    <SelectItem key={transport} value={transport}>
                      {t(`print_jobs.transport.${transport}`)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* #1091 — these two are the BRANCH's business dates, not the viewer's. */}
            <div className="flex flex-col gap-1">
              <span className="text-[11px] text-muted-foreground">
                {t("print_jobs.filters.business_day_range")}
              </span>
              <div className="flex items-center gap-1">
                <Input
                  type="date"
                  value={urlFilters.from}
                  onChange={(event) => setFilter("from", event.target.value)}
                  className="h-8 w-36 text-xs"
                  data-testid="filter-from"
                />
                <span className="text-xs text-muted-foreground">–</span>
                <Input
                  type="date"
                  value={urlFilters.to}
                  onChange={(event) => setFilter("to", event.target.value)}
                  className="h-8 w-36 text-xs"
                  data-testid="filter-to"
                />
              </div>
            </div>

            {hasActiveFilters && (
              <Button
                size="sm"
                variant="ghost"
                className="h-8 gap-1 text-xs"
                onClick={() => resetFilters()}
                data-testid="clear-filters"
              >
                <X className="size-3.5" />
                {t("common.clear_filters")}
              </Button>
            )}
          </div>
        )}

        <p className="text-[11px] text-muted-foreground">
          {t("print_jobs.filters.business_day_note")}
        </p>

        {isLoading && response === undefined ? (
          <DataTableSkeleton columns={8} />
        ) : (
          <DataTable columns={columns} data={jobs} emptyMessage={t("print_jobs.empty")} />
        )}

        <Pagination
          meta={response?.meta ?? { current_page: 1, last_page: 1, total: 0, per_page: 25 }}
          page={page}
          onPageChange={setPage}
          perPage={Number(urlFilters.per_page) || 25}
          onPerPageChange={(value) => setFilter("per_page", String(value))}
        />
      </PageContent>
    </>
  );
}
