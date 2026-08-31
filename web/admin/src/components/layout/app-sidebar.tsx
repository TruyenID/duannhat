"use client";

import { useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import type { LucideIcon } from "lucide-react";
import {
  Sidebar,
  SidebarContent,
  SidebarGroup,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
} from "@godxjp/ui";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { apiFetch } from "@/lib/api";
import { NavItem } from "./nav-item";
import { ArrowRight, ChevronsUpDown, Search, Store, Tags } from "lucide-react";

export interface NavGroup {
  label: string;
  items: {
    title: string;
    href: string;
    icon: LucideIcon;
  }[];
}

interface Brand {
  id: string;
  name: string;
  slug: string;
}

interface Shop {
  id: string;
  name: string;
  slug: string;
  brand_name?: string | null;
}

interface PagedResponse<T> {
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

// Sidebar dropdown is a quick switcher, not a typeahead. 100 matches the
// backend's MAX_PER_PAGE — covers any small/medium org without paging UI.
const SIDEBAR_LIST_LIMIT = 100;

interface AppSidebarProps {
  brandName: string;
  brandLogo?: string;
  mode?: "brand" | "shop";
  navGroups: NavGroup[];
}

export function AppSidebar({ brandName, mode = "brand", navGroups }: AppSidebarProps) {
  const router = useRouter();
  const { t } = useTranslation();
  // Active brand slug comes from the URL, not from the display name. The
  // previous implementation compared `brandName === b.slug` which was always
  // false (display name vs slug).
  const params = useParams<{ brandSlug?: string; shopSlug?: string }>();
  const activeBrandSlug = params.brandSlug;
  const activeShopSlug = params.shopSlug;

  // Lazy: don't hit /me/brands and /me/shops on every page render. The
  // dropdown is only opened occasionally (when switching workspace), so we
  // defer the fetch until the user has opened it once. Once `hasOpened` flips
  // true, react-query keeps the result cached for `staleTime` so subsequent
  // opens (and other pages) reuse it without refetching.
  const [hasOpened, setHasOpened] = useState(false);

  const { data: brandsResp } = useQuery({
    queryKey: ["me", "brands", "sidebar"],
    queryFn: () =>
      apiFetch<PagedResponse<Brand>>(`/api/v1/me/brands?per_page=${SIDEBAR_LIST_LIMIT}`),
    enabled: hasOpened,
    staleTime: 5 * 60 * 1000,
  });

  const { data: shopsResp } = useQuery({
    queryKey: ["me", "shops", "sidebar"],
    queryFn: () => apiFetch<PagedResponse<Shop>>(`/api/v1/me/shops?per_page=${SIDEBAR_LIST_LIMIT}`),
    enabled: hasOpened,
    staleTime: 5 * 60 * 1000,
  });

  const brands = brandsResp?.data ?? [];
  const shops = shopsResp?.data ?? [];
  const brandTotal = brandsResp?.meta.total ?? 0;
  const shopTotal = shopsResp?.meta.total ?? 0;
  const hasMore = brandTotal > brands.length || shopTotal > shops.length;

  return (
    <Sidebar collapsible="icon" className="border-r">
      {/* Header: Context Switcher (replaces static logo) */}
      <SidebarHeader className="flex h-12 items-center border-b px-2">
        <SidebarMenu>
          <SidebarMenuItem>
            <DropdownMenu
              onOpenChange={(open) => {
                if (open && !hasOpened) setHasOpened(true);
              }}
            >
              <DropdownMenuTrigger asChild>
                <SidebarMenuButton className="h-9 w-full justify-between">
                  <div className="flex items-center gap-2 overflow-hidden">
                    <div className="flex size-5 shrink-0 items-center justify-center rounded bg-primary text-[10px] font-bold text-primary-foreground">
                      {brandName.charAt(0).toUpperCase()}
                    </div>
                    <span className="truncate text-sm font-semibold">{brandName}</span>
                  </div>
                  <ChevronsUpDown className="size-3.5 shrink-0 text-muted-foreground" />
                </SidebarMenuButton>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="start" side="bottom" className="w-60">
                {brands.length > 0 && (
                  <>
                    <div className="flex items-center gap-1.5 px-2 py-1.5">
                      <Tags className="size-3 text-muted-foreground" />
                      <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                        {t("select_context.brands")}
                      </span>
                      {brandTotal > brands.length && (
                        <span className="ml-auto text-[10px] text-muted-foreground">
                          {brands.length} / {brandTotal}
                        </span>
                      )}
                    </div>
                    <DropdownMenuGroup>
                      {brands.map((b) => {
                        const isActive = activeBrandSlug === b.slug && mode === "brand";
                        return (
                          <DropdownMenuItem
                            key={b.slug}
                            className="h-8 gap-2 pl-3 text-sm"
                            onClick={() => router.push(`/hq/${b.slug}/dashboard`)}
                          >
                            <div className="flex size-5 shrink-0 items-center justify-center rounded bg-primary text-[10px] font-bold text-primary-foreground">
                              {b.name.charAt(0)}
                            </div>
                            <span className={isActive ? "font-medium" : ""}>{b.name}</span>
                            {isActive && <span className="ml-auto text-xs text-primary">●</span>}
                          </DropdownMenuItem>
                        );
                      })}
                    </DropdownMenuGroup>
                    {shops.length > 0 && <DropdownMenuSeparator />}
                  </>
                )}
                {shops.length > 0 && (
                  <>
                    <div className="flex items-center gap-1.5 px-2 py-1.5">
                      <Store className="size-3 text-muted-foreground" />
                      <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                        {t("select_context.shops")}
                      </span>
                      {shopTotal > shops.length && (
                        <span className="ml-auto text-[10px] text-muted-foreground">
                          {shops.length} / {shopTotal}
                        </span>
                      )}
                    </div>
                    <DropdownMenuGroup>
                      {shops.map((s) => {
                        const isActive = activeShopSlug === s.slug && mode === "shop";
                        return (
                          <DropdownMenuItem
                            key={s.slug}
                            className="h-8 gap-2 pl-3 text-sm"
                            onClick={() => router.push(`/shop/${s.slug}/dashboard`)}
                          >
                            <div className="flex size-5 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground">
                              <Store className="size-3" />
                            </div>
                            <span className={isActive ? "font-medium" : ""}>{s.name}</span>
                            {s.brand_name && (
                              <span className="ml-auto text-[10px] text-muted-foreground">
                                {s.brand_name}
                              </span>
                            )}
                          </DropdownMenuItem>
                        );
                      })}
                    </DropdownMenuGroup>
                  </>
                )}
                {(brands.length > 0 || shops.length > 0) && hasMore && <DropdownMenuSeparator />}
                <DropdownMenuItem
                  className="h-8 gap-2 pl-3 text-sm"
                  onClick={() => router.push("/select-context")}
                >
                  <div className="flex size-5 shrink-0 items-center justify-center rounded bg-muted text-muted-foreground">
                    <Search className="size-3" />
                  </div>
                  <span className="flex-1 truncate">{t("select_context.search_placeholder")}</span>
                  <ArrowRight className="size-3.5 shrink-0 text-muted-foreground" />
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarHeader>

      {/* Navigation */}
      <SidebarContent className="py-1">
        {navGroups.map((group) => (
          <SidebarGroup key={group.label} className="py-1">
            <SidebarGroupLabel className="h-6 px-3 text-xs">{group.label}</SidebarGroupLabel>
            <SidebarMenu className="gap-0.5 px-1.5">
              {group.items.map((item) => (
                <NavItem key={item.href} title={item.title} href={item.href} icon={item.icon} />
              ))}
            </SidebarMenu>
          </SidebarGroup>
        ))}
      </SidebarContent>

      {/* No footer — user menu is in TopBar */}
    </Sidebar>
  );
}
