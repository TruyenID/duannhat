import { Button } from "@godxjp/ui/general";
import { DropdownMenu, DropdownMenuContent, DropdownMenuGroup, DropdownMenuItem, DropdownMenuTrigger } from "@godxjp/ui/navigation";
import { useLocale, useTheme } from "../../providers/app-provider";
import { Languages, Moon, Sun } from "lucide-react";
import type { LocaleCode } from "../../i18n";

interface TopBarProps {
  brandName?: string;
  children?: React.ReactNode;
}

export function TopBar({ brandName, children }: TopBarProps) {
  const { locale, setLocale, locales } = useLocale();
  const { theme, setTheme } = useTheme();

  // 18.x theme axis is light|dark (no "system") — a plain flip.
  const toggleTheme = () => setTheme(theme === "dark" ? "light" : "dark");
  const ThemeIcon = theme === "dark" ? Sun : Moon;

  return (
    <header className="flex h-12 w-full shrink-0 items-center px-3">
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
          onClick={toggleTheme}
        >
          <ThemeIcon className="size-4" />
        </Button>
      </div>
    </header>
  );
}
