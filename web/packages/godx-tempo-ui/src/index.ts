/**
 * Public barrel for the design-system UI primitives.
 *
 * Import the whole library tersely:
 *   `import { Button, Input, Card, Spinner } from '@godxjp/ui';`
 *
 * Or per-component (preserves the historic code style and works fine
 * thanks to Node module resolution finding `<name>/index.tsx`):
 *   `import { Button } from '@godxjp/ui';`
 *
 * The `internal/` subfolder is intentionally NOT re-exported — anything
 * inside it is implementation detail (UIProvider context, hooks, theme
 * tokens) that should be reached via its own qualified path on the rare
 * occasions a consumer needs it.
 *
 * This file is generated alphabetically; sort new entries when adding.
 */

export * from './accordion';
export * from './alert';
export * from './alert-dialog';
export * from './aspect-ratio';
export * from './avatar';
export * from './badge';
export * from './breadcrumb';
export * from './button';
export * from './calendar';
export * from './card';
export * from './carousel';
export * from './checkbox';
export * from './collapsible';
export * from './color-picker';
export * from './combobox';
export * from './command';
export * from './context-menu';
export * from './date-picker';
export * from './dialog';
export * from './drawer';
export * from './dropdown-menu';
export * from './file-upload';
export * from './form';
export * from './hover-card';
export * from './input';
export * from './input-otp';
export * from './label';
export * from './menubar';
export * from './navigation-menu';
export * from './page-container';
export * from './pagination';
export * from './password-input';
export * from './popover';
export * from './progress';
export * from './radio-group';
export * from './rating';
export * from './resizable';
export * from './rich-text-editor';
export * from './scroll-area';
export * from './select';
export * from './separator';
export * from './sheet';
export * from './sidebar';
export * from './skeleton';
export * from './slider';
export * from './slug-input';
export * from './sonner';
export * from './spinner';
export * from './status-badge';
export * from './switch';
export * from './table';
export * from './tabs';
export * from './tag-input';
export * from './textarea';
export * from './time-picker';
export * from './toggle';
export * from './toggle-group';
export * from './tooltip';
export * from './translatable-field';
export * from './translatable-rich-text';

// Provider & hooks — needed by consuming apps (ws-app, etc.)
export { UIProvider } from './internal/ui-provider';
export { useTheme, useLocale, useTimezone, useUILocales, resolveTranslatableConfig } from './internal/ui-hooks';
export type { UILocaleConfig, LocaleMap, LocaleCode, Theme, TranslatableConfig, TranslatableValue } from './internal/ui-context';
