import { useEffect, useState } from "react";
import { useMe } from "@/hooks/use-me";
import { useOrders } from "@/hooks/use-orders";
import { useTranslation } from "@/i18n";
import { WorkstationAlertBanner } from "@/components/workstation-alert-banner";
import { TicketGrid } from "./components/ticket-grid";
import { ConnectionBadge } from "./components/connection-badge";
import { SettingsDrawer } from "./components/settings-drawer";
import { loadOrdersSnapshot, type OrdersSnapshot } from "@/lib/idb";
import { ageInMinutes, isSnapshotStale, cn } from "@/lib/utils";
import type { KdsOrder } from "@/services/kds/orders";

/**
 * Late count fallback for offline cache or pre-Phase-5.4 servers that don't
 * emit `meta`. The per-order `is_late` field is still server-side, so this
 * stays an O(n) filter over the cached array.
 */
function countLate(orders: KdsOrder[]): number {
  return orders.reduce((n, o) => (o.is_late ? n + 1 : n), 0);
}

export default function DashboardPage() {
  const { t } = useTranslation();
  const me = useMe();
  const orders = useOrders();
  const [cached, setCached] = useState<OrdersSnapshot | null>(null);

  // Load cache once at mount — used as fallback if first orders fetch fails
  useEffect(() => {
    void loadOrdersSnapshot().then(setCached);
  }, []);

  return (
    <div className="min-h-screen bg-background text-foreground">
      {/* #1806 S2 — sự cố phải nằm ở chỗ bếp đã nhìn sẵn. */}
      <WorkstationAlertBanner />
      {(() => {
        const showCache = orders.isError && !orders.data && cached !== null;
        const ordersToShow: KdsOrder[] = showCache ? cached.orders : orders.data?.orders ?? [];
        const cacheAge = showCache ? ageInMinutes(cached.fetched_at) : 0;
        const cacheStale = showCache ? isSnapshotStale(cached.fetched_at) : false;
        const activeCount = orders.data?.meta?.active_count ?? ordersToShow.length;
        const lateCount = orders.data?.meta?.late_count ?? countLate(ordersToShow);

        return (
          <>
            <header className="flex justify-between items-center p-4 border-b sticky top-0 bg-background z-20">
              <div>
                <h1 className="text-xl font-semibold">KDS</h1>
                <p className="text-sm text-muted-foreground">
                  {me.data?.branch?.name ?? me.data?.name ?? "…"}
                </p>
              </div>
              <div className="flex items-center gap-3">
                {activeCount > 0 && (
                  <div className="flex items-center gap-2 text-sm tabular-nums">
                    <span className="px-2 py-1 rounded bg-muted">
                      {activeCount} {t("dashboard.active_count")}
                    </span>
                    {lateCount > 0 && (
                      <span className="px-2 py-1 rounded bg-red-100 dark:bg-red-950 text-red-900 dark:text-red-200 font-semibold">
                        🔥 {lateCount} {t("dashboard.late_count")}
                      </span>
                    )}
                  </div>
                )}
                <ConnectionBadge />
                <SettingsDrawer />
              </div>
            </header>

            {/*
              Trade-off: bump-all stays enabled even on a stale snapshot.
              KdsOrderResource never exposes order.status, so we can't filter
              closed orders client-side. If the cook bumps an order that closed
              while offline, the gen-2 endpoint returns KDS_E001 once the network
              is back and the surfaced error toast (Task 6.4) resyncs the
              dashboard. Banner friction is the offline-window fallback.
            */}
            {showCache && (
              <div
                className={cn(
                  "text-sm p-3 text-center",
                  cacheStale
                    ? "bg-red-100 dark:bg-red-950 text-red-900 dark:text-red-200 font-semibold"
                    : "bg-amber-100 dark:bg-amber-950 text-amber-900 dark:text-amber-200",
                )}
              >
                {t("dashboard.offline_snapshot")} · {cacheAge} {t("dashboard.minutes")}
                {cacheStale && (
                  <p className="mt-1">{t("dashboard.offline_stale_warning")}</p>
                )}
              </div>
            )}

            <main>
              {orders.isLoading && !orders.data ? (
                <div className="grid place-items-center min-h-[60vh] text-muted-foreground">
                  {t("common.loading")}
                </div>
              ) : orders.isError && !showCache ? (
                <div className="grid place-items-center min-h-[60vh] text-destructive">
                  <div className="text-center space-y-3">
                    <p>{t("common.error")}</p>
                    <button
                      type="button"
                      onClick={() => orders.refetch()}
                      className="px-4 py-2 border rounded min-h-11"
                    >
                      {t("common.retry")}
                    </button>
                  </div>
                </div>
              ) : (
                <TicketGrid orders={ordersToShow} />
              )}
            </main>
          </>
        );
      })()}
    </div>
  );
}
