import { NavLink, useLocation } from "react-router";
import type { LucideIcon } from "lucide-react";
import {
  SidebarMenuButton,
  SidebarMenuItem,
} from "@godxjp/ui";

interface NavItemProps {
  title: string;
  href: string;
  icon: LucideIcon;
}

export function NavItem({ title, href, icon: Icon }: NavItemProps) {
  const location = useLocation();
  const isActive =
    href === "/"
      ? location.pathname === "/"
      : location.pathname === href || location.pathname.startsWith(href + "/");

  return (
    <SidebarMenuItem>
      <SidebarMenuButton asChild isActive={isActive} className="h-8 text-sm">
        <NavLink to={href}>
          <Icon className="size-4" />
          <span>{title}</span>
        </NavLink>
      </SidebarMenuButton>
    </SidebarMenuItem>
  );
}
