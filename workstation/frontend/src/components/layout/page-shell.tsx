import { TooltipProvider } from "@godxjp/ui/feedback";
import { AppShell } from "@godxjp/ui/layout";
import { AppSidebar, type NavGroup } from "./app-sidebar";
import { TopBar } from "./top-bar";

interface PageShellProps {
  children: React.ReactNode;
  sidebar?: boolean;
  topbar?: boolean;
  brandName?: string;
  navGroups?: NavGroup[];
}

/**
 * @godxjp/ui 18.x replaced SidebarProvider/SidebarInset with <AppShell>, which
 * owns the docked sidebar, the topbar slot and the mobile nav drawer.
 */
export function PageShell({
  children,
  sidebar = true,
  topbar = true,
  brandName = "",
  navGroups = [],
}: PageShellProps) {
  if (!sidebar && !topbar) {
    return <main className="flex min-h-screen flex-col">{children}</main>;
  }

  if (!sidebar) {
    return (
      <main className="flex min-h-screen flex-col">
        {topbar && <TopBar brandName={brandName} />}
        <div className="flex flex-1 flex-col">{children}</div>
      </main>
    );
  }

  return (
    <TooltipProvider delayDuration={300}>
      <AppShell
        sidebar={<AppSidebar brandName={brandName} navGroups={navGroups} />}
        topbar={topbar ? <TopBar brandName={brandName} /> : undefined}
      >
        <div className="flex flex-1 flex-col overflow-auto">{children}</div>
      </AppShell>
    </TooltipProvider>
  );
}
