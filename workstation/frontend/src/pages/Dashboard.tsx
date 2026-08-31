import { Card, CardContent, CardHeader, CardTitle } from "@godxjp/ui/data-display";
import { Button } from "@godxjp/ui/general";
import { useCallback, useEffect, useState } from "react";
import { ShoppingCart, DollarSign, Printer, TrendingUp, Wifi, Monitor } from "lucide-react";
import { getDashboardStats, getLANInfo } from "../lib/api";
import type { DashboardStats, LANInfo } from "../lib/api";
import { useWsEvents } from "../lib/ws-context";
import { AlertPanel } from "../components/AlertPanel";
import { useTranslation } from "../providers/app-provider";
import { PageHeader } from "../components/layout/page-header";
import { PageContent } from "../components/layout/page-content";

export function Dashboard() {
  const { t } = useTranslation();
  const [stats, setStats] = useState<DashboardStats | null>(null);
  const [lanInfo, setLanInfo] = useState<LANInfo | null>(null);

  useEffect(() => {
    loadStats();
    const interval = setInterval(loadStats, 15000);
    return () => clearInterval(interval);
  }, []);

  // Realtime: refresh stats immediately on any order event.
  const handleWsEvent = useCallback(() => { loadStats(); }, []);
  useWsEvents(handleWsEvent, ["order_created", "order_updated", "order_paid"]);

  async function loadStats() {
    try {
      const result = await getDashboardStats();
      if (result) setStats(result);
      const lan = await getLANInfo();
      if (lan) setLanInfo(lan);
    } catch {}
  }

  const formatPrice = (amount: number) =>
    new Intl.NumberFormat("ja-JP").format(amount);

  return (
    <>
      <PageHeader title={t("nav.dashboard")} />
      {/* #1806 S1 — sự cố phải nằm ở chỗ người ta đã nhìn sẵn. */}
      <AlertPanel />
      <PageContent>
        <div className="space-y-4">
          {/* Stats grid */}
          <div className="grid grid-cols-4 gap-3">
            <StatCard icon={ShoppingCart} label={t("ws.active_orders")} value={stats?.active_orders ?? 0} />
            <StatCard icon={TrendingUp} label={t("ws.today_orders")} value={stats?.today_orders ?? 0} />
            <StatCard icon={DollarSign} label={t("ws.today_revenue")} value={formatPrice(stats?.today_revenue ?? 0)} />
            <StatCard icon={Printer} label={t("ws.devices_online")} value={`${stats?.online_devices ?? 0}/${stats?.device_count ?? 0}`} />
          </div>

          <div className="grid grid-cols-2 gap-3">
            {/* Quick actions */}
            <Card>
              <CardHeader>
                <CardTitle className="text-sm font-medium">{t("common.actions")}</CardTitle>
              </CardHeader>
              <CardContent className="flex gap-2">
                <Button color="primary" size="sm" onClick={() => (window.location.href = "/orders")}>
                  {t("ws.new_order")}
                </Button>
                <Button variant="outline" size="sm" onClick={() => (window.location.href = "/devices")}>
                  {t("ws.manage_devices")}
                </Button>
                <Button variant="outline" size="sm" onClick={() => (window.location.href = "/reports")}>
                  {t("ws.view_reports")}
                </Button>
              </CardContent>
            </Card>

            {/* LAN Server info */}
            <Card>
              <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle className="text-sm font-medium flex items-center gap-1.5">
                  <Wifi size={14} className="text-primary" />
                  {t("ws.lan_server")}
                </CardTitle>
                <Monitor size={14} className="text-muted-foreground" />
              </CardHeader>
              <CardContent className="space-y-1.5 text-sm">
                <div className="flex justify-between">
                  <span className="text-muted-foreground">URL</span>
                  <span className="font-mono text-xs text-primary">{lanInfo?.url || "-"}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">{t("ws.connected_clients")}</span>
                  <span className="font-medium">{lanInfo?.ws_clients ?? 0}</span>
                </div>
                <p className="text-xs text-muted-foreground pt-1">
                  {t("ws.lan_help")}
                </p>
              </CardContent>
            </Card>
          </div>
        </div>
      </PageContent>
    </>
  );
}

function StatCard({ icon: Icon, label, value }: { icon: any; label: string; value: string | number }) {
  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-xs font-medium text-muted-foreground">{label}</CardTitle>
        <Icon size={14} className="text-muted-foreground" />
      </CardHeader>
      <CardContent>
        <div className="text-xl font-bold">{value}</div>
      </CardContent>
    </Card>
  );
}
