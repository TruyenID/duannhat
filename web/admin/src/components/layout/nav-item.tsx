"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import type { LucideIcon } from "lucide-react";
import { SidebarMenuButton, SidebarMenuItem } from "@godxjp/ui";

interface NavItemProps {
  title: string;
  href: string;
  icon: LucideIcon;
}

export function NavItem({ title, href, icon: Icon }: NavItemProps) {
  const pathname = usePathname();
  const isActive = pathname === href || pathname.startsWith(href + "/");

  return (
    <SidebarMenuItem>
      <SidebarMenuButton asChild isActive={isActive} className="h-8 text-sm">
        <Link href={href}>
          <Icon className="size-4" />
          <span>{title}</span>
        </Link>
      </SidebarMenuButton>
    </SidebarMenuItem>
  );
}
