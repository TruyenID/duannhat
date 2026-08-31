"use client";

import { SidebarInset, SidebarProvider } from "@godxjp/ui";
import { TooltipProvider } from "@godxjp/ui";
import { AppSidebar, type NavGroup } from "./app-sidebar";
import { TopBar } from "./top-bar";

interface PageShellProps {
  children: React.ReactNode;
  sidebar?: boolean;
  topbar?: boolean;
  brandName?: string;
  brandLogo?: string;
  mode?: "brand" | "shop";
  navGroups?: NavGroup[];
}

export function PageShell({
  children,
  sidebar = true,
  topbar = true,
  brandName = "",
  brandLogo,
  mode = "brand",
  navGroups = [],
}: PageShellProps) {
  if (!sidebar && !topbar) {
    return <main className="flex min-h-screen flex-col">{children}</main>;
  }

  if (!sidebar) {
    return (
      <main className="flex min-h-screen flex-col">
        {topbar && <TopBar brandName={brandName} mode={mode} />}
        <div className="flex flex-1 flex-col">{children}</div>
      </main>
    );
  }

  return (
    <TooltipProvider delayDuration={300}>
      <SidebarProvider>
        <AppSidebar brandName={brandName} brandLogo={brandLogo} mode={mode} navGroups={navGroups} />
        <SidebarInset className="h-screen overflow-hidden">
          {topbar && <TopBar brandName={brandName} mode={mode} />}
          <div className="flex flex-1 flex-col overflow-auto">{children}</div>
        </SidebarInset>
      </SidebarProvider>
    </TooltipProvider>
  );
}
