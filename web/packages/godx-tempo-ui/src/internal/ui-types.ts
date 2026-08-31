/**
 * Shared UI types for the components in `src/components/ui/`.
 *
 * Originally from @omnifyjp/ui — copied into this repo so we own the source
 * (the npm package is no longer a dependency). These types enforce consistent
 * APIs across Button, Badge, Alert, and future components.
 *
 * @example
 * ```tsx
 * import type { UIColor, UISize } from '@godxjp/ui';
 * ```
 */

/**
 * Semantic color intent for UI components.
 *
 * Each color maps to a pair of CSS custom properties in theme.css:
 * `--{color}` (background) and `--{color}-foreground` (text on that background).
 *
 * | Color | CSS Variable | Default (Light) | Use for |
 * |-------|-------------|-----------------|---------|
 * | `primary` | `--primary` | `#030213` | Main actions, active states |
 * | `destructive` | `--destructive` | `#d4183d` | Delete, errors, dangerous actions |
 * | `success` | `--success` | `#10b981` | Confirmed, approved, completed |
 * | `warning` | `--warning` | `#f59e0b` | Caution, needs attention |
 * | `info` | `--info` | `#3b82f6` | Informational, neutral highlights |
 *
 * Consumers override these via CSS custom properties:
 * ```css
 * :root { --primary: #dc2626; }
 * ```
 *
 * @example
 * ```tsx
 * <Button color="success">Approve</Button>
 * <Badge color="warning">Pending</Badge>
 * <Alert color="info">Tip: use keyboard shortcuts</Alert>
 * ```
 */
export type UIColor = 'primary' | 'destructive' | 'success' | 'warning' | 'info';

/**
 * Visual display variant for UI components.
 *
 * Controls **how** the color is rendered, independent of **which** color.
 * Combine with `color` prop for full flexibility: `variant="outline" color="destructive"`.
 *
 * | Variant | Description |
 * |---------|-------------|
 * | `default` | Solid filled background with contrasting text |
 * | `secondary` | Muted/gray background (color-independent) |
 * | `outline` | Border with transparent background, colored text |
 * | `soft` | Light tinted background (10% opacity of color) |
 * | `ghost` | Transparent, shows tinted background on hover |
 * | `link` | Text-only with underline on hover |
 *
 * @example
 * ```tsx
 * <Button variant="soft" color="success">Approve</Button>
 * <Button variant="outline" color="destructive">Reject</Button>
 * <Badge variant="soft" color="warning">In Review</Badge>
 * ```
 */
export type UIVariant = 'default' | 'secondary' | 'outline' | 'soft' | 'ghost' | 'link';

/**
 * Standard size scale for UI components.
 *
 * Maps to density tokens in theme.css for consistent sizing across the system.
 *
 * | Size | Height Token | Pixels | Use for |
 * |------|-------------|--------|---------|
 * | `xs` | `h-element-xs` | 24px | Compact tables, inline actions |
 * | `sm` | `h-element-sm` | 28px | Secondary actions, tight layouts |
 * | `default` | `h-element` | 32px | Standard buttons, inputs |
 * | `lg` | `h-element-lg` | 36px | Primary CTAs, form actions |
 * | `xl` | `h-element-xl` | 44px | Login forms, hero sections |
 *
 * @example
 * ```tsx
 * <Button size="sm">Edit</Button>
 * <Button size="xl" block>Sign In</Button>
 * <Input size="lg" />
 * ```
 */
export type UISize = 'xs' | 'sm' | 'default' | 'lg' | 'xl';
