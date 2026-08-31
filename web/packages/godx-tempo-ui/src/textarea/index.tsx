import * as React from "react";

import { cn } from "@/lib/utils";
import { TranslatableField } from '../translatable-field';
import { useUILocales, resolveTranslatableConfig } from "../internal/ui-hooks";
import type { TranslatableConfig, TranslatableValue } from "../internal/ui-context";

// ─── Types ────────────────────────────────────────────────────────────────────

type NativeTextareaProps = Omit<React.ComponentProps<'textarea'>, 'value' | 'onChange'>;

interface StandardTextareaProps extends NativeTextareaProps {
  /** Translatable mode disabled (default). */
  translatable?: never;
  value?: string;
  onChange?: React.ChangeEventHandler<HTMLTextAreaElement>;
  /**
   * Single-string validation error. When set, the textarea gets
   * `aria-invalid` and a red error message is rendered below.
   */
  error?: string;
}

interface TranslatableTextareaProps extends NativeTextareaProps {
  /**
   * Enable locale-switching tabs on this textarea.
   * - `true` — inherit UIProvider's locale config
   * - `object` — override locales/defaultLocale/fallbackLocale per field
   *
   * @example
   * ```tsx
   * // Uses UIProvider config
   * <Textarea translatable value={val} onChange={setVal} />
   *
   * // Custom per-field config
   * <Textarea
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
   * The active locale's error is forwarded as `aria-invalid` on the textarea;
   * all locale tabs with errors show a red dot indicator.
   *
   * @example `{ en: 'Required', vi: 'Too long (120/100)' }`
   */
  errors?: Partial<Record<string, string>>;
}

export type TextareaProps = StandardTextareaProps | TranslatableTextareaProps;

// ─── Base class ───────────────────────────────────────────────────────────────

const textareaClass =
  "resize-none border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:bg-input/30 flex field-sizing-content min-h-16 w-full rounded-md border bg-input-background px-3 py-2 text-base transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 md:text-sm";

// ─── Component ────────────────────────────────────────────────────────────────

/**
 * Multi-line text input with auto-sizing via `field-sizing-content`.
 * Supports translatable mode via the `translatable` prop.
 *
 * @example
 * ```tsx
 * // Standard
 * <Textarea placeholder="Enter a description..." />
 * <Textarea value={content} onChange={(e) => setContent(e.target.value)} />
 *
 * // Translatable — uses UIProvider's locale config
 * <Textarea translatable value={val} onChange={setVal} />
 *
 * // Translatable — custom config
 * <Textarea
 *   translatable={{ locales: { en: 'English', vi: 'Tiếng Việt' }, fallbackLocale: 'en' }}
 *   value={val}
 *   onChange={setVal}
 * />
 * ```
 */
const Textarea = React.forwardRef<HTMLTextAreaElement, TextareaProps>(
  (props, ref) => {
    const { className, translatable, ...rest } = props as TranslatableTextareaProps & { className?: string };

    const providerLocales = useUILocales();

    // ── Translatable mode ──────────────────────────────────────────────────
    if (translatable !== undefined) {
      const config = resolveTranslatableConfig(translatable, providerLocales);

      // Fallback: if no locale config available, render standard textarea
      if (!config) {
        const { value: _v, onChange: _oc, ...textareaRest } = rest as TranslatableTextareaProps;
        return (
          <textarea
            ref={ref}
            data-slot="textarea"
            className={cn(textareaClass, className)}
            {...(textareaRest as NativeTextareaProps)}
          />
        );
      }

      const { value = {}, onChange, errors, ...textareaRest } = rest as TranslatableTextareaProps;

      return (
        <TranslatableField
          config={config}
          value={value as TranslatableValue}
          onChange={onChange ?? (() => {})}
          errors={errors}
        >
          {({ value: localeValue, onChange: localeChange, fallbackPlaceholder, hasError }) => (
            <textarea
              ref={ref}
              data-slot="textarea"
              data-translatable
              className={cn(textareaClass, className)}
              value={localeValue}
              placeholder={fallbackPlaceholder ?? (textareaRest as React.TextareaHTMLAttributes<HTMLTextAreaElement>).placeholder}
              onChange={(e) => localeChange(e.target.value)}
              {...(textareaRest as React.TextareaHTMLAttributes<HTMLTextAreaElement>)}
              aria-invalid={hasError || (textareaRest as React.TextareaHTMLAttributes<HTMLTextAreaElement>)['aria-invalid'] || undefined}
            />
          )}
        </TranslatableField>
      );
    }

    // ── Standard mode ──────────────────────────────────────────────────────
    const { value, onChange, error, ...textareaRest } = rest as StandardTextareaProps;
    const ariaInvalid =
      error !== undefined && error !== ''
        ? true
        : (textareaRest as React.TextareaHTMLAttributes<HTMLTextAreaElement>)['aria-invalid'];
    return (
      <>
        <textarea
          ref={ref}
          data-slot="textarea"
          className={cn(textareaClass, className)}
          value={value}
          onChange={onChange}
          aria-invalid={ariaInvalid}
          {...(textareaRest as NativeTextareaProps)}
        />
        {error ? (
          <p data-slot="textarea-error" className="mt-1 text-sm text-destructive">
            {error}
          </p>
        ) : null}
      </>
    );
  },
);
Textarea.displayName = "Textarea";

export { Textarea };
