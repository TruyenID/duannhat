"use client";

import { useMemo } from "react";
import { useParams } from "next/navigation";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { cn } from "@/lib/utils";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { usePaymentGatewayConnections } from "@/hooks/api/use-payment-gateways";
import { useTranslation } from "@/providers/app-provider";
import { SettingsTabsNav } from "../../components/settings-tabs-nav";
import { PaymentsSettingsShell } from "../components/payments-settings-nav";
import { AgingTab } from "./components/aging-tab";
import { BatchesTab } from "./components/batches-tab";
import { PayoutsTab } from "./components/payouts-tab";
import { UnmatchedTab } from "./components/unmatched-tab";
import type { SettlementConnectionOption } from "./lib/settlement-view";

const TAB_KEYS = ["batches", "payouts", "aging", "unmatched"] as const;
type TabKey = (typeof TAB_KEYS)[number];

const FILTER_DEFAULTS = {
  tab: "batches",
  connection_id: "all",
  status: "all",
  settled_from: "",
  settled_to: "",
  per_page: "25",
};

/**
 * HQ settlement reconciliation (#1157 · plan-050 M5 T5.1).
 *
 * Four backend endpoints, four tabs — the split is the backend's, and it is
 * deliberate: batches, payouts, aging and unmatched lines have different keys
 * and different refresh rhythms, so one table with a mode switch would force
 * the reader to infer the shape from a filter.
 *
 * Everything on this screen is read-only and server-computed. The UI scales
 * `*_minor` integers for display (see `lib/money-minor.ts`) and does nothing
 * else to a number: no totals, no net = gross − fee, no currency conversion. If
 * a figure looks wrong here, it is wrong in the provider's report or in the
 * reconciler, and this screen's job is to show that rather than paper over it.
 */
export default function HqSettlementsPage() {
  const { brandSlug } = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  const { filters, page, setFilter, setFilters, setPage } = useSearchFilters(FILTER_DEFAULTS);

  const tab: TabKey = (TAB_KEYS as readonly string[]).includes(filters.tab)
    ? (filters.tab as TabKey)
    : "batches";

  // Connection options come from the real connection list, not from the
  // settlement rows — a connection that has not settled anything yet still has
  // to be selectable, otherwise "no rows" and "cannot even ask" look the same.
  const { data: connectionsPage } = usePaymentGatewayConnections(brandSlug, { per_page: 100 });

  const connections: SettlementConnectionOption[] = useMemo(
    () =>
      (connectionsPage?.data ?? []).map((connection) => ({
        id: connection.id,
        label: connection.merchant_display_name
          ? `${connection.provider.name} · ${connection.merchant_display_name}`
          : connection.provider.name,
      })),
    [connectionsPage]
  );

  const perPage = Number(filters.per_page) || 25;

  const tabProps = {
    brandSlug,
    connections,
    connectionId: filters.connection_id,
    status: filters.status,
    page,
    perPage,
    setFilter: (key: string, value: string) =>
      setFilter(key as keyof typeof FILTER_DEFAULTS, value),
    setPage,
  };

  return (
    <>
      <PageHeader title={t("hq.settlements.title")} description={t("hq.settlements.description")} />

      <PageContent>
        <SettingsTabsNav brandSlug={brandSlug} />
        <PaymentsSettingsShell brandSlug={brandSlug}>
          <div className="flex flex-col gap-4">
            <div
              data-slot="settlements-tab-strip"
              role="tablist"
              aria-label={t("hq.settlements.tabs.aria_label")}
              className="inline-flex h-9 w-fit items-center justify-center rounded-lg bg-muted p-1 text-muted-foreground"
            >
              {TAB_KEYS.map((key) => (
                <button
                  key={key}
                  type="button"
                  role="tab"
                  aria-selected={tab === key}
                  onClick={() =>
                    // Status codes differ between the four endpoints, so a
                    // status carried across tabs would silently filter the new
                    // table down to nothing. The connection filter DOES carry —
                    // it means the same thing everywhere.
                    setFilters({ tab: key, status: "all" })
                  }
                  className={cn(
                    "inline-flex h-7 items-center justify-center rounded-md px-3 text-xs font-medium transition-all",
                    "focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none",
                    tab === key
                      ? "bg-background text-foreground shadow-sm"
                      : "hover:text-foreground/80"
                  )}
                >
                  {key === "batches" ? t("hq.settlements.tabs.batches") : null}
                  {key === "payouts" ? t("hq.settlements.tabs.payouts") : null}
                  {key === "aging" ? t("hq.settlements.tabs.aging") : null}
                  {key === "unmatched" ? t("hq.settlements.tabs.unmatched") : null}
                </button>
              ))}
            </div>

            {tab === "batches" ? <BatchesTab {...tabProps} /> : null}
            {tab === "payouts" ? <PayoutsTab {...tabProps} /> : null}
            {tab === "aging" ? <AgingTab {...tabProps} /> : null}
            {tab === "unmatched" ? (
              <UnmatchedTab
                {...tabProps}
                settledFrom={filters.settled_from}
                settledTo={filters.settled_to}
              />
            ) : null}
          </div>
        </PaymentsSettingsShell>
      </PageContent>
    </>
  );
}
