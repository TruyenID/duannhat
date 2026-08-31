import { useEffect, useState } from "react";
import { NavLink } from "react-router";
import type { LucideIcon } from "lucide-react";
import {
  Badge,
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from "@godxjp/ui";
import { Wifi, WifiOff } from "lucide-react";
import { getSyncInfo } from "../../lib/api";
import { NavItem } from "./nav-item";

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

export function AppSidebar({ brandName, navGroups }: AppSidebarProps) {
  const [syncStatus, setSyncStatus] = useState("offline");
  const [pendingCount, setPendingCount] = useState(0);

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

  return (
    <Sidebar collapsible="icon" className="border-r">
      {/* Header: Static brand (single-workspace, no context switcher) */}
      <SidebarHeader className="flex h-12 items-center border-b px-2">
        <SidebarMenu>
          <SidebarMenuItem>
            <SidebarMenuButton className="h-9 w-full">
              <div className="flex items-center gap-2 overflow-hidden">
                <div className="flex size-5 shrink-0 items-center justify-center rounded bg-primary text-[10px] font-bold text-primary-foreground">
                  {brandName.charAt(0).toUpperCase()}
                </div>
                <span className="truncate text-sm font-semibold">{brandName}</span>
              </div>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarHeader>

      {/* Navigation */}
      <SidebarContent className="py-1">
        {navGroups.map((group) => (
          <SidebarGroup key={group.label} className="py-1">
            <SidebarGroupLabel className="h-6 px-3 text-xs">
              {group.label}
            </SidebarGroupLabel>
            <SidebarMenu className="gap-0.5 px-1.5">
              {group.items.map((item) => (
                <NavItem
                  key={item.href}
                  title={item.title}
                  href={item.href}
                  icon={item.icon}
                />
              ))}
            </SidebarMenu>
          </SidebarGroup>
        ))}
      </SidebarContent>

      {/* Footer: Sync status */}
      <SidebarFooter className="border-t py-2 px-2">
        <NavLink
          to="/sync"
          className="flex items-center gap-2 px-1.5 py-1 text-xs text-muted-foreground hover:text-foreground transition-colors rounded-md hover:bg-accent"
          style={{ textDecoration: "none" }}
        >
          {syncStatus === "online" ? (
            <Wifi size={14} className="text-success" />
          ) : (
            <WifiOff size={14} />
          )}
          <span className="capitalize group-data-[collapsible=icon]:hidden">
            {syncStatus}
          </span>
          {pendingCount > 0 && (
            <Badge color="warning" variant="soft" className="ml-auto text-[10px] px-1.5 py-0 group-data-[collapsible=icon]:hidden">
              {pendingCount}
            </Badge>
          )}
        </NavLink>
      </SidebarFooter>
    </Sidebar>
  );
}
