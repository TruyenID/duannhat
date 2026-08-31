import { Badge } from "@godxjp/ui/admin";
import { Text } from "@godxjp/ui/general";
import { Sidebar } from "@godxjp/ui/layout";
import { useEffect, useState } from "react";
import { NavLink, useLocation, useNavigate } from "react-router";
import type { LucideIcon } from "lucide-react";
import { Wifi, WifiOff } from "lucide-react";
import { getSyncInfo, getVersion } from "../../lib/api";

export interface NavGroup {
  label: string;
  items: {
    title: string;
    href: string;
    icon: LucideIcon;
  }[];
}

interface AppSidebarProps {
  brandName: string;
  navGroups: NavGroup[];
}

/**
 * @godxjp/ui 18.x replaced the shadcn-style Sidebar primitives (SidebarMenu /
 * SidebarMenuButton / …) with a data-driven `<Sidebar sections activeId />`.
 * Rows render through `renderItem` so each one is a real react-router NavLink
 * (a single interactive element — no nested button).
 */
export function AppSidebar({ brandName, navGroups }: AppSidebarProps) {
  const location = useLocation();
  const navigate = useNavigate();
  const [syncStatus, setSyncStatus] = useState("offline");
  const [pendingCount, setPendingCount] = useState(0);
  const [version, setVersion] = useState("");

  useEffect(() => {
    getVersion().then(setVersion).catch(() => undefined);
  }, []);

  useEffect(() => {
    const check = async () => {
      try {
        const info = await getSyncInfo();
        if (info) {
          setSyncStatus(info.status);
          setPendingCount(info.pending_count);
        }
      } catch {}
    };
    check();
    const interval = setInterval(check, 5000);
    return () => clearInterval(interval);
  }, []);

  // Rows are keyed by href so activeId is a plain lookup.
  const sections = navGroups.map((group) => ({
    label: group.label,
    items: group.items.map((item) => ({
      id: item.href,
      label: item.title,
      icon: item.icon as unknown as React.ComponentType<React.SVGProps<SVGSVGElement>>,
    })),
  }));

  const activeId =
    navGroups
      .flatMap((g) => g.items)
      .map((i) => i.href)
      .filter((href) =>
        href === "/"
          ? location.pathname === "/"
          : location.pathname === href || location.pathname.startsWith(href + "/")
      )
      // Longest match wins so /peripherals/new doesn't light up a shorter prefix.
      .sort((a, b) => b.length - a.length)[0] ?? "";

  return (
    <Sidebar
      activeId={activeId}
      sections={sections}
      onSelect={(id) => navigate(id)}
      brand={
        <div className="flex items-center gap-2 overflow-hidden">
          <div className="flex size-5 shrink-0 items-center justify-center rounded bg-primary text-[10px] font-bold text-primary-foreground">
            {brandName.charAt(0).toUpperCase()}
          </div>
          <span className="truncate text-sm font-semibold">{brandName}</span>
          {version && (
            <Text as="span" size="xs" tone="muted">
              v{version}
            </Text>
          )}
        </div>
      }
      footer={
        <NavLink
          to="/sync"
          className="flex items-center gap-2 rounded-md px-1.5 py-1 text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
          style={{ textDecoration: "none" }}
        >
          {syncStatus === "online" ? (
            <Wifi size={14} className="text-success" />
          ) : (
            <WifiOff size={14} />
          )}
          <span className="capitalize">{syncStatus}</span>
          {pendingCount > 0 && (
            <Badge className="ml-auto px-1.5 py-0 text-[10px]">{pendingCount}</Badge>
          )}
        </NavLink>
      }
    />
  );
}
