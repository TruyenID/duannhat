import { Outlet } from "react-router";
import {
  LayoutDashboard,
  ShoppingCart,
  UtensilsCrossed,
  PlugZap,
  Settings,
  RefreshCw,
  BarChart3,
} from "lucide-react";
import { useTranslation } from "../providers/app-provider";
import { PageShell } from "../components/layout/page-shell";
import { SyncFailureBanner } from "../components/layout/sync-failure-banner";
import type { NavGroup } from "../components/layout/app-sidebar";

export function MainLayout() {
  const { t } = useTranslation();

  const navGroups: NavGroup[] = [
    {
      label: t("nav.overview"),
      items: [
        { title: t("nav.dashboard"), href: "/", icon: LayoutDashboard },
        { title: t("nav.orders"), href: "/orders", icon: ShoppingCart },
      ],
    },
    {
      label: t("nav.catalog"),
      items: [
        { title: t("nav.menu"), href: "/menu", icon: UtensilsCrossed },
      ],
    },
    {
      label: t("nav.settings"),
      items: [
        { title: t("nav.peripherals"), href: "/peripherals", icon: PlugZap },
        { title: t("nav.reports"), href: "/reports", icon: BarChart3 },
        { title: t("nav.sync"), href: "/sync", icon: RefreshCw },
        { title: t("nav.settings"), href: "/settings", icon: Settings },
      ],
    },
  ];

  return (
    <PageShell sidebar topbar brandName="Workstation" navGroups={navGroups}>
      <SyncFailureBanner />
      <Outlet />
    </PageShell>
  );
}
