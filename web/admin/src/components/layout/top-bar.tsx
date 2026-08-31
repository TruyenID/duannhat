"use client";

import { SidebarTrigger } from "@godxjp/ui";
import { Button } from "@godxjp/ui";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import { Avatar, AvatarFallback } from "@godxjp/ui";
import { useLocale, useTheme, useTranslation } from "@/providers/app-provider";
import { logout } from "@/lib/auth";
import { Languages, Monitor, Moon, Sun, User, Settings, LogOut } from "lucide-react";
import type { LocaleCode } from "@/i18n";
import { NotificationBell } from "./notification-bell";

const themeIcons = {
  light: Sun,
  dark: Moon,
  system: Monitor,
} as const;

interface TopBarProps {
  brandName?: string;
  mode?: "brand" | "shop";
  userName?: string;
  userEmail?: string;
  children?: React.ReactNode;
}

export function TopBar({
  brandName,
  mode = "brand",
  userName = "User",
  userEmail = "",
  children,
}: TopBarProps) {
  const { locale, setLocale, locales } = useLocale();
  const { theme, setTheme } = useTheme();
  const { t } = useTranslation();

  const cycleTheme = () => {
    const order = ["light", "dark", "system"] as const;
    const next = order[(order.indexOf(theme) + 1) % order.length];
    setTheme(next);
  };

  const initials = userName
    .split(" ")
    .map((w) => w[0])
    .slice(0, 2)
    .join("")
    .toUpperCase();

  const ThemeIcon = themeIcons[theme];

  return (
    <header className="flex h-12 shrink-0 items-center border-b px-3">
      <SidebarTrigger className="-ml-1 size-7" />
      <div className="mx-2 h-4 w-px bg-border" />

      {brandName && <span className="text-sm font-medium">{brandName}</span>}

      <div className="ml-auto flex items-center gap-0.5">
        {children}

        {/* Notification Bell (plan-008) */}
        <NotificationBell />

        {/* Language Switcher */}
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="sm"
              className="size-11 p-0"
              aria-label={t("common.language")}
              title={t("common.language")}
            >
              <Languages className="size-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuGroup>
              {(Object.keys(locales) as LocaleCode[]).map((loc) => (
                <DropdownMenuItem key={loc} onClick={() => setLocale(loc)} className="h-7 text-sm">
                  <span className={locale === loc ? "font-medium" : ""}>{locales[loc]}</span>
                  {locale === loc && (
                    <span className="ml-auto text-xs text-muted-foreground">
                      {loc.toUpperCase()}
                    </span>
                  )}
                </DropdownMenuItem>
              ))}
            </DropdownMenuGroup>
          </DropdownMenuContent>
        </DropdownMenu>

        {/* Theme Toggle */}
        <Button variant="ghost" size="sm" className="size-8 p-0" onClick={cycleTheme}>
          <ThemeIcon className="size-4" />
        </Button>

        <div className="mx-1 h-4 w-px bg-border" />

        {/* User Menu */}
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="sm" className="h-8 gap-2 px-2">
              <Avatar className="size-6">
                <AvatarFallback className="text-xs">{initials}</AvatarFallback>
              </Avatar>
              <div className="hidden items-start sm:flex sm:flex-col">
                <span className="max-w-[120px] truncate text-xs font-medium">{userName}</span>
                {userEmail && (
                  <span className="max-w-[120px] truncate text-[10px] text-muted-foreground">
                    {userEmail}
                  </span>
                )}
              </div>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-48">
            <DropdownMenuGroup>
              <DropdownMenuLabel className="py-1.5">
                <div className="text-sm font-medium">{userName}</div>
                {userEmail && <div className="text-xs text-muted-foreground">{userEmail}</div>}
              </DropdownMenuLabel>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
              <DropdownMenuItem className="h-7 text-sm">
                <User className="size-3.5" />
                {t("common.profile")}
              </DropdownMenuItem>
              <DropdownMenuItem className="h-7 text-sm">
                <Settings className="size-3.5" />
                {t("common.settings")}
              </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
              <DropdownMenuItem className="h-7 text-sm text-destructive" onClick={logout}>
                <LogOut className="size-3.5" />
                {t("common.logout")}
              </DropdownMenuItem>
            </DropdownMenuGroup>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </header>
  );
}
