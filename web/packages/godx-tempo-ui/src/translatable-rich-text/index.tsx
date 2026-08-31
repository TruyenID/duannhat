/**
 * Locale-tabbed wrapper around `RichTextEditor`.
 *
 * `<Input translatable />` and `<Textarea translatable />` cover plain text.
 * This provides the same UX (locale switcher tab bar with overflow dropdown,
 * fallback hints, per-locale error indicators) for rich text — by composing
 * `<TranslatableField>` with `RichTextEditor` as the render-prop child.
 *
 * Remove (and switch callers to `<RichTextEditor translatable />`) once
 * the editor grows its own built-in `translatable` prop.
 */

import { useUILocales } from "../internal/ui-hooks";
import type { TranslatableValue } from "../internal/ui-context";
import { TranslatableField } from "../translatable-field";
import { RichTextEditor } from "../rich-text-editor";

export interface TranslatableRichTextProps {
  value: TranslatableValue;
  onChange: (value: TranslatableValue) => void;
  /** Per-locale error map. Truthy string for a locale code marks it invalid. */
  errors?: Partial<Record<string, string>>;
  className?: string;
}

export function TranslatableRichText({
  value,
  onChange,
  errors,
  className,
}: TranslatableRichTextProps) {
  const config = useUILocales();

  // No UIProvider locale config in scope → fall back to a single editor
  // bound to whatever value the parent passes for the first known key.
  // Should not happen in practice because UIProvider mounts above.
  if (!config) {
    const firstKey = Object.keys(value)[0] ?? "";
    return (
      <RichTextEditor
        value={value[firstKey] ?? ""}
        onChange={(html) => onChange({ ...value, [firstKey]: html })}
        className={className}
      />
    );
  }

  return (
    <TranslatableField
      config={config}
      value={value}
      onChange={onChange}
      errors={errors}
      className={className}
    >
      {({ value: localeValue, onChange: localeChange }) => (
        <RichTextEditor value={localeValue} onChange={localeChange} />
      )}
    </TranslatableField>
  );
}
