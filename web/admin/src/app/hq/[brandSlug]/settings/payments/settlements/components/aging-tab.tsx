"use client";

import { useMemo } from "react";
import type { ColumnDef } from "@tanstack/react-table";
import { Badge } from "@godxjp/ui";

import { DataTable } from "@/components/shared/data-table";
import { buildCsv, downloadCsv } from "@/lib/csv";
import { useSettlementAging } from "@/hooks/api/use-settlements";
import type { SettlementAgingRow } from "@/services/settlement-service";
import { useTranslation } from "@/providers/app-provider";
import { agingBucketKeys, humanizeCode, type SettlementTabProps } from "../lib/settlement-view";
import { MinorAmount } from "./minor-amount";
import { SettlementPanel } from "./settlement-panel";
import { SettlementToolbar } from "./settlement-toolbar";

/**
 * Money still sitting at the gateway, bucketed by days pending (#1155 T4.2).
 *
 * The bucket columns are BUILT FROM THE RESPONSE. Edges come from
 * `payments.settlement.aging_buckets` config and the alert threshold is
 * per-provider (Stripe pays out in days, PayPay in monthly cycles), so a fixed
 * `0-3d / 4-7d / …` column set here would quietly drop a column the day
 * operations retunes it.
 *
 * One row per connection × currency, because the server refuses to add yen to
 * dong — and so does this screen.
 *
 * The endpoint returns the whole report at once, so there is no pagination and
 * the CSV here really is everything on the tab.
 */
export function AgingTab({ brandSlug, connections, connectionId, setFilter }: SettlementTabProps) {
  const { t } = useTranslation();

  const filters = useMemo(
    () => ({ connection_id: connectionId === "all" ? undefined : connectionId }),
    [connectionId]
  );

  const { data, isLoading, isFetching, isError, error, refetch } = useSettlementAging(
    brandSlug,
    filters
  );

  const rows = useMemo(() => data?.data ?? [], [data]);
  const bucketKeys = useMemo(() => agingBucketKeys(rows), [rows]);

  const columns: ColumnDef<SettlementAgingRow>[] = useMemo(() => {
    const base: ColumnDef<SettlementAgingRow>[] = [
      {
        id: "provider",
        header: t("hq.settlements.field.provider"),
        cell: ({ row }) => (
          <span className="font-medium">{humanizeCode(row.original.provider)}</span>
        ),
      },
      {
        id: "currency",
        header: t("hq.settlements.field.currency"),
        cell: ({ row }) => (
          <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{row.original.currency}</code>
        ),
      },
      {
        id: "total",
        header: t("hq.settlements.field.pending_total"),
        cell: ({ row }) => (
          <MinorAmount
            minor={row.original.total_net_minor}
            currency={row.original.currency}
            className="font-medium"
          />
        ),
      },
      {
        id: "rows",
        header: t("hq.settlements.field.row_count"),
        cell: ({ row }) => <span className="tabular-nums">{row.original.row_count}</span>,
      },
      {
        id: "oldest",
        header: t("hq.settlements.field.oldest_age_days"),
        cell: ({ row }) => {
          const days = row.original.oldest_age_days;
          if (days === null) return <span className="text-muted-foreground">—</span>;
          return row.original.over_threshold ? (
            // Over the provider's own payout window — a real operational alert,
            // which is why this one gets warning colour and an empty table does
            // not.
            <Badge color="warning" variant="soft" className="tabular-nums">
              {t("hq.settlements.aging.days", { days: String(days) })}
            </Badge>
          ) : (
            <span className="tabular-nums">
              {t("hq.settlements.aging.days", { days: String(days) })}
            </span>
          );
        },
      },
    ];

    for (const key of bucketKeys) {
      base.push({
        id: `bucket-${key}`,
        header: key,
        cell: ({ row }) => {
          const bucket = row.original.buckets?.[key];
          if (!bucket || bucket.row_count === 0) {
            return <span className="text-muted-foreground">—</span>;
          }
          return <MinorAmount minor={bucket.net_minor} currency={row.original.currency} />;
        },
      });
    }

    return base;
  }, [t, bucketKeys]);

  function handleExport() {
    const csv = buildCsv(
      [
        "connection_id",
        "provider",
        "currency",
        "total_net_minor",
        "row_count",
        "oldest_age_days",
        "over_threshold",
        ...bucketKeys.map((key) => `${key}_net_minor`),
      ],
      rows.map((r) => [
        r.connection_id,
        r.provider,
        r.currency,
        r.total_net_minor,
        r.row_count,
        r.oldest_age_days,
        String(r.over_threshold),
        ...bucketKeys.map((key) => r.buckets?.[key]?.net_minor ?? 0),
      ])
    );
    downloadCsv("settlement-aging.csv", csv);
  }

  return (
    <div className="flex flex-col gap-3">
      <SettlementToolbar
        connections={connections}
        connectionId={connectionId}
        onConnectionChange={(v) => setFilter("connection_id", v)}
        onExport={handleExport}
        exportDisabled={rows.length === 0}
        scopeNote={t("hq.settlements.export.scope_note_full")}
      />

      <SettlementPanel
        isLoading={isLoading}
        isFetching={isFetching}
        isError={isError}
        error={error}
        hasData={data !== undefined}
        isEmpty={rows.length === 0}
        columns={columns.length}
        onRetry={() => void refetch()}
      >
        <DataTable columns={columns} data={rows} emptyMessage={t("hq.settlements.aging.empty")} />
      </SettlementPanel>
    </div>
  );
}
