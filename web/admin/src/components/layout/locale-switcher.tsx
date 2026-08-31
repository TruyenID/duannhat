import { GlobeIcon } from "lucide-react";

import { cn } from "@/lib/utils";
import { useLocale } from "@godxjp/ui";
import type { LocaleCode } from "@godxjp/ui";
import { Button } from "@godxjp/ui";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuTrigger,
} from "@godxjp/ui";

// ─── Locale code helper ──────────────────────────────────────────────────────

/**
 * Short display code for a locale, e.g. "ja-JP" -> "JA".
 *
 * #1189 — this replaces a flag-emoji map plus regional-indicator generation
 * plus a globe-emoji fallback. Flags were emoji (banned in this UI), and they
 * were also wrong: a language is not a country, so "en" had to pick between
 * US/GB/AU arbitrarily. The code is unambiguous and needs no assets.
 */
function localeCode(code: LocaleCode): string {
  return code.split("-")[0].toUpperCase();
}

// ─── Component ───────────────────────────────────────────────────────────────

export interface LocaleSwitcherProps {
  /** Show the short locale code badge (e.g. "JA") beside the globe. Default: false */
  showFlag?: boolean;
  /** Show locale label (e.g. "English"). Default: true */
  showLabel?: boolean;
  /** Show locale code (e.g. "EN"). Default: false */
  showCode?: boolean;
  /** Dropdown alignment. Default: "end" */
  align?: "start" | "center" | "end";
  /** Button variant. Default: "ghost" */
  variant?: "ghost" | "outline" | "default";
  /** Button size. Default: inferred (icon when no label/code, default otherwise) */
  size?: "default" | "sm" | "icon";
  /** Additional class names for the trigger button. */
  className?: string;
}

/**
 * Dropdown locale switcher that reads from UIProvider.
 *
 * Requires `<UIProvider locales={...}>` to be an ancestor.
 *
 * @example
 * ```tsx
 * <LocaleSwitcher />
 * <LocaleSwitcher showFlag showLabel={false} />  // globe + short code
 * <LocaleSwitcher showCode variant="outline" />
 * ```
 */
export function LocaleSwitcher({
  showFlag = false,
  showLabel = true,
  showCode = false,
  align = "end",
  variant = "ghost",
  size,
  className,
}: LocaleSwitcherProps) {
  const { currentLocale, setLocale, locales } = useLocale();

  const entries = Object.entries(locales);
  if (entries.length === 0) return null;

  const currentLabel = locales[currentLocale] ?? currentLocale;
  const hasText = showLabel || showCode;
  const resolvedSize = size ?? (hasText ? "default" : "icon");

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant={variant} size={resolvedSize} className={cn("gap-2", className)}>
          <GlobeIcon className="size-4" aria-hidden />
          {showFlag && (
            <span className="text-xs font-semibold leading-none">{localeCode(currentLocale)}</span>
          )}
          {showLabel && <span>{currentLabel}</span>}
          {showCode && !showLabel && <span>{currentLocale.split("-")[0].toUpperCase()}</span>}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align={align}>
        <DropdownMenuRadioGroup value={currentLocale} onValueChange={setLocale}>
          {entries.map(([code, label]) => (
            <DropdownMenuRadioItem key={code} value={code}>
              {showFlag && (
                <span className="mr-2 text-xs font-semibold leading-none text-muted-foreground">
                  {localeCode(code as LocaleCode)}
                </span>
              )}
              {label}
            </DropdownMenuRadioItem>
          ))}
        </DropdownMenuRadioGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
