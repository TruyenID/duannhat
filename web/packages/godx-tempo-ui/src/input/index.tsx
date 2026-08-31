import * as React from "react";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";
import { TranslatableField } from '../translatable-field';
import { useUILocales, resolveTranslatableConfig } from "../internal/ui-hooks";
import type { TranslatableConfig, TranslatableValue } from "../internal/ui-context";

// ─── Variants ─────────────────────────────────────────────────────────────────

const inputVariants = cva(
  "file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input flex w-full min-w-0 rounded-md border bg-input-background transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      size: {
        xs: "h-element-xs px-2 text-xs",
        sm: "h-element-sm px-2.5 text-sm",
        default: "h-element px-3 py-1 text-base md:text-sm",
        lg: "h-element-lg px-4 text-sm",
        xl: "h-element-xl px-4 text-base",
      },
    },
    defaultVariants: {
      size: "default",
    },
  },
);

// ─── Types ────────────────────────────────────────────────────────────────────

type InputSize = VariantProps<typeof inputVariants>['size'];
type NativeInputProps = Omit<React.ComponentProps<'input'>, 'value' | 'onChange' | 'size'>;

interface StandardInputProps extends NativeInputProps {
  size?: InputSize;
  /** Translatable mode disabled (default). */
  translatable?: never;
  value?: string;
  onChange?: React.ChangeEventHandler<HTMLInputElement>;
  /**
   * Single-string validation error. When set, the input gets
   * `aria-invalid` and a red error message is rendered below.
   */
  error?: string;
}

interface TranslatableInputProps extends NativeInputProps {
  size?: InputSize;
  /**
   * Enable locale-switching tabs on this input.
   * - `true` — inherit UIProvider's locale config
   * - `object` — override locales/defaultLocale/fallbackLocale per field
   *
   * @example
   * ```tsx
   * // Uses UIProvider config
   * <Input translatable value={val} onChange={setVal} />
   *
   * // Custom per-field config
   * <Input
   *   translatable={{ locales: { en: 'English', vi: 'Tiếng Việt' }, fallbackLocale: 'en' }}
   *   value={val}
   *   onChange={setVal}
   * />
   * ```
   */
  translatable: TranslatableConfig;
  value?: TranslatableValue;
  onChange?: (value: TranslatableValue) => void;
  /**
   * Per-locale validation errors. Truthy string = that locale is invalid.
   * The active locale's error is forwarded as `aria-invalid` on the input;
   * all locale tabs with errors show a red dot indicator.
   *
   * @example `{ en: 'Required', vi: 'Too long (120/100)' }`
   */
  errors?: Partial<Record<string, string>>;
}

export type InputProps = StandardInputProps | TranslatableInputProps;

// ─── Component ────────────────────────────────────────────────────────────────

/**
 * Text input component with multiple size variants.
 * Supports translatable mode via the `translatable` prop.
 *
 * **Tokens used** (Phase B foundation — `plans/design-foundations-japanese.md`):
 * - Heights via `h-element-*` → `--density-element-*` tokens (28/32/36/44 default;
 *   shifts under `[data-density="compact"]` / `"comfortable"` modes)
 * - `bg-input-background` → `--input-background` (warm off-white per SmartHR)
 * - `border-input` → `--border` = oklch(86% 0.006 60) (SmartHR BORDER #d6d3d0)
 * - `rounded-md` → `--radius-md` = 4 px (JP enterprise subtle radius)
 * - `text-base` → `--text-base` = 14 px / 1.7 line-height (JMDC convergent CJK)
 * - `aria-invalid` styling reads from `--destructive` = 茜 (NOT pure red)
 *
 * Translatable mode (`translatable` prop) wraps the input in `<TranslatableField>`
 * and renders a locale tab bar above. Per-locale errors via the `errors` prop.
 *
 * @example
 * ```tsx
 * // Standard
 * <Input placeholder="Enter text..." />
 * <Input size="sm" value={val} onChange={(e) => setVal(e.target.value)} />
 *
 * // Translatable — uses UIProvider's locale config
 * <Input translatable value={val} onChange={setVal} />
 *
 * // Translatable — custom config
 * <Input
 *   translatable={{ locales: { en: 'English', vi: 'Tiếng Việt' }, fallbackLocale: 'en' }}
 *   value={val}
 *   onChange={setVal}
 * />
 * ```
 */
const Input = React.forwardRef<HTMLInputElement, InputProps>(
  (props, ref) => {
    const { className, type, size, translatable, ...rest } = props as TranslatableInputProps & { type?: string; className?: string };

    const providerLocales = useUILocales();

    // ── Translatable mode ──────────────────────────────────────────────────
    if (translatable !== undefined) {
      const config = resolveTranslatableConfig(translatable, providerLocales);

      // Fallback: if no locale config available, render standard input
      if (!config) {
        const { value: _v, onChange: _oc, ...inputRest } = rest as TranslatableInputProps;
        return (
          <input
            type={type}
            ref={ref}
            data-slot="input"
            className={cn(inputVariants({ size, className }))}
            {...(inputRest as NativeInputProps)}
          />
        );
      }

      const { value = {}, onChange, errors, ...inputRest } = rest as TranslatableInputProps;

      return (
        <TranslatableField
          config={config}
          value={value as TranslatableValue}
          onChange={onChange ?? (() => {})}
          errors={errors}
        >
          {({ value: localeValue, onChange: localeChange, fallbackPlaceholder, hasError }) => (
            <input
              type={type}
              ref={ref}
              data-slot="input"
              data-translatable
              className={cn(inputVariants({ size, className }))}
              value={localeValue}
              placeholder={fallbackPlaceholder ?? (inputRest as React.InputHTMLAttributes<HTMLInputElement>).placeholder}
              onChange={(e) => localeChange(e.target.value)}
              {...(inputRest as React.InputHTMLAttributes<HTMLInputElement>)}
              aria-invalid={hasError || (inputRest as React.InputHTMLAttributes<HTMLInputElement>)['aria-invalid'] || undefined}
            />
          )}
        </TranslatableField>
      );
    }

    // ── Standard mode ──────────────────────────────────────────────────────
    const { value, onChange, error, ...inputRest } = rest as StandardInputProps;
    const ariaInvalid =
      error !== undefined && error !== ''
        ? true
        : (inputRest as React.InputHTMLAttributes<HTMLInputElement>)['aria-invalid'];
    return (
      <>
        <input
          type={type}
          ref={ref}
          data-slot="input"
          className={cn(inputVariants({ size, className }))}
          value={value}
          onChange={onChange}
          aria-invalid={ariaInvalid}
          {...(inputRest as NativeInputProps)}
        />
        {error ? (
          <p data-slot="input-error" className="mt-1 text-sm text-destructive">
            {error}
          </p>
        ) : null}
      </>
    );
  },
);
Input.displayName = "Input";

export { Input, inputVariants };
