import { SidebarTrigger } from "@godxjp/ui";
import { Button } from "@godxjp/ui";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import { useLocale, useTheme } from "../../providers/app-provider";
import { Languages, Monitor, Moon, Sun } from "lucide-react";
import type { LocaleCode } from "../../i18n";

const themeIcons = {
  light: Sun,
  dark: Moon,
  system: Monitor,
} as const;

interface TopBarProps {
  brandName?: string;
  children?: React.ReactNode;
}

export function TopBar({ brandName, children }: TopBarProps) {
  const { locale, setLocale, locales } = useLocale();
  const { theme, setTheme } = useTheme();

  const cycleTheme = () => {
    const order = ["light", "dark", "system"] as const;
    const next = order[(order.indexOf(theme) + 1) % order.length];
    setTheme(next);
  };

  const ThemeIcon = themeIcons[theme];

  return (
    <header className="flex h-12 shrink-0 items-center border-b px-3">
      <SidebarTrigger className="-ml-1 size-7" />
      <div className="mx-2 h-4 w-px bg-border" />

      {brandName && (
        <span className="text-sm font-medium">{brandName}</span>
      )}

      <div className="ml-auto flex items-center gap-0.5">
        {children}

        {/* Language Switcher */}
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="sm" className="size-8 p-0">
              <Languages className="size-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuGroup>
              {(Object.keys(locales) as LocaleCode[]).map((loc) => (
                <DropdownMenuItem
                  key={loc}
                  onClick={() => setLocale(loc)}
                  className="h-7 text-sm"
                >
                  <span className={locale === loc ? "font-medium" : ""}>
                    {locales[loc]}
                  </span>
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
        <Button
          variant="ghost"
          size="sm"
          className="size-8 p-0"
          onClick={cycleTheme}
        >
          <ThemeIcon className="size-4" />
        </Button>
      </div>
    </header>
  );
}
