import { useState, useRef, useEffect, ReactNode } from 'react';
import { CornerDownLeft, ChevronDown } from 'lucide-react';
import { cn } from "@/lib/utils";
import type { UILocaleConfig, LocaleCode, TranslatableValue } from "../internal/ui-context";

export type { TranslatableValue };

// ─── Config ───────────────────────────────────────────────────────────────────

/** Max locale tabs shown inline; overflow goes into a dropdown. */
const MAX_INLINE_TABS = 3;

// ─── Types ────────────────────────────────────────────────────────────────────

export interface TranslatableRenderProps {
  /** Active locale code, e.g. `'en'` */
  locale: LocaleCode;
  /** String value for the active locale */
  value: string;
  /** Call with the new string to update the active locale's value */
  onChange: (value: string) => void;
  /** Fallback value shown as placeholder when the active locale is empty */
  fallbackPlaceholder: string | undefined;
  /** True when the active locale has a truthy entry in `errors` */
  hasError: boolean;
}

interface TranslatableFieldProps {
  config: UILocaleConfig;
  value: TranslatableValue;
  onChange: (value: TranslatableValue) => void;
  /** Render the actual input. Receives locale-scoped value/onChange. */
  children: (props: TranslatableRenderProps) => ReactNode;
  className?: string;
  /**
   * Per-locale validation errors. A truthy string for a locale code marks that
   * locale as invalid: its tab dot turns red and `hasError` is `true` in the
   * render props when that locale is active.
   *
   * @example `{ en: '', vi: 'Too long' }` — only VI has an error
   */
  errors?: Partial<Record<string, string>>;
}

// ─── Component ────────────────────────────────────────────────────────────────

/**
 * Wraps any text input with a locale switcher tab bar.
 * Used internally by `Input` and `Textarea` when `translatable` prop is set.
 *
 * When more than 3 locales are configured, overflow locales are collapsed into
 * a dropdown button to prevent the tab bar from overflowing.
 *
 * @example
 * ```tsx
 * <TranslatableField config={localeConfig} value={val} onChange={setVal} errors={{ vi: 'Too long' }}>
 *   {({ value, onChange, fallbackPlaceholder, hasError }) => (
 *     <input aria-invalid={hasError || undefined} value={value} onChange={(e) => onChange(e.target.value)} />
 *   )}
 * </TranslatableField>
 * ```
 */
export function TranslatableField({
  config,
  value,
  onChange,
  children,
  className,
  errors,
}: TranslatableFieldProps) {
  const [activeLocale, setActiveLocale] = useState<LocaleCode>(config.defaultLocale);
  const [dropdownOpen, setDropdownOpen] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  // Close dropdown on outside click
  useEffect(() => {
    if (!dropdownOpen) return;
    const handler = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setDropdownOpen(false);
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [dropdownOpen]);

  const isFallback = activeLocale !== config.fallbackLocale;
  const fallbackPlaceholder = isFallback ? (value[config.fallbackLocale] ?? undefined) : undefined;
  const hasError = !!(errors?.[activeLocale]);

  const handleChange = (v: string) => {
    onChange({ ...value, [activeLocale]: v });
  };

  // ── Build visible + overflow locale lists ──────────────────────────────────
  const localeEntries = Object.entries(config.locales) as [LocaleCode, string][];
  let visibleEntries: [LocaleCode, string][];
  let overflowEntries: [LocaleCode, string][];

  if (localeEntries.length <= MAX_INLINE_TABS) {
    visibleEntries = localeEntries;
    overflowEntries = [];
  } else {
    // Always keep active locale visible; fill remaining slots from the front
    const nonActive = localeEntries.filter(([code]) => code !== activeLocale);
    const visibleNonActive = nonActive.slice(0, MAX_INLINE_TABS - 1);
    const visibleCodes = new Set([...visibleNonActive.map(([c]) => c), activeLocale]);
    // Preserve original order
    visibleEntries = localeEntries.filter(([code]) => visibleCodes.has(code));
    overflowEntries = localeEntries.filter(([code]) => !visibleCodes.has(code));
  }

  const activeInOverflow = overflowEntries.some(([code]) => code === activeLocale);
  const overflowHasValue = overflowEntries.some(([code]) => !!(value[code] ?? ''));
  const overflowHasError = overflowEntries.some(([code]) => !!(errors?.[code]));

  return (
    <div className={cn('flex flex-col gap-1', className)}>
      {/* Locale tab bar */}
      <div className="flex items-center gap-0.5">

        {/* Inline locale tabs */}
        {visibleEntries.map(([code, label]) => {
          const isActive = code === activeLocale;
          const hasValue = !!(value[code] ?? '');
          const hasLocaleError = !!(errors?.[code]);

          return (
            <button
              key={code}
              type="button"
              title={label}
              onClick={() => setActiveLocale(code)}
              className={cn(
                'relative px-2 py-0.5 rounded text-xs font-medium transition-colors select-none',
                isActive
                  ? 'bg-primary text-primary-foreground'
                  : 'text-muted-foreground hover:text-foreground hover:bg-muted',
              )}
            >
              {code.toUpperCase()}
              {/* dot — locale has a value or an error, but is not active */}
              {(hasValue || hasLocaleError) && !isActive && (
                <span className={cn(
                  'absolute -top-0.5 -right-0.5 w-1.5 h-1.5 rounded-full',
                  hasLocaleError ? 'bg-destructive' : 'bg-primary',
                )} />
              )}
            </button>
          );
        })}

        {/* Overflow dropdown */}
        {overflowEntries.length > 0 && (
          <div ref={dropdownRef} className="relative">
            <button
              type="button"
              title="More languages"
              onClick={() => setDropdownOpen((o) => !o)}
              className={cn(
                'relative flex items-center gap-0.5 px-1.5 py-0.5 rounded text-xs font-medium transition-colors select-none',
                activeInOverflow
                  ? 'bg-primary text-primary-foreground'
                  : 'text-muted-foreground hover:text-foreground hover:bg-muted',
              )}
            >
              {activeInOverflow
                ? activeLocale.toUpperCase()
                : `+${overflowEntries.length}`}
              <ChevronDown className={cn('w-3 h-3 transition-transform', dropdownOpen && 'rotate-180')} />
              {/* dot when overflow has values/errors but active is not in overflow */}
              {(overflowHasValue || overflowHasError) && !activeInOverflow && (
                <span className={cn(
                  'absolute -top-0.5 -right-0.5 w-1.5 h-1.5 rounded-full',
                  overflowHasError ? 'bg-destructive' : 'bg-primary',
                )} />
              )}
            </button>

            {dropdownOpen && (
              <div className="absolute top-full left-0 mt-1 z-10 min-w-[140px] rounded-md border border-border bg-popover shadow-md py-1">
                {overflowEntries.map(([code, label]) => {
                  const isActive = code === activeLocale;
                  const hasValue = !!(value[code] ?? '');
                  const hasLocaleError = !!(errors?.[code]);
                  return (
                    <button
                      key={code}
                      type="button"
                      onClick={() => { setActiveLocale(code); setDropdownOpen(false); }}
                      className={cn(
                        'w-full flex items-center gap-2 px-3 py-1.5 text-sm transition-colors',
                        isActive
                          ? 'bg-primary/10 text-primary font-medium'
                          : 'hover:bg-accent text-foreground',
                      )}
                    >
                      <span className="text-xs font-semibold w-6 shrink-0">{code.toUpperCase()}</span>
                      <span className="text-xs text-muted-foreground flex-1 text-left">{label}</span>
                      {(hasValue || hasLocaleError) && (
                        <span className={cn(
                          'w-1.5 h-1.5 rounded-full shrink-0',
                          hasLocaleError ? 'bg-destructive' : 'bg-primary',
                        )} />
                      )}
                    </button>
                  );
                })}
              </div>
            )}
          </div>
        )}

        {/* Fallback hint when active locale is empty */}
        {fallbackPlaceholder && !value[activeLocale] && (
          <span data-testid="fallback-hint" className="ml-1 flex items-center gap-0.5 text-xs text-muted-foreground">
            <CornerDownLeft className="w-3 h-3" />
            {config.fallbackLocale.toUpperCase()}
          </span>
        )}
      </div>

      {children({
        locale: activeLocale,
        value: value[activeLocale] ?? '',
        onChange: handleChange,
        fallbackPlaceholder,
        hasError,
      })}
    </div>
  );
}
