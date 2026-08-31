import * as react_jsx_runtime from 'react/jsx-runtime';
import * as React$1 from 'react';
import { ReactNode } from 'react';
import * as AccordionPrimitive from '@radix-ui/react-accordion';
import * as class_variance_authority_types from 'class-variance-authority/types';
import { VariantProps } from 'class-variance-authority';
import * as AlertDialogPrimitive from '@radix-ui/react-alert-dialog';
import * as AspectRatioPrimitive from '@radix-ui/react-aspect-ratio';
import * as AvatarPrimitive from '@radix-ui/react-avatar';
import { DayPicker } from 'react-day-picker';
import useEmblaCarousel from 'embla-carousel-react';
import * as CheckboxPrimitive from '@radix-ui/react-checkbox';
import * as CollapsiblePrimitive from '@radix-ui/react-collapsible';
import { Command as Command$1 } from 'cmdk';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import * as ContextMenuPrimitive from '@radix-ui/react-context-menu';
import { Locale } from 'date-fns';
import { Drawer as Drawer$1 } from 'vaul';
import * as DropdownMenuPrimitive from '@radix-ui/react-dropdown-menu';
import * as react_hook_form from 'react-hook-form';
import { FieldValues, FieldPath, ControllerProps } from 'react-hook-form';
import * as LabelPrimitive from '@radix-ui/react-label';
import { Slot } from '@radix-ui/react-slot';
import * as HoverCardPrimitive from '@radix-ui/react-hover-card';
import { OTPInput } from 'input-otp';
import * as MenubarPrimitive from '@radix-ui/react-menubar';
import * as NavigationMenuPrimitive from '@radix-ui/react-navigation-menu';
import * as PopoverPrimitive from '@radix-ui/react-popover';
import * as ProgressPrimitive from '@radix-ui/react-progress';
import * as RadioGroupPrimitive from '@radix-ui/react-radio-group';
import * as ResizablePrimitive from 'react-resizable-panels';
import * as ScrollAreaPrimitive from '@radix-ui/react-scroll-area';
import * as SelectPrimitive from '@radix-ui/react-select';
import * as SeparatorPrimitive from '@radix-ui/react-separator';
import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import * as SliderPrimitive from '@radix-ui/react-slider';
import { ToasterProps } from 'sonner';
export { toast } from 'sonner';
import * as SwitchPrimitives from '@radix-ui/react-switch';
import * as TabsPrimitive from '@radix-ui/react-tabs';
import * as TogglePrimitive from '@radix-ui/react-toggle';
import * as ToggleGroupPrimitive from '@radix-ui/react-toggle-group';

/**
 * Vertically collapsible content sections built on Radix Accordion.
 *
 * Supports `"single"` (one panel open at a time) and `"multiple"` (any number open)
 * modes via the `type` prop. Each section animates open/closed with a chevron indicator.
 *
 * @example
 * ```tsx
 * <Accordion type="single" collapsible>
 *   <AccordionItem value="item-1">
 *     <AccordionTrigger>Is it accessible?</AccordionTrigger>
 *     <AccordionContent>
 *       Yes. It adheres to the WAI-ARIA Accordion pattern.
 *     </AccordionContent>
 *   </AccordionItem>
 *   <AccordionItem value="item-2">
 *     <AccordionTrigger>Is it styled?</AccordionTrigger>
 *     <AccordionContent>
 *       Yes. It ships with default styles via Tailwind CSS.
 *     </AccordionContent>
 *   </AccordionItem>
 * </Accordion>
 * ```
 */
declare function Accordion({ ...props }: React$1.ComponentProps<typeof AccordionPrimitive.Root>): react_jsx_runtime.JSX.Element;
/** Individual accordion section. Requires a unique `value` prop. */
declare function AccordionItem({ className, ...props }: React$1.ComponentProps<typeof AccordionPrimitive.Item>): react_jsx_runtime.JSX.Element;
/** Clickable trigger that toggles its parent `AccordionItem`. Renders a chevron icon that rotates on open. */
declare function AccordionTrigger({ className, children, ...props }: React$1.ComponentProps<typeof AccordionPrimitive.Trigger>): react_jsx_runtime.JSX.Element;
/** Animated collapsible content area within an `AccordionItem`. */
declare function AccordionContent({ className, children, ...props }: React$1.ComponentProps<typeof AccordionPrimitive.Content>): react_jsx_runtime.JSX.Element;

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
type UIColor = 'primary' | 'destructive' | 'success' | 'warning' | 'info';

declare const alertVariants: (props?: ({
    variant?: "default" | "destructive" | "soft" | null | undefined;
    color?: "primary" | "destructive" | "success" | "warning" | "info" | null | undefined;
} & class_variance_authority_types.ClassProp) | undefined) => string;
interface AlertProps extends React$1.ComponentProps<"div">, Omit<VariantProps<typeof alertVariants>, "color"> {
    /**
     * Semantic color intent.
     *
     * @default "primary"
     * @example
     * ```tsx
     * <Alert color="success">Operation completed</Alert>
     * <Alert color="warning">Check your input</Alert>
     * <Alert variant="soft" color="info">Tip</Alert>
     * ```
     */
    color?: UIColor;
}
/**
 * Static alert banner for displaying important messages.
 *
 * Supports semantic colors via `color` prop and two visual styles: `default` (bordered)
 * and `soft` (filled background). All existing `variant="destructive"` usage continues to work.
 *
 * @example
 * ```tsx
 * // Default (bordered)
 * <Alert>
 *   <InfoIcon className="size-4" />
 *   <AlertTitle>Heads up!</AlertTitle>
 *   <AlertDescription>You can add components using the CLI.</AlertDescription>
 * </Alert>
 *
 * // Semantic colors
 * <Alert color="success">
 *   <CheckIcon className="size-4" />
 *   <AlertTitle>Success</AlertTitle>
 *   <AlertDescription>Changes saved successfully.</AlertDescription>
 * </Alert>
 *
 * // Soft variant (filled background)
 * <Alert variant="soft" color="warning">
 *   <AlertTriangleIcon className="size-4" />
 *   <AlertTitle>Warning</AlertTitle>
 *   <AlertDescription>This action cannot be undone.</AlertDescription>
 * </Alert>
 *
 * // Legacy (still works)
 * <Alert variant="destructive">
 *   <AlertCircleIcon className="size-4" />
 *   <AlertTitle>Error</AlertTitle>
 *   <AlertDescription>Session expired.</AlertDescription>
 * </Alert>
 * ```
 */
declare function Alert({ className, variant, color, ...props }: AlertProps): react_jsx_runtime.JSX.Element;
/** Bold title text within an Alert. Rendered in the second grid column when an icon is present. */
declare function AlertTitle({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Descriptive body text within an Alert, rendered below the title. */
declare function AlertDescription({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;

/**
 * Confirmation dialog built on Radix UI AlertDialog.
 *
 * Unlike `Dialog`, an alert dialog requires an explicit user action to dismiss
 * (no click-outside or Escape by default). Use it for destructive actions or
 * important confirmations.
 *
 * **When to use:** destructive or irreversible actions ONLY — delete, archive,
 * force-logout, payment confirmation. The no-dismiss-on-overlay-click behaviour
 * is intentional friction so the user can't fat-finger the action away. For any
 * non-destructive confirmation (save, publish, edit) use `<Dialog>` instead.
 *
 * @example
 * ```tsx
 * <AlertDialog open={open} onOpenChange={setOpen}>
 *   <AlertDialogTrigger asChild>
 *     <Button variant="destructive">Delete Item</Button>
 *   </AlertDialogTrigger>
 *   <AlertDialogContent>
 *     <AlertDialogHeader>
 *       <AlertDialogTitle>Are you sure?</AlertDialogTitle>
 *       <AlertDialogDescription>
 *         This action cannot be undone. This will permanently delete
 *         your item and remove it from our servers.
 *       </AlertDialogDescription>
 *     </AlertDialogHeader>
 *     <AlertDialogFooter>
 *       <AlertDialogCancel>Cancel</AlertDialogCancel>
 *       <AlertDialogAction>Delete</AlertDialogAction>
 *     </AlertDialogFooter>
 *   </AlertDialogContent>
 * </AlertDialog>
 * ```
 */
declare function AlertDialog({ ...props }: React$1.ComponentProps<typeof AlertDialogPrimitive.Root>): react_jsx_runtime.JSX.Element;
/** Element that opens the alert dialog when clicked. Use `asChild` to merge into your own button. */
declare function AlertDialogTrigger({ ...props }: React$1.ComponentProps<typeof AlertDialogPrimitive.Trigger>): react_jsx_runtime.JSX.Element;
/** Portal that renders alert dialog content outside the DOM hierarchy. */
declare function AlertDialogPortal({ ...props }: React$1.ComponentProps<typeof AlertDialogPrimitive.Portal>): react_jsx_runtime.JSX.Element;
/** Semi-transparent backdrop rendered behind the alert dialog content. */
declare function AlertDialogOverlay({ className, ...props }: React$1.ComponentProps<typeof AlertDialogPrimitive.Overlay>): react_jsx_runtime.JSX.Element;
/** Alert dialog content panel with overlay backdrop. */
declare function AlertDialogContent({ className, ...props }: React$1.ComponentProps<typeof AlertDialogPrimitive.Content>): react_jsx_runtime.JSX.Element;
/** Container for AlertDialogTitle and AlertDialogDescription. */
declare function AlertDialogHeader({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Container for AlertDialogAction and AlertDialogCancel buttons. */
declare function AlertDialogFooter({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Accessible title for the alert dialog. */
declare function AlertDialogTitle({ className, ...props }: React$1.ComponentProps<typeof AlertDialogPrimitive.Title>): react_jsx_runtime.JSX.Element;
/** Accessible description explaining the consequences of the action. */
declare function AlertDialogDescription({ className, ...props }: React$1.ComponentProps<typeof AlertDialogPrimitive.Description>): react_jsx_runtime.JSX.Element;
/** Primary action button that confirms and closes the alert dialog. */
declare function AlertDialogAction({ className, ...props }: React$1.ComponentProps<typeof AlertDialogPrimitive.Action>): react_jsx_runtime.JSX.Element;
/** Cancel button that dismisses the alert dialog without taking action. Styled as outline variant. */
declare function AlertDialogCancel({ className, ...props }: React$1.ComponentProps<typeof AlertDialogPrimitive.Cancel>): react_jsx_runtime.JSX.Element;

/**
 * Maintains a consistent width-to-height ratio for its content.
 *
 * Useful for images, videos, and maps that need to preserve their aspect ratio
 * across different viewport sizes. Built on Radix AspectRatio.
 *
 * @example
 * ```tsx
 * <AspectRatio ratio={16 / 9}>
 *   <img
 *     src="/hero.jpg"
 *     alt="Hero image"
 *     className="h-full w-full rounded-md object-cover"
 *   />
 * </AspectRatio>
 * ```
 */
declare function AspectRatio({ ...props }: React.ComponentProps<typeof AspectRatioPrimitive.Root>): react_jsx_runtime.JSX.Element;

type AvatarProps = React$1.ComponentProps<typeof AvatarPrimitive.Root>;
/**
 * Circular container for user profile images or initials.
 * Use with {@link AvatarImage} and {@link AvatarFallback} for graceful loading.
 *
 * @example
 * ```tsx
 * <Avatar>
 *   <AvatarImage src="/avatar.jpg" alt="User" />
 *   <AvatarFallback>JD</AvatarFallback>
 * </Avatar>
 *
 * // Custom size
 * <Avatar className="size-8">
 *   <AvatarImage src="/small.jpg" alt="User" />
 *   <AvatarFallback>U</AvatarFallback>
 * </Avatar>
 * ```
 */
declare function Avatar({ className, ...props }: AvatarProps): react_jsx_runtime.JSX.Element;
type AvatarImageProps = React$1.ComponentProps<typeof AvatarPrimitive.Image>;
/**
 * Image element rendered inside an {@link Avatar}. Falls back to
 * {@link AvatarFallback} when the image fails to load.
 *
 * @example
 * ```tsx
 * <AvatarImage src="/photo.jpg" alt="Jane Doe" />
 * ```
 */
declare function AvatarImage({ className, ...props }: AvatarImageProps): react_jsx_runtime.JSX.Element;
type AvatarFallbackProps = React$1.ComponentProps<typeof AvatarPrimitive.Fallback>;
/**
 * Fallback content displayed while the {@link AvatarImage} is loading or
 * if it fails. Typically shows user initials (max 2 characters).
 *
 * @example
 * ```tsx
 * <AvatarFallback>JD</AvatarFallback>
 * ```
 */
declare function AvatarFallback({ className, ...props }: AvatarFallbackProps): react_jsx_runtime.JSX.Element;

declare const badgeVariants: (props?: ({
    variant?: "default" | "destructive" | "secondary" | "outline" | "soft" | null | undefined;
    color?: "primary" | "destructive" | "success" | "warning" | "info" | null | undefined;
} & class_variance_authority_types.ClassProp) | undefined) => string;
interface BadgeProps extends React$1.ComponentProps<"span">, Omit<VariantProps<typeof badgeVariants>, "color"> {
    /**
     * Semantic color intent.
     *
     * @default "primary"
     * @example
     * ```tsx
     * <Badge color="success">Done</Badge>
     * <Badge color="warning">Pending</Badge>
     * <Badge variant="soft" color="destructive">Failed</Badge>
     * ```
     */
    color?: UIColor;
    /** Render as a child component using Radix Slot. @default false */
    asChild?: boolean;
}
/**
 * Inline status descriptor with semantic colors and visual variants.
 *
 * **Tokens used** (Phase B foundation — `plans/design-foundations-japanese.md`):
 * - Color combinations apply via cva compoundVariants over
 *   `--color-{primary,success,warning,info,destructive}`. Status colors map to
 *   和色 hue centers (若竹 success / 山吹 warning / 群青 info / 茜 destructive
 *   — NOT pure red, cited cultural rule).
 * - `rounded-md` → `--radius-md` = 4 px for tag-style badges; pass `rounded-full`
 *   on the className for pill-style status indicators.
 * - `text-xs` → `--text-xs` = 12 px / 18 px line-height (JMDC convergent CJK)
 * - 1 px border (`border` utility) per JP enterprise convention — borders > shadows
 *   for hierarchy (See Foundations / Cultural Notes in Storybook).
 *
 * @example
 * ```tsx
 * // Solid (default)
 * <Badge>New</Badge>
 * <Badge color="success">Done</Badge>
 * <Badge color="warning">Pending</Badge>
 *
 * // Soft (light tinted background)
 * <Badge variant="soft" color="success">Approved</Badge>
 * <Badge variant="soft" color="destructive">Rejected</Badge>
 *
 * // Outline
 * <Badge variant="outline">v1.0.0</Badge>
 * <Badge variant="outline" color="info">Beta</Badge>
 *
 * // Legacy (still works)
 * <Badge variant="destructive">Error</Badge>
 * <Badge variant="secondary">Draft</Badge>
 * ```
 */
declare function Badge({ className, variant, color, asChild, ...props }: BadgeProps): react_jsx_runtime.JSX.Element;

/**
 * Navigation breadcrumb trail showing the current page hierarchy.
 *
 * Renders as a `<nav>` with `aria-label="breadcrumb"` for accessibility.
 * Use `BreadcrumbSeparator` between items (defaults to a chevron icon)
 * and `BreadcrumbEllipsis` for collapsed intermediate items.
 *
 * @example
 * ```tsx
 * <Breadcrumb>
 *   <BreadcrumbList>
 *     <BreadcrumbItem>
 *       <BreadcrumbLink href="/">Home</BreadcrumbLink>
 *     </BreadcrumbItem>
 *     <BreadcrumbSeparator />
 *     <BreadcrumbItem>
 *       <BreadcrumbLink href="/projects">Projects</BreadcrumbLink>
 *     </BreadcrumbItem>
 *     <BreadcrumbSeparator />
 *     <BreadcrumbItem>
 *       <BreadcrumbPage>Current Project</BreadcrumbPage>
 *     </BreadcrumbItem>
 *   </BreadcrumbList>
 * </Breadcrumb>
 * ```
 */
declare function Breadcrumb({ ...props }: React$1.ComponentProps<"nav">): react_jsx_runtime.JSX.Element;
/** Ordered list container for breadcrumb items. Handles wrapping and spacing. */
declare function BreadcrumbList({ className, ...props }: React$1.ComponentProps<"ol">): react_jsx_runtime.JSX.Element;
/** Individual breadcrumb list item wrapping a link or page indicator. */
declare function BreadcrumbItem({ className, ...props }: React$1.ComponentProps<"li">): react_jsx_runtime.JSX.Element;
/**
 * Clickable breadcrumb link. Set `asChild` to render a custom element (e.g., React Router `Link`).
 *
 * @param asChild - When true, renders the child element instead of an `<a>` tag.
 */
declare function BreadcrumbLink({ asChild, className, ...props }: React$1.ComponentProps<"a"> & {
    asChild?: boolean;
}): react_jsx_runtime.JSX.Element;
/** Non-interactive breadcrumb label for the current page. Rendered with `aria-current="page"`. */
declare function BreadcrumbPage({ className, ...props }: React$1.ComponentProps<"span">): react_jsx_runtime.JSX.Element;
/** Visual separator between breadcrumb items. Defaults to a `ChevronRight` icon; pass custom children to override. */
declare function BreadcrumbSeparator({ children, className, ...props }: React$1.ComponentProps<"li">): react_jsx_runtime.JSX.Element;
/** Ellipsis indicator for collapsed breadcrumb items. Renders a `MoreHorizontal` icon with screen-reader text. */
declare function BreadcrumbEllipsis({ className, ...props }: React$1.ComponentProps<"span">): react_jsx_runtime.JSX.Element;

declare const buttonVariants: (props?: ({
    variant?: "link" | "default" | "destructive" | "secondary" | "outline" | "soft" | "ghost" | null | undefined;
    color?: "primary" | "destructive" | "success" | "warning" | "info" | null | undefined;
    size?: "default" | "xs" | "sm" | "lg" | "xl" | "icon" | null | undefined;
} & class_variance_authority_types.ClassProp) | undefined) => string;
interface ButtonProps extends React$1.ComponentProps<"button">, Omit<VariantProps<typeof buttonVariants>, "color"> {
    /**
     * Semantic color intent. Works with `variant` to produce the final appearance.
     *
     * | Color | Use for |
     * |-------|---------|
     * | `primary` | Main actions (default) |
     * | `destructive` | Delete, errors |
     * | `success` | Approve, confirm |
     * | `warning` | Caution, attention |
     * | `info` | Informational |
     *
     * @default "primary"
     * @example
     * ```tsx
     * <Button color="success">Approve</Button>
     * <Button variant="outline" color="destructive">Reject</Button>
     * <Button variant="soft" color="warning">Review</Button>
     * ```
     */
    color?: UIColor;
    /**
     * Render as a child component using Radix Slot.
     * When `true`, the button merges its props onto its single child element.
     * @default false
     */
    asChild?: boolean;
    /**
     * Make the button take the full width of its container.
     * @default false
     * @example
     * ```tsx
     * <Button block>Full Width</Button>
     * <Button size="xl" block>Sign In</Button>
     * ```
     */
    block?: boolean;
}
/**
 * Button component with semantic colors, visual variants, and standard sizes.
 *
 * Combines `variant` (how it looks) with `color` (what it means) for full flexibility.
 * All existing `variant="destructive"` usage continues to work unchanged.
 *
 * **Tokens used** (Phase B foundation — `plans/design-foundations-japanese.md`):
 * - Heights via `h-element-{xs,sm,default,lg,xl}` → `--density-element-*` tokens.
 *   Default 32 px shifts to 28 / 44 under `[data-density]` modes.
 * - `--color-primary` (oklch 56% 0.15 240 ≈ SmartHR MAIN, chroma ≤ 0.15 per 渋み)
 * - `--color-destructive` = 茜 (akane, NOT pure red — cited cultural rule)
 * - `--color-success` / `--color-warning` / `--color-info` mapped to 和色 hue centers
 * - `rounded-md` → `--radius-md` = 4 px (control radius, JP enterprise subtle)
 *
 * **Touch target**: only `size="xl"` (44 px) clears Digital Agency hard rule on its
 * own. Smaller sizes need a wrapper / `::before` padding to reach 44×44 on mobile.
 * See Foundations / Touch Targets in Storybook.
 *
 * @example
 * ```tsx
 * // Default (solid primary)
 * <Button>Save</Button>
 *
 * // Semantic colors
 * <Button color="success">Approve</Button>
 * <Button color="destructive">Delete</Button>
 * <Button color="warning">Proceed with caution</Button>
 *
 * // Variant × Color combinations
 * <Button variant="outline" color="destructive">Reject</Button>
 * <Button variant="soft" color="success">Approved</Button>
 * <Button variant="ghost" color="info">Learn more</Button>
 *
 * // Legacy (still works)
 * <Button variant="destructive">Delete</Button>
 *
 * // Sizes: xs (24px) | sm (28px) | default (32px) | lg (36px) | xl (44px) | icon (32x32)
 * <Button size="xs">Tiny</Button>
 * <Button size="xl" block>Sign In</Button>
 * <Button size="icon"><PlusIcon /></Button>
 * ```
 */
declare const Button: React$1.ForwardRefExoticComponent<Omit<ButtonProps, "ref"> & React$1.RefAttributes<HTMLButtonElement>>;

/**
 * Date picker calendar built on `react-day-picker` v9. Supports single, multiple,
 * and range selection modes. Styled with Shadcn UI conventions.
 *
 * @param showOutsideDays - Whether to show days from adjacent months. Defaults to `true`.
 *
 * @example
 * ```tsx
 * const [date, setDate] = useState<Date | undefined>();
 * <Calendar mode="single" selected={date} onSelect={setDate} />
 * ```
 */
declare function Calendar({ className, classNames, showOutsideDays, ...props }: React$1.ComponentProps<typeof DayPicker>): react_jsx_runtime.JSX.Element;

/**
 * Styled card container with header, title, description, action, content, and footer sub-components.
 *
 * **Tokens used** (Phase B foundation — `plans/design-foundations-japanese.md`):
 * - `gap-card` → `--spacing-card` = 16 px (24 px on `[data-density="comfortable"]`, 12 px on compact)
 * - `px-card` / `pt-card` / `pb-card` → same `--spacing-card` token
 * - `bg-card` / `text-card-foreground` → semantic role tokens (warm off-white / off-black per SmartHR)
 * - `rounded-lg` → `--radius-lg` = 6 px (SmartHR card radius — JP enterprise subtle)
 * - `border` = 1 px hairline (border > shadow per JP enterprise convention)
 *
 * The Card automatically adopts the active density mode via the density tokens
 * — wrap a subtree in `<div data-density="compact">` or `"comfortable"` to shift.
 *
 * @example
 * ```tsx
 * <Card>
 *   <CardHeader>
 *     <CardTitle>Notifications</CardTitle>
 *     <CardDescription>You have 3 unread messages.</CardDescription>
 *     <CardAction>
 *       <Button variant="outline">Mark all read</Button>
 *     </CardAction>
 *   </CardHeader>
 *   <CardContent>
 *     <p>Your recent activity will appear here.</p>
 *   </CardContent>
 *   <CardFooter>
 *     <Button>View all</Button>
 *   </CardFooter>
 * </Card>
 * ```
 */
declare function Card({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/**
 * Card header section. Lays out title, description, and optional action in a grid.
 *
 * **Tokens used:**
 * - `px-card` / `pt-card` / `pb-card` → `--spacing-card`
 * - `gap-2` (8 px = `--spacing-2`) — title-to-description gap inside the header.
 *   Sits between the related-items "tight" yohaku step (4 px) and the inside-card
 *   "default" (16 px) — see Foundations / Spacing for the 1:1.5:3 ratio.
 */
declare function CardHeader({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Card title rendered as an `<h4>` element. */
declare function CardTitle({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Card description text displayed in muted foreground color. */
declare function CardDescription({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Card action slot positioned at the top-right of `CardHeader`. Place buttons or menus here. */
declare function CardAction({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Card content area with horizontal padding. Bottom padding applied when last child. */
declare function CardContent({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/**
 * Edge-to-edge media slot for image / video / illustration cards (Pinterest,
 * product gallery, blog preview, etc).
 *
 * Unlike the other Card sub-components, `CardMedia` has **no horizontal
 * padding** — the child media fills the full Card width. When `CardMedia` is
 * the first child of `Card` it rounds its top corners to match the Card's
 * border radius; when it's the last child it rounds its bottom corners.
 *
 * The default has no aspect-ratio constraint — pass `aspectRatio` (any
 * Tailwind aspect-ratio class string fragment, e.g. `"16/9"`, `"4/3"`,
 * `"square"`) for a consistent gallery layout.
 *
 * Place an `<img>`, `<video>`, or Next.js `<Image fill>` inside.
 *
 * @example
 * ```tsx
 * <Card className="w-72 overflow-hidden">
 *   <CardMedia aspectRatio="16/9">
 *     <img src="/cover.jpg" alt="" className="size-full object-cover" />
 *   </CardMedia>
 *   <CardHeader>
 *     <CardTitle>Yakiniku platter</CardTitle>
 *     <CardDescription>From the spring menu</CardDescription>
 *   </CardHeader>
 * </Card>
 * ```
 */
declare function CardMedia({ className, aspectRatio, children, ...props }: React$1.ComponentProps<"div"> & {
    aspectRatio?: string;
}): react_jsx_runtime.JSX.Element;
/** Card footer with horizontal layout. Typically used for action buttons. */
declare function CardFooter({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;

type CarouselApi = ReturnType<typeof useEmblaCarousel>[1];
type UseCarouselParameters = Parameters<typeof useEmblaCarousel>;
type CarouselOptions = UseCarouselParameters[0];
type CarouselPlugin = UseCarouselParameters[1];
type CarouselProps = {
    /** Embla Carousel options (e.g., `{ loop: true, align: "start" }`). */
    opts?: CarouselOptions;
    /** Embla Carousel plugins (e.g., Autoplay, ClassNames). */
    plugins?: CarouselPlugin;
    /** Scroll axis direction. Defaults to `"horizontal"`. */
    orientation?: "horizontal" | "vertical";
    /** Callback to receive the Embla API instance for external control. */
    setApi?: (api: CarouselApi) => void;
};
/**
 * Carousel/slider component powered by Embla Carousel.
 *
 * Provides a context for child components (`CarouselContent`, `CarouselItem`,
 * `CarouselPrevious`, `CarouselNext`). Supports horizontal/vertical orientation,
 * keyboard navigation (arrow keys), and plugin extensibility.
 *
 * @example
 * ```tsx
 * <Carousel opts={{ loop: true }}>
 *   <CarouselContent>
 *     <CarouselItem>Slide 1</CarouselItem>
 *     <CarouselItem>Slide 2</CarouselItem>
 *     <CarouselItem>Slide 3</CarouselItem>
 *   </CarouselContent>
 *   <CarouselPrevious />
 *   <CarouselNext />
 * </Carousel>
 * ```
 */
declare function Carousel({ orientation, opts, setApi, plugins, className, children, ...props }: React$1.ComponentProps<"div"> & CarouselProps): react_jsx_runtime.JSX.Element;
/** Scrollable container for `CarouselItem` elements. Manages the overflow viewport. */
declare function CarouselContent({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Individual slide within the carousel. Defaults to full-width (`basis-full`). */
declare function CarouselItem({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Navigation button to scroll to the previous slide. Automatically disabled when at the beginning. */
declare function CarouselPrevious({ className, variant, size, ...props }: React$1.ComponentProps<typeof Button>): react_jsx_runtime.JSX.Element;
/** Navigation button to scroll to the next slide. Automatically disabled when at the end. */
declare function CarouselNext({ className, variant, size, ...props }: React$1.ComponentProps<typeof Button>): react_jsx_runtime.JSX.Element;

type CheckboxProps = React$1.ComponentPropsWithoutRef<typeof CheckboxPrimitive.Root>;
/**
 * Checkable input that allows selecting one or more options from a set.
 * Supports checked, unchecked, and indeterminate states.
 *
 * @example
 * ```tsx
 * // Basic usage
 * <Checkbox id="terms" />
 * <Label htmlFor="terms">Accept terms</Label>
 *
 * // Controlled
 * <Checkbox checked={accepted} onCheckedChange={setAccepted} />
 *
 * // Indeterminate (partial selection)
 * <Checkbox checked="indeterminate" />
 * ```
 */
declare const Checkbox: React$1.ForwardRefExoticComponent<Omit<CheckboxPrimitive.CheckboxProps & React$1.RefAttributes<HTMLButtonElement>, "ref"> & React$1.RefAttributes<HTMLButtonElement>>;

/**
 * Expandable/collapsible container built on Radix Collapsible.
 *
 * Manages open/closed state for a single content region. For multiple
 * collapsible sections, consider using `Accordion` instead.
 *
 * @example
 * ```tsx
 * <Collapsible>
 *   <CollapsibleTrigger asChild>
 *     <Button variant="ghost">Toggle details</Button>
 *   </CollapsibleTrigger>
 *   <CollapsibleContent>
 *     <p>Hidden content revealed on toggle.</p>
 *   </CollapsibleContent>
 * </Collapsible>
 * ```
 */
declare function Collapsible({ ...props }: React.ComponentProps<typeof CollapsiblePrimitive.Root>): react_jsx_runtime.JSX.Element;
/** Button or element that toggles the collapsible open/closed state. Supports `asChild` for custom trigger elements. */
declare function CollapsibleTrigger({ ...props }: React.ComponentProps<typeof CollapsiblePrimitive.CollapsibleTrigger>): react_jsx_runtime.JSX.Element;
/** Content region that shows/hides when the collapsible is toggled. */
declare function CollapsibleContent({ ...props }: React.ComponentProps<typeof CollapsiblePrimitive.CollapsibleContent>): react_jsx_runtime.JSX.Element;

interface ColorPickerProps {
    /** Currently selected color as a hex string (e.g., `"#3B82F6"`). */
    value?: string;
    /** Callback fired when a color is selected. Receives a hex string. */
    onChange?: (color: string) => void;
    /** Additional CSS class for the trigger button. */
    className?: string;
    /** Whether the color picker is disabled. */
    disabled?: boolean;
    /** Whether to show the preset color grid. Defaults to `true`. */
    showPresets?: boolean;
    /** Whether to show the custom hex input with native color picker. Defaults to `true`. */
    showInput?: boolean;
}
/**
 * Color picker with a popover containing preset color swatches and an optional custom hex input.
 * The trigger button shows the currently selected color swatch and its hex value.
 *
 * @example
 * ```tsx
 * const [color, setColor] = useState("#3B82F6");
 *
 * <ColorPicker
 *   value={color}
 *   onChange={setColor}
 *   showPresets
 *   showInput
 * />
 * ```
 */
declare function ColorPicker({ value, onChange, className, disabled, showPresets, showInput, }: ColorPickerProps): react_jsx_runtime.JSX.Element;

/** A single option in the Combobox dropdown. */
interface ComboboxOption {
    /** Unique value for this option. */
    value: string;
    /** Display label shown in the dropdown list. */
    label: string;
    /** Whether this option is non-selectable. */
    disabled?: boolean;
}
interface ComboboxProps {
    /** Available options to display in the dropdown. */
    options: ComboboxOption[];
    /** Currently selected value. */
    value?: string;
    /** Callback fired when the selected value changes. */
    onChange?: (value: string) => void;
    /** Placeholder text shown when no value is selected. */
    placeholder?: string;
    /** Placeholder text for the search input inside the dropdown. */
    searchPlaceholder?: string;
    /** Text shown when no options match the search query. */
    emptyText?: string;
    /** Additional CSS class for the trigger button. */
    className?: string;
    /** Whether the combobox is disabled. */
    disabled?: boolean;
    /** Whether to show a clear button when a value is selected. */
    clearable?: boolean;
    /**
     * Single-string validation error. When set, the trigger gets
     * `aria-invalid` and a red error message is rendered below.
     */
    error?: string;
}
/**
 * Searchable single-select combobox built on cmdk and Radix Popover.
 * Combines a text search input with a selectable option list.
 *
 * @example
 * ```tsx
 * const [value, setValue] = useState("");
 *
 * <Combobox
 *   options={[
 *     { value: "react", label: "React" },
 *     { value: "vue", label: "Vue" },
 *     { value: "svelte", label: "Svelte" },
 *   ]}
 *   value={value}
 *   onChange={setValue}
 *   placeholder="Select framework..."
 *   searchPlaceholder="Search..."
 *   clearable
 * />
 * ```
 */
declare function Combobox({ options, value, onChange, placeholder, searchPlaceholder, emptyText, className, disabled, clearable, error, }: ComboboxProps): react_jsx_runtime.JSX.Element;
interface MultiComboboxProps {
    /** Available options to display in the dropdown. */
    options: ComboboxOption[];
    /** Array of currently selected values. */
    value?: string[];
    /** Callback fired when the selection changes. */
    onChange?: (value: string[]) => void;
    /** Placeholder text shown when no values are selected. */
    placeholder?: string;
    /** Placeholder text for the search input inside the dropdown. */
    searchPlaceholder?: string;
    /** Text shown when no options match the search query. */
    emptyText?: string;
    /** Additional CSS class for the trigger button. */
    className?: string;
    /** Whether the combobox is disabled. */
    disabled?: boolean;
    /** Maximum number of items that can be selected. */
    maxSelected?: number;
    /**
     * Single-string validation error. When set, the trigger gets
     * `aria-invalid` and a red error message is rendered below.
     */
    error?: string;
}
/**
 * Searchable multi-select combobox that allows selecting multiple values.
 * Selected items are shown as a count in the trigger button.
 *
 * @example
 * ```tsx
 * const [selected, setSelected] = useState<string[]>([]);
 *
 * <MultiCombobox
 *   options={[
 *     { value: "react", label: "React" },
 *     { value: "vue", label: "Vue" },
 *     { value: "svelte", label: "Svelte" },
 *   ]}
 *   value={selected}
 *   onChange={setSelected}
 *   placeholder="Select frameworks..."
 *   maxSelected={3}
 * />
 * ```
 */
declare function MultiCombobox({ options, value, onChange, placeholder, searchPlaceholder, emptyText, className, disabled, maxSelected, error, }: MultiComboboxProps): react_jsx_runtime.JSX.Element;

/**
 * Modal dialog component built on Radix UI Dialog.
 *
 * Renders a centered overlay panel that interrupts the user with important content
 * and expects a response. Supports controlled (`open`/`onOpenChange`) and
 * uncontrolled usage (via `DialogTrigger`).
 *
 * **When to use:** forms, content viewers, non-destructive confirmations.
 * Click-outside and Escape both dismiss. For irreversible actions (delete,
 * force-logout) use `<AlertDialog>` instead — the lack of overlay-click dismiss
 * is intentional friction. For touch-first mobile UX with swipe-to-dismiss use
 * `<Drawer>`. For desktop side-rail content (filters, settings) use `<Sheet>`.
 *
 * @example
 * ```tsx
 * <Dialog open={open} onOpenChange={setOpen}>
 *   <DialogTrigger asChild>
 *     <Button>Open Dialog</Button>
 *   </DialogTrigger>
 *   <DialogContent>
 *     <DialogHeader>
 *       <DialogTitle>Edit Profile</DialogTitle>
 *       <DialogDescription>
 *         Make changes to your profile here.
 *       </DialogDescription>
 *     </DialogHeader>
 *     <div className="space-y-4">
 *       <Input placeholder="Name" />
 *     </div>
 *     <DialogFooter>
 *       <Button variant="outline" onClick={() => setOpen(false)}>
 *         Cancel
 *       </Button>
 *       <Button>Save Changes</Button>
 *     </DialogFooter>
 *   </DialogContent>
 * </Dialog>
 * ```
 */
declare function Dialog({ ...props }: React$1.ComponentProps<typeof DialogPrimitive.Root>): react_jsx_runtime.JSX.Element;
/** Element that opens the dialog when clicked. Use `asChild` to merge into your own button. */
declare function DialogTrigger({ ...props }: React$1.ComponentProps<typeof DialogPrimitive.Trigger>): react_jsx_runtime.JSX.Element;
/** Portal that renders dialog content outside the DOM hierarchy. */
declare function DialogPortal({ ...props }: React$1.ComponentProps<typeof DialogPrimitive.Portal>): react_jsx_runtime.JSX.Element;
/** Button that closes the dialog. Use `asChild` to merge into your own button. */
declare function DialogClose({ ...props }: React$1.ComponentProps<typeof DialogPrimitive.Close>): react_jsx_runtime.JSX.Element;
/** Semi-transparent backdrop rendered behind the dialog content. */
declare const DialogOverlay: React$1.ForwardRefExoticComponent<Omit<DialogPrimitive.DialogOverlayProps & React$1.RefAttributes<HTMLDivElement>, "ref"> & React$1.RefAttributes<HTMLDivElement>>;
/** Dialog content panel with overlay backdrop and a built-in close button. */
declare const DialogContent: React$1.ForwardRefExoticComponent<Omit<DialogPrimitive.DialogContentProps & React$1.RefAttributes<HTMLDivElement>, "ref"> & React$1.RefAttributes<HTMLDivElement>>;
/** Container for DialogTitle and DialogDescription at the top of the dialog. */
declare function DialogHeader({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Container for action buttons at the bottom of the dialog. */
declare function DialogFooter({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Accessible title rendered inside DialogHeader. */
declare function DialogTitle({ className, ...props }: React$1.ComponentProps<typeof DialogPrimitive.Title>): react_jsx_runtime.JSX.Element;
/** Accessible description rendered inside DialogHeader below the title. */
declare function DialogDescription({ className, ...props }: React$1.ComponentProps<typeof DialogPrimitive.Description>): react_jsx_runtime.JSX.Element;

/**
 * Command palette component built on cmdk.
 *
 * Provides a searchable, keyboard-navigable list of commands or options.
 * Use `CommandDialog` for a modal command palette, or `Command` inline
 * for embedded search/filter interfaces like comboboxes.
 *
 * @example
 * ```tsx
 * <CommandDialog open={open} onOpenChange={setOpen}>
 *   <CommandInput placeholder="Type a command or search..." />
 *   <CommandList>
 *     <CommandEmpty>No results found.</CommandEmpty>
 *     <CommandGroup heading="Suggestions">
 *       <CommandItem>
 *         <CalendarIcon className="size-4" />
 *         Calendar
 *       </CommandItem>
 *       <CommandItem>
 *         <SearchIcon className="size-4" />
 *         Search
 *         <CommandShortcut>Ctrl+K</CommandShortcut>
 *       </CommandItem>
 *     </CommandGroup>
 *     <CommandSeparator />
 *     <CommandGroup heading="Settings">
 *       <CommandItem>
 *         <SettingsIcon className="size-4" />
 *         Settings
 *       </CommandItem>
 *     </CommandGroup>
 *   </CommandList>
 * </CommandDialog>
 * ```
 */
declare function Command({ className, ...props }: React$1.ComponentProps<typeof Command$1>): react_jsx_runtime.JSX.Element;
/** Modal wrapper that renders a Command palette inside a Dialog. Accepts optional `title` and `description` for accessibility. */
declare function CommandDialog({ title, description, children, ...props }: React$1.ComponentProps<typeof Dialog> & {
    title?: string;
    description?: string;
}): react_jsx_runtime.JSX.Element;
/** Search input field with a magnifying glass icon for filtering command items. */
declare function CommandInput({ className, ...props }: React$1.ComponentProps<typeof Command$1.Input>): react_jsx_runtime.JSX.Element;
/** Scrollable container for command groups and items. */
declare function CommandList({ className, ...props }: React$1.ComponentProps<typeof Command$1.List>): react_jsx_runtime.JSX.Element;
/** Fallback content shown when no command items match the search query. */
declare function CommandEmpty({ ...props }: React$1.ComponentProps<typeof Command$1.Empty>): react_jsx_runtime.JSX.Element;
/** Groups related command items under an optional heading. */
declare function CommandGroup({ className, ...props }: React$1.ComponentProps<typeof Command$1.Group>): react_jsx_runtime.JSX.Element;
/** Visual divider between groups of command items. */
declare function CommandSeparator({ className, ...props }: React$1.ComponentProps<typeof Command$1.Separator>): react_jsx_runtime.JSX.Element;
/** Selectable command item that can be navigated with keyboard arrows and activated with Enter. */
declare function CommandItem({ className, ...props }: React$1.ComponentProps<typeof Command$1.Item>): react_jsx_runtime.JSX.Element;
/** Keyboard shortcut hint displayed at the end of a command item. */
declare function CommandShortcut({ className, ...props }: React$1.ComponentProps<"span">): react_jsx_runtime.JSX.Element;

/**
 * Right-click context menu built on Radix UI ContextMenu.
 *
 * Displays a menu of actions when the user right-clicks (or long-presses)
 * on the trigger area. Supports items, checkbox items, radio items,
 * sub-menus, separators, labels, and shortcuts.
 *
 * **When to use:** ONLY when the user model expects right-click affordance —
 * file managers, canvas editors, code editors, table cells with rich
 * per-cell actions. Most action menus should use `<DropdownMenu>` instead
 * (button trigger is more discoverable). Don't use `<ContextMenu>` as the
 * primary affordance for an action — touch users can't right-click.
 *
 * @example
 * ```tsx
 * <ContextMenu>
 *   <ContextMenuTrigger className="flex h-40 w-64 items-center justify-center rounded-md border border-dashed">
 *     Right click here
 *   </ContextMenuTrigger>
 *   <ContextMenuContent>
 *     <ContextMenuItem>
 *       Copy
 *       <ContextMenuShortcut>Ctrl+C</ContextMenuShortcut>
 *     </ContextMenuItem>
 *     <ContextMenuItem>
 *       Paste
 *       <ContextMenuShortcut>Ctrl+V</ContextMenuShortcut>
 *     </ContextMenuItem>
 *     <ContextMenuSeparator />
 *     <ContextMenuItem variant="destructive">Delete</ContextMenuItem>
 *   </ContextMenuContent>
 * </ContextMenu>
 * ```
 */
declare function ContextMenu({ ...props }: React$1.ComponentProps<typeof ContextMenuPrimitive.Root>): react_jsx_runtime.JSX.Element;
/** Area that opens the context menu on right-click. */
declare function ContextMenuTrigger({ ...props }: React$1.ComponentProps<typeof ContextMenuPrimitive.Trigger>): react_jsx_runtime.JSX.Element;
/** Groups related context menu items together for accessibility. */
declare function ContextMenuGroup({ ...props }: React$1.ComponentProps<typeof ContextMenuPrimitive.Group>): react_jsx_runtime.JSX.Element;
/** Portal that renders context menu content outside the DOM hierarchy. */
declare function ContextMenuPortal({ ...props }: React$1.ComponentProps<typeof ContextMenuPrimitive.Portal>): react_jsx_runtime.JSX.Element;
/** Container for a nested sub-menu within the context menu. */
declare function ContextMenuSub({ ...props }: React$1.ComponentProps<typeof ContextMenuPrimitive.Sub>): react_jsx_runtime.JSX.Element;
/** Container for radio context menu items where only one can be selected at a time. */
declare function ContextMenuRadioGroup({ ...props }: React$1.ComponentProps<typeof ContextMenuPrimitive.RadioGroup>): react_jsx_runtime.JSX.Element;
/** Menu item that opens a sub-menu on hover. Displays a chevron indicator. */
declare function ContextMenuSubTrigger({ className, inset, children, ...props }: React$1.ComponentProps<typeof ContextMenuPrimitive.SubTrigger> & {
    inset?: boolean;
}): react_jsx_runtime.JSX.Element;
/** Floating container for sub-menu items. */
declare function ContextMenuSubContent({ className, ...props }: React$1.ComponentProps<typeof ContextMenuPrimitive.SubContent>): react_jsx_runtime.JSX.Element;
/** Floating container for context menu items, positioned at the cursor location. */
declare function ContextMenuContent({ className, ...props }: React$1.ComponentProps<typeof ContextMenuPrimitive.Content>): react_jsx_runtime.JSX.Element;
/** Actionable menu item. Set `variant="destructive"` for dangerous actions, `inset` for left-padding alignment. */
declare function ContextMenuItem({ className, inset, variant, ...props }: React$1.ComponentProps<typeof ContextMenuPrimitive.Item> & {
    inset?: boolean;
    variant?: "default" | "destructive";
}): react_jsx_runtime.JSX.Element;
/** Menu item with a checkbox indicator for toggling options. */
declare function ContextMenuCheckboxItem({ className, children, checked, ...props }: React$1.ComponentProps<typeof ContextMenuPrimitive.CheckboxItem>): react_jsx_runtime.JSX.Element;
/** Menu item with a radio indicator for single-selection groups. */
declare function ContextMenuRadioItem({ className, children, ...props }: React$1.ComponentProps<typeof ContextMenuPrimitive.RadioItem>): react_jsx_runtime.JSX.Element;
/** Non-interactive label used to title a group of menu items. */
declare function ContextMenuLabel({ className, inset, ...props }: React$1.ComponentProps<typeof ContextMenuPrimitive.Label> & {
    inset?: boolean;
}): react_jsx_runtime.JSX.Element;
/** Visual divider between groups of menu items. */
declare function ContextMenuSeparator({ className, ...props }: React$1.ComponentProps<typeof ContextMenuPrimitive.Separator>): react_jsx_runtime.JSX.Element;
/** Keyboard shortcut hint displayed at the end of a menu item. */
declare function ContextMenuShortcut({ className, ...props }: React$1.ComponentProps<"span">): react_jsx_runtime.JSX.Element;

interface DatePickerProps {
    /** Currently selected date. */
    value?: Date;
    /** Callback fired when a date is selected or cleared. */
    onChange?: (date: Date | undefined) => void;
    /** Placeholder text shown when no date is selected. */
    placeholder?: string;
    /** Additional CSS class for the trigger button. */
    className?: string;
    /** Whether the date picker is disabled. */
    disabled?: boolean;
    /** date-fns Locale object for date formatting (e.g., `ja` for Japanese). */
    locale?: Locale;
    /**
     * Single-string validation error. When set, the trigger gets
     * `aria-invalid` and a red error message is rendered below.
     */
    error?: string;
}
/**
 * Single date picker with a calendar popover.
 * Displays the selected date formatted with date-fns and opens a calendar on click.
 *
 * @example
 * ```tsx
 * const [date, setDate] = useState<Date>();
 *
 * <DatePicker
 *   value={date}
 *   onChange={setDate}
 *   placeholder="Pick a date"
 * />
 * ```
 */
declare function DatePicker({ value, onChange, placeholder, className, disabled, locale: localeProp, error, }: DatePickerProps): react_jsx_runtime.JSX.Element;
interface DateRangePickerProps {
    /** Currently selected date range with `from` and optional `to`. */
    value?: {
        from: Date | undefined;
        to?: Date | undefined;
    };
    /** Callback fired when the date range changes. */
    onChange?: (range: {
        from: Date | undefined;
        to?: Date | undefined;
    } | undefined) => void;
    /** Placeholder text shown when no range is selected. */
    placeholder?: string;
    /** Additional CSS class for the trigger button. */
    className?: string;
    /** Whether the date range picker is disabled. */
    disabled?: boolean;
    /** date-fns Locale object for date formatting (e.g., `ja` for Japanese). */
    locale?: Locale;
    /**
     * Single-string validation error. When set, the trigger gets
     * `aria-invalid` and a red error message is rendered below.
     */
    error?: string;
}
/**
 * Date range picker with a two-month calendar popover.
 * Allows selecting a start and end date displayed as a range string.
 *
 * @example
 * ```tsx
 * const [range, setRange] = useState<{ from: Date | undefined; to?: Date }>();
 *
 * <DateRangePicker
 *   value={range}
 *   onChange={setRange}
 *   placeholder="Select date range"
 * />
 * ```
 */
declare function DateRangePicker({ value, onChange, placeholder, className, disabled, locale: localeProp, error, }: DateRangePickerProps): react_jsx_runtime.JSX.Element;

/**
 * Swipeable drawer component built on Vaul.
 *
 * Slides from any edge of the screen and can be dismissed by swiping.
 * Set the `direction` prop on the root to control direction (`"top"`, `"bottom"`,
 * `"left"`, `"right"`). Always wrap content in `DrawerBody` for proper scrolling.
 *
 * **When to use:** mobile-first touch UX where swipe-to-dismiss is expected
 * (mobile filters, action sheets, picker bottom-sheets). For desktop-first side
 * panels without swipe affordance use `<Sheet>`. For centered modal dialogs
 * use `<Dialog>`. For destructive confirmations use `<AlertDialog>`.
 *
 * @example
 * ```tsx
 * <Drawer open={open} onOpenChange={setOpen}>
 *   <DrawerTrigger asChild>
 *     <Button variant="outline">Open Drawer</Button>
 *   </DrawerTrigger>
 *   <DrawerContent>
 *     <DrawerHeader>
 *       <DrawerTitle>Task Details</DrawerTitle>
 *       <DrawerDescription>
 *         View and edit task information.
 *       </DrawerDescription>
 *     </DrawerHeader>
 *     <DrawerBody>
 *       <p>Scrollable content goes here.</p>
 *     </DrawerBody>
 *     <DrawerFooter>
 *       <Button>Save</Button>
 *       <DrawerClose asChild>
 *         <Button variant="outline">Cancel</Button>
 *       </DrawerClose>
 *     </DrawerFooter>
 *   </DrawerContent>
 * </Drawer>
 * ```
 */
declare function Drawer({ ...props }: React$1.ComponentProps<typeof Drawer$1.Root>): react_jsx_runtime.JSX.Element;
/** Element that opens the drawer when clicked. Use `asChild` to merge into your own button. */
declare function DrawerTrigger({ ...props }: React$1.ComponentProps<typeof Drawer$1.Trigger>): react_jsx_runtime.JSX.Element;
/** Portal that renders drawer content outside the DOM hierarchy. */
declare function DrawerPortal({ ...props }: React$1.ComponentProps<typeof Drawer$1.Portal>): react_jsx_runtime.JSX.Element;
/** Button that closes the drawer. Use `asChild` to merge into your own button. */
declare function DrawerClose({ ...props }: React$1.ComponentProps<typeof Drawer$1.Close>): react_jsx_runtime.JSX.Element;
/** Semi-transparent backdrop rendered behind the drawer panel. */
declare function DrawerOverlay({ className, ...props }: React$1.ComponentProps<typeof Drawer$1.Overlay>): react_jsx_runtime.JSX.Element;
/** Drawer content panel that slides in from the configured direction. Includes a drag handle for bottom drawers. */
declare function DrawerContent({ className, children, ...props }: React$1.ComponentProps<typeof Drawer$1.Content>): react_jsx_runtime.JSX.Element;
/** Container for DrawerTitle and DrawerDescription at the top of the drawer. */
declare function DrawerHeader({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Scrollable body area for drawer content. Always wrap main content in this component. */
declare function DrawerBody({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Container for action buttons at the bottom of the drawer. Pushed to the bottom via `mt-auto`. */
declare function DrawerFooter({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Accessible title rendered inside DrawerHeader. */
declare function DrawerTitle({ className, ...props }: React$1.ComponentProps<typeof Drawer$1.Title>): react_jsx_runtime.JSX.Element;
/** Accessible description rendered inside DrawerHeader below the title. */
declare function DrawerDescription({ className, ...props }: React$1.ComponentProps<typeof Drawer$1.Description>): react_jsx_runtime.JSX.Element;

/**
 * Dropdown menu component built on Radix UI DropdownMenu.
 *
 * Displays a menu of actions or options triggered by a button click.
 * Supports items, checkbox items, radio items, sub-menus, separators,
 * labels, shortcuts, and destructive variants.
 *
 * **When to use:** button-triggered actions on a single subject — table row
 * actions, user avatar menu, kebab "more options" overflow. For right-click
 * affordance on a UI element use `<ContextMenu>`. For app-chrome top menu
 * bars (File / Edit / View) use `<Menubar>`. For site/app-wide primary
 * navigation use `<NavigationMenu>`.
 *
 * @example
 * ```tsx
 * <DropdownMenu>
 *   <DropdownMenuTrigger asChild>
 *     <Button variant="outline">Actions</Button>
 *   </DropdownMenuTrigger>
 *   <DropdownMenuContent>
 *     <DropdownMenuLabel>My Account</DropdownMenuLabel>
 *     <DropdownMenuSeparator />
 *     <DropdownMenuItem>
 *       <UserIcon className="size-4" />
 *       Profile
 *       <DropdownMenuShortcut>Ctrl+P</DropdownMenuShortcut>
 *     </DropdownMenuItem>
 *     <DropdownMenuItem>
 *       <SettingsIcon className="size-4" />
 *       Settings
 *     </DropdownMenuItem>
 *     <DropdownMenuSeparator />
 *     <DropdownMenuItem variant="destructive">
 *       <TrashIcon className="size-4" />
 *       Delete
 *     </DropdownMenuItem>
 *   </DropdownMenuContent>
 * </DropdownMenu>
 * ```
 */
declare function DropdownMenu({ ...props }: React$1.ComponentProps<typeof DropdownMenuPrimitive.Root>): react_jsx_runtime.JSX.Element;
/** Portal that renders dropdown content outside the DOM hierarchy. */
declare function DropdownMenuPortal({ ...props }: React$1.ComponentProps<typeof DropdownMenuPrimitive.Portal>): react_jsx_runtime.JSX.Element;
/** Element that opens the dropdown menu when clicked. Use `asChild` to merge into your own button. */
declare function DropdownMenuTrigger({ ...props }: React$1.ComponentProps<typeof DropdownMenuPrimitive.Trigger>): react_jsx_runtime.JSX.Element;
/** Floating container for menu items, positioned relative to the trigger. */
declare function DropdownMenuContent({ className, sideOffset, ...props }: React$1.ComponentProps<typeof DropdownMenuPrimitive.Content>): react_jsx_runtime.JSX.Element;
/** Groups related menu items together for accessibility. */
declare function DropdownMenuGroup({ ...props }: React$1.ComponentProps<typeof DropdownMenuPrimitive.Group>): react_jsx_runtime.JSX.Element;
/** Actionable menu item. Set `variant="destructive"` for dangerous actions, `inset` for left-padding alignment. */
declare function DropdownMenuItem({ className, inset, variant, ...props }: React$1.ComponentProps<typeof DropdownMenuPrimitive.Item> & {
    inset?: boolean;
    variant?: "default" | "destructive";
}): react_jsx_runtime.JSX.Element;
/** Menu item with a checkbox indicator for toggling options. */
declare function DropdownMenuCheckboxItem({ className, children, checked, ...props }: React$1.ComponentProps<typeof DropdownMenuPrimitive.CheckboxItem>): react_jsx_runtime.JSX.Element;
/** Container for radio menu items where only one can be selected at a time. */
declare function DropdownMenuRadioGroup({ ...props }: React$1.ComponentProps<typeof DropdownMenuPrimitive.RadioGroup>): react_jsx_runtime.JSX.Element;
/** Menu item with a radio indicator for single-selection groups. */
declare function DropdownMenuRadioItem({ className, children, ...props }: React$1.ComponentProps<typeof DropdownMenuPrimitive.RadioItem>): react_jsx_runtime.JSX.Element;
/** Non-interactive label used to title a group of menu items. */
declare function DropdownMenuLabel({ className, inset, ...props }: React$1.ComponentProps<typeof DropdownMenuPrimitive.Label> & {
    inset?: boolean;
}): react_jsx_runtime.JSX.Element;
/** Visual divider between groups of menu items. */
declare function DropdownMenuSeparator({ className, ...props }: React$1.ComponentProps<typeof DropdownMenuPrimitive.Separator>): react_jsx_runtime.JSX.Element;
/** Keyboard shortcut hint displayed at the end of a menu item. */
declare function DropdownMenuShortcut({ className, ...props }: React$1.ComponentProps<"span">): react_jsx_runtime.JSX.Element;
/** Container for a nested sub-menu within the dropdown. */
declare function DropdownMenuSub({ ...props }: React$1.ComponentProps<typeof DropdownMenuPrimitive.Sub>): react_jsx_runtime.JSX.Element;
/** Menu item that opens a sub-menu on hover. Displays a chevron indicator. */
declare function DropdownMenuSubTrigger({ className, inset, children, ...props }: React$1.ComponentProps<typeof DropdownMenuPrimitive.SubTrigger> & {
    inset?: boolean;
}): react_jsx_runtime.JSX.Element;
/** Floating container for sub-menu items. */
declare function DropdownMenuSubContent({ className, ...props }: React$1.ComponentProps<typeof DropdownMenuPrimitive.SubContent>): react_jsx_runtime.JSX.Element;

type FileUploadVariant = "dropzone" | "compact" | "avatar" | "gallery" | "inline";
interface FileUploadProps {
    value?: File[];
    onChange?: (files: File[]) => void;
    accept?: string;
    multiple?: boolean;
    maxSize?: number;
    maxFiles?: number;
    disabled?: boolean;
    className?: string;
    showPreview?: boolean;
    variant?: FileUploadVariant;
    placeholder?: string;
    hint?: string;
}
declare function FileUpload({ value, onChange, accept, multiple, maxSize, maxFiles, disabled, className, showPreview, variant, placeholder, hint, }: FileUploadProps): react_jsx_runtime.JSX.Element;

/**
 * Form provider component built on react-hook-form's FormProvider.
 * Wraps form fields and provides form context for validation, error display, and accessibility.
 *
 * @example
 * ```tsx
 * const form = useForm({ defaultValues: { email: "" } });
 *
 * <Form {...form}>
 *   <form onSubmit={form.handleSubmit(onSubmit)}>
 *     <FormField
 *       control={form.control}
 *       name="email"
 *       render={({ field }) => (
 *         <FormItem>
 *           <FormLabel>Email</FormLabel>
 *           <FormControl>
 *             <Input placeholder="you@example.com" {...field} />
 *           </FormControl>
 *           <FormDescription>Your work email address.</FormDescription>
 *           <FormMessage />
 *         </FormItem>
 *       )}
 *     />
 *     <Button type="submit">Submit</Button>
 *   </form>
 * </Form>
 * ```
 */
declare const Form: <TFieldValues extends FieldValues, TContext = any, TTransformedValues = TFieldValues>(props: react_hook_form.FormProviderProps<TFieldValues, TContext, TTransformedValues>) => React$1.JSX.Element;
/** Connects a form field to react-hook-form's Controller and provides field context. */
declare const FormField: <TFieldValues extends FieldValues = FieldValues, TName extends FieldPath<TFieldValues> = FieldPath<TFieldValues>>({ ...props }: ControllerProps<TFieldValues, TName>) => react_jsx_runtime.JSX.Element;
/**
 * Hook that returns field state, IDs, and error information for the current form field.
 * Must be used within a `FormField` component.
 */
declare const useFormField: () => {
    invalid: boolean;
    isDirty: boolean;
    isTouched: boolean;
    isValidating: boolean;
    error?: react_hook_form.FieldError;
    id: string;
    name: string;
    formItemId: string;
    formDescriptionId: string;
    formMessageId: string;
};
/** Container for a single form field, grouping label, control, description, and message. */
declare function FormItem({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Label for a form field that turns red on validation error. */
declare function FormLabel({ className, ...props }: React$1.ComponentProps<typeof LabelPrimitive.Root>): react_jsx_runtime.JSX.Element;
/** Slot wrapper that wires up aria attributes for the form control. */
declare function FormControl({ ...props }: React$1.ComponentProps<typeof Slot>): react_jsx_runtime.JSX.Element;
/** Helper text displayed below the form control. */
declare function FormDescription({ className, ...props }: React$1.ComponentProps<"p">): react_jsx_runtime.JSX.Element;
/** Displays the validation error message for the form field. */
declare function FormMessage({ className, ...props }: React$1.ComponentProps<"p">): react_jsx_runtime.JSX.Element | null;

/**
 * Hover-activated floating card built on Radix UI HoverCard.
 *
 * Displays a preview card when the user hovers over a trigger element.
 * Ideal for showing user profiles, link previews, or supplementary info
 * without requiring a click.
 *
 * @example
 * ```tsx
 * <HoverCard>
 *   <HoverCardTrigger asChild>
 *     <a href="/user/john" className="underline">
 *       @john
 *     </a>
 *   </HoverCardTrigger>
 *   <HoverCardContent>
 *     <div className="flex gap-4">
 *       <Avatar>
 *         <AvatarImage src="/avatars/john.png" />
 *         <AvatarFallback>JD</AvatarFallback>
 *       </Avatar>
 *       <div>
 *         <h4 className="text-sm font-semibold">John Doe</h4>
 *         <p className="text-sm text-muted-foreground">
 *           Software Engineer
 *         </p>
 *       </div>
 *     </div>
 *   </HoverCardContent>
 * </HoverCard>
 * ```
 */
declare function HoverCard({ ...props }: React$1.ComponentProps<typeof HoverCardPrimitive.Root>): react_jsx_runtime.JSX.Element;
/** Element that shows the hover card on mouse enter. Use `asChild` to merge into your own element. */
declare function HoverCardTrigger({ ...props }: React$1.ComponentProps<typeof HoverCardPrimitive.Trigger>): react_jsx_runtime.JSX.Element;
/** Floating content panel that appears on hover. */
declare function HoverCardContent({ className, align, sideOffset, ...props }: React$1.ComponentProps<typeof HoverCardPrimitive.Content>): react_jsx_runtime.JSX.Element;

type Theme = 'light' | 'dark' | 'system';
type LocaleCode = string;
/**
 * Map of locale code → display label.
 * @example { en: 'English', vi: 'Tiếng Việt', ja: '日本語' }
 */
type LocaleMap = Record<LocaleCode, string>;
/** Value shape for translatable fields: locale code → string content. */
type TranslatableValue = Record<LocaleCode, string>;
/** Locale configuration used by UIProvider and translatable fields. */
interface UILocaleConfig {
    /** Available locales. e.g. `{ en: 'English', vi: 'Tiếng Việt' }` */
    locales: LocaleMap;
    /** Locale shown by default when a translatable field is first rendered. */
    defaultLocale: LocaleCode;
    /** Locale to fall back to when the active locale has no value. */
    fallbackLocale: LocaleCode;
}
/**
 * `true`  — inherit UIProvider's locale config.
 * `object` — override per-field (merged with provider config).
 */
type TranslatableConfig = true | Partial<UILocaleConfig>;

declare const inputVariants: (props?: ({
    size?: "default" | "xs" | "sm" | "lg" | "xl" | null | undefined;
} & class_variance_authority_types.ClassProp) | undefined) => string;
type InputSize = VariantProps<typeof inputVariants>['size'];
type NativeInputProps = Omit<React$1.ComponentProps<'input'>, 'value' | 'onChange' | 'size'>;
interface StandardInputProps extends NativeInputProps {
    size?: InputSize;
    /** Translatable mode disabled (default). */
    translatable?: never;
    value?: string;
    onChange?: React$1.ChangeEventHandler<HTMLInputElement>;
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
type InputProps = StandardInputProps | TranslatableInputProps;
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
declare const Input: React$1.ForwardRefExoticComponent<(Omit<StandardInputProps, "ref"> | Omit<TranslatableInputProps, "ref">) & React$1.RefAttributes<HTMLInputElement>>;

/**
 * One-time password input component built on the `input-otp` library.
 * Renders a segmented input for entering verification codes.
 *
 * @example
 * ```tsx
 * <InputOTP maxLength={6} value={otp} onChange={setOtp}>
 *   <InputOTPGroup>
 *     <InputOTPSlot index={0} />
 *     <InputOTPSlot index={1} />
 *     <InputOTPSlot index={2} />
 *   </InputOTPGroup>
 *   <InputOTPSeparator />
 *   <InputOTPGroup>
 *     <InputOTPSlot index={3} />
 *     <InputOTPSlot index={4} />
 *     <InputOTPSlot index={5} />
 *   </InputOTPGroup>
 * </InputOTP>
 * ```
 */
declare function InputOTP({ className, containerClassName, ...props }: React$1.ComponentProps<typeof OTPInput> & {
    containerClassName?: string;
}): react_jsx_runtime.JSX.Element;
/** Groups adjacent OTP slots together visually. */
declare function InputOTPGroup({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Individual character slot within an OTP group. */
declare function InputOTPSlot({ index, className, ...props }: React$1.ComponentProps<"div"> & {
    index: number;
}): react_jsx_runtime.JSX.Element;
/** Visual separator (dash) between OTP groups. */
declare function InputOTPSeparator({ ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;

type LabelProps = React$1.ComponentPropsWithoutRef<typeof LabelPrimitive.Root>;
/**
 * Accessible label for form controls, automatically associated via `htmlFor`.
 *
 * @example
 * ```tsx
 * // With an input
 * <Label htmlFor="email">Email address</Label>
 * <Input id="email" type="email" />
 *
 * // Disabled state (responds to group/peer disabled)
 * <Label htmlFor="name">Name</Label>
 * ```
 */
declare const Label: React$1.ForwardRefExoticComponent<Omit<LabelPrimitive.LabelProps & React$1.RefAttributes<HTMLLabelElement>, "ref"> & React$1.RefAttributes<HTMLLabelElement>>;

/**
 * Horizontal menu bar component built on Radix UI Menubar.
 *
 * Provides a desktop-style menu bar with multiple dropdown menus.
 * Each menu is defined by a `MenubarMenu` containing a `MenubarTrigger`
 * and `MenubarContent` with items.
 *
 * **When to use:** macOS / desktop-app style menu bar where File / Edit /
 * View / Help live as siblings at the top of the app chrome. Rare in modern
 * web apps. For action menus on individual UI elements use `<DropdownMenu>`.
 * For website / app-router navigation use `<NavigationMenu>`. Pick this only
 * when the product mimics a native desktop app (rich text editors, IDEs,
 * canvas tools).
 *
 * @example
 * ```tsx
 * <Menubar>
 *   <MenubarMenu>
 *     <MenubarTrigger>File</MenubarTrigger>
 *     <MenubarContent>
 *       <MenubarItem>
 *         New Tab
 *         <MenubarShortcut>Ctrl+T</MenubarShortcut>
 *       </MenubarItem>
 *       <MenubarItem>New Window</MenubarItem>
 *       <MenubarSeparator />
 *       <MenubarSub>
 *         <MenubarSubTrigger>Share</MenubarSubTrigger>
 *         <MenubarSubContent>
 *           <MenubarItem>Email Link</MenubarItem>
 *           <MenubarItem>Messages</MenubarItem>
 *         </MenubarSubContent>
 *       </MenubarSub>
 *     </MenubarContent>
 *   </MenubarMenu>
 *   <MenubarMenu>
 *     <MenubarTrigger>Edit</MenubarTrigger>
 *     <MenubarContent>
 *       <MenubarItem>Undo</MenubarItem>
 *       <MenubarItem>Redo</MenubarItem>
 *     </MenubarContent>
 *   </MenubarMenu>
 * </Menubar>
 * ```
 */
declare function Menubar({ className, ...props }: React$1.ComponentProps<typeof MenubarPrimitive.Root>): react_jsx_runtime.JSX.Element;
/** Wraps a single menu within the menu bar, containing a trigger and content. */
declare function MenubarMenu({ ...props }: React$1.ComponentProps<typeof MenubarPrimitive.Menu>): react_jsx_runtime.JSX.Element;
/** Groups related menubar items together for accessibility. */
declare function MenubarGroup({ ...props }: React$1.ComponentProps<typeof MenubarPrimitive.Group>): react_jsx_runtime.JSX.Element;
/** Portal that renders menubar content outside the DOM hierarchy. */
declare function MenubarPortal({ ...props }: React$1.ComponentProps<typeof MenubarPrimitive.Portal>): react_jsx_runtime.JSX.Element;
/** Container for radio menubar items where only one can be selected at a time. */
declare function MenubarRadioGroup({ ...props }: React$1.ComponentProps<typeof MenubarPrimitive.RadioGroup>): react_jsx_runtime.JSX.Element;
/** Button label in the menu bar that opens its associated dropdown content on click. */
declare function MenubarTrigger({ className, ...props }: React$1.ComponentProps<typeof MenubarPrimitive.Trigger>): react_jsx_runtime.JSX.Element;
/** Floating container for menubar items, positioned below the trigger. */
declare function MenubarContent({ className, align, alignOffset, sideOffset, ...props }: React$1.ComponentProps<typeof MenubarPrimitive.Content>): react_jsx_runtime.JSX.Element;
/** Actionable menu item. Set `variant="destructive"` for dangerous actions, `inset` for left-padding alignment. */
declare function MenubarItem({ className, inset, variant, ...props }: React$1.ComponentProps<typeof MenubarPrimitive.Item> & {
    inset?: boolean;
    variant?: "default" | "destructive";
}): react_jsx_runtime.JSX.Element;
/** Menu item with a checkbox indicator for toggling options. */
declare function MenubarCheckboxItem({ className, children, checked, ...props }: React$1.ComponentProps<typeof MenubarPrimitive.CheckboxItem>): react_jsx_runtime.JSX.Element;
/** Menu item with a radio indicator for single-selection groups. */
declare function MenubarRadioItem({ className, children, ...props }: React$1.ComponentProps<typeof MenubarPrimitive.RadioItem>): react_jsx_runtime.JSX.Element;
/** Non-interactive label used to title a group of menu items. */
declare function MenubarLabel({ className, inset, ...props }: React$1.ComponentProps<typeof MenubarPrimitive.Label> & {
    inset?: boolean;
}): react_jsx_runtime.JSX.Element;
/** Visual divider between groups of menu items. */
declare function MenubarSeparator({ className, ...props }: React$1.ComponentProps<typeof MenubarPrimitive.Separator>): react_jsx_runtime.JSX.Element;
/** Keyboard shortcut hint displayed at the end of a menu item. */
declare function MenubarShortcut({ className, ...props }: React$1.ComponentProps<"span">): react_jsx_runtime.JSX.Element;
/** Container for a nested sub-menu within the menubar. */
declare function MenubarSub({ ...props }: React$1.ComponentProps<typeof MenubarPrimitive.Sub>): react_jsx_runtime.JSX.Element;
/** Menu item that opens a sub-menu on hover. Displays a chevron indicator. */
declare function MenubarSubTrigger({ className, inset, children, ...props }: React$1.ComponentProps<typeof MenubarPrimitive.SubTrigger> & {
    inset?: boolean;
}): react_jsx_runtime.JSX.Element;
/** Floating container for sub-menu items. */
declare function MenubarSubContent({ className, ...props }: React$1.ComponentProps<typeof MenubarPrimitive.SubContent>): react_jsx_runtime.JSX.Element;

/**
 * Accessible navigation menu built on Radix UI NavigationMenu.
 *
 * Provides a horizontal navigation bar with dropdown content panels,
 * suitable for site-wide navigation with rich sub-menus. Set `viewport={false}`
 * to render content inline instead of in a shared viewport container.
 *
 * **When to use:** site or app primary navigation header with rich submenu
 * content (mega menus, marketing site nav, dashboard top-nav). For action
 * menus on individual UI elements use `<DropdownMenu>`. For desktop-app
 * style menu bars (File / Edit / View) use `<Menubar>`. For sidebar
 * navigation use `<Sidebar>`.
 *
 * @example
 * ```tsx
 * <NavigationMenu>
 *   <NavigationMenuList>
 *     <NavigationMenuItem>
 *       <NavigationMenuTrigger>Getting Started</NavigationMenuTrigger>
 *       <NavigationMenuContent>
 *         <ul className="grid gap-3 p-4 w-[400px]">
 *           <li>
 *             <NavigationMenuLink href="/docs">
 *               <div className="font-medium">Introduction</div>
 *               <p className="text-muted-foreground">
 *                 Learn the basics of the component library.
 *               </p>
 *             </NavigationMenuLink>
 *           </li>
 *         </ul>
 *       </NavigationMenuContent>
 *     </NavigationMenuItem>
 *     <NavigationMenuItem>
 *       <NavigationMenuLink
 *         className={navigationMenuTriggerStyle()}
 *         href="/docs"
 *       >
 *         Documentation
 *       </NavigationMenuLink>
 *     </NavigationMenuItem>
 *   </NavigationMenuList>
 * </NavigationMenu>
 * ```
 */
declare function NavigationMenu({ className, children, viewport, ...props }: React$1.ComponentProps<typeof NavigationMenuPrimitive.Root> & {
    viewport?: boolean;
}): react_jsx_runtime.JSX.Element;
/** Horizontal list container for navigation menu items. */
declare function NavigationMenuList({ className, ...props }: React$1.ComponentProps<typeof NavigationMenuPrimitive.List>): react_jsx_runtime.JSX.Element;
/** Individual navigation menu item that can contain a trigger and content or a direct link. */
declare function NavigationMenuItem({ className, ...props }: React$1.ComponentProps<typeof NavigationMenuPrimitive.Item>): react_jsx_runtime.JSX.Element;
/** Shared style variant for navigation menu trigger buttons and standalone links. Apply with `className={navigationMenuTriggerStyle()}`. */
declare const navigationMenuTriggerStyle: (props?: class_variance_authority_types.ClassProp | undefined) => string;
/** Button that opens the associated NavigationMenuContent dropdown. Displays a chevron indicator. */
declare function NavigationMenuTrigger({ className, children, ...props }: React$1.ComponentProps<typeof NavigationMenuPrimitive.Trigger>): react_jsx_runtime.JSX.Element;
/** Dropdown content panel revealed when a NavigationMenuTrigger is activated. */
declare function NavigationMenuContent({ className, ...props }: React$1.ComponentProps<typeof NavigationMenuPrimitive.Content>): react_jsx_runtime.JSX.Element;
/** Shared viewport container that displays the active NavigationMenuContent with animated transitions. */
declare function NavigationMenuViewport({ className, ...props }: React$1.ComponentProps<typeof NavigationMenuPrimitive.Viewport>): react_jsx_runtime.JSX.Element;
/** Accessible link element within navigation menu content. Supports `data-[active=true]` styling. */
declare function NavigationMenuLink({ className, ...props }: React$1.ComponentProps<typeof NavigationMenuPrimitive.Link>): react_jsx_runtime.JSX.Element;
/** Animated arrow indicator that tracks the active menu trigger position. */
declare function NavigationMenuIndicator({ className, ...props }: React$1.ComponentProps<typeof NavigationMenuPrimitive.Indicator>): react_jsx_runtime.JSX.Element;

interface PageContainerProps {
    /**
     * Page title
     */
    title?: string;
    /**
     * Subtitle or description below title
     */
    subtitle?: string;
    /**
     * Extra content (buttons, actions) displayed on the right side of header
     */
    extra?: ReactNode;
    /**
     * Main page content
     */
    children: ReactNode;
    /**
     * Footer content displayed at the bottom
     */
    footer?: ReactNode;
    /**
     * Sidebar content displayed on left or right
     */
    sidebar?: ReactNode;
    /**
     * Sidebar position
     * @default 'right'
     */
    sidebarPosition?: 'left' | 'right';
    /**
     * Sidebar width
     * @default 'w-80'
     */
    sidebarWidth?: string;
    /**
     * Layout variant
     * - 'standard': Default padded layout with header
     * - 'full': Full width, no padding (for boards, gantt)
     * - 'split': Layout with sidebar inside page
     * @default 'standard'
     */
    variant?: 'standard' | 'full' | 'split';
    /**
     * Custom container className
     */
    className?: string;
    /**
     * Custom content className
     */
    contentClassName?: string;
    /**
     * Show separator below header
     * @default true for standard variant
     */
    showHeaderSeparator?: boolean;
}
/**
 * PageContainer - Flexible page layout component
 *
 * @example
 * // Standard layout with title and actions
 * <PageContainer
 *   title="Dashboard"
 *   subtitle="Overview of all projects"
 *   extra={<Button>Create</Button>}
 * >
 *   <div>Content here</div>
 * </PageContainer>
 *
 * @example
 * // Split layout with right sidebar
 * <PageContainer
 *   title="Task Detail"
 *   variant="split"
 *   sidebar={<CommentSection />}
 *   sidebarPosition="right"
 * >
 *   <div>Main content</div>
 * </PageContainer>
 *
 * @example
 * // Full width layout (no padding)
 * <PageContainer variant="full">
 *   <KanbanBoard />
 * </PageContainer>
 */
declare function PageContainer({ title, subtitle, extra, children, footer, sidebar, sidebarPosition, sidebarWidth, variant, className, contentClassName, showHeaderSeparator, }: PageContainerProps): react_jsx_runtime.JSX.Element;
declare function StandardPageContainer(props: Omit<PageContainerProps, 'variant'>): react_jsx_runtime.JSX.Element;
declare function SplitPageContainer(props: Omit<PageContainerProps, 'variant'>): react_jsx_runtime.JSX.Element;
declare function FullWidthPageContainer(props: Omit<PageContainerProps, 'variant' | 'title' | 'subtitle' | 'extra'>): react_jsx_runtime.JSX.Element;

/**
 * Page navigation component with numbered links, previous/next buttons, and ellipsis indicators.
 *
 * Renders as a `<nav>` with `aria-label="pagination"` for accessibility.
 * Compose with `PaginationContent`, `PaginationItem`, `PaginationLink`,
 * `PaginationPrevious`, `PaginationNext`, and `PaginationEllipsis`.
 *
 * @example
 * ```tsx
 * <Pagination>
 *   <PaginationContent>
 *     <PaginationItem>
 *       <PaginationPrevious href="#" />
 *     </PaginationItem>
 *     <PaginationItem>
 *       <PaginationLink href="#" isActive>1</PaginationLink>
 *     </PaginationItem>
 *     <PaginationItem>
 *       <PaginationLink href="#">2</PaginationLink>
 *     </PaginationItem>
 *     <PaginationItem>
 *       <PaginationEllipsis />
 *     </PaginationItem>
 *     <PaginationItem>
 *       <PaginationLink href="#">10</PaginationLink>
 *     </PaginationItem>
 *     <PaginationItem>
 *       <PaginationNext href="#" />
 *     </PaginationItem>
 *   </PaginationContent>
 * </Pagination>
 * ```
 */
declare function Pagination({ className, ...props }: React$1.ComponentProps<"nav">): react_jsx_runtime.JSX.Element;
/** Flex container for pagination items. Renders as a `<ul>`. */
declare function PaginationContent({ className, ...props }: React$1.ComponentProps<"ul">): react_jsx_runtime.JSX.Element;
/** List item wrapper for a single pagination element. */
declare function PaginationItem({ ...props }: React$1.ComponentProps<"li">): react_jsx_runtime.JSX.Element;
type PaginationLinkProps = {
    /** When true, renders the link with an `outline` variant and `aria-current="page"`. */
    isActive?: boolean;
} & Pick<React$1.ComponentProps<typeof Button>, "size"> & React$1.ComponentProps<"a">;
/** Styled pagination link using button variants. Supports `isActive` for the current page. */
declare function PaginationLink({ className, isActive, size, ...props }: PaginationLinkProps): react_jsx_runtime.JSX.Element;
/** "Previous" pagination link with a left chevron icon. */
declare function PaginationPrevious({ className, ...props }: React$1.ComponentProps<typeof PaginationLink>): react_jsx_runtime.JSX.Element;
/** "Next" pagination link with a right chevron icon. */
declare function PaginationNext({ className, ...props }: React$1.ComponentProps<typeof PaginationLink>): react_jsx_runtime.JSX.Element;
/** Ellipsis indicator for omitted page numbers. Renders a `MoreHorizontal` icon with screen-reader text. */
declare function PaginationEllipsis({ className, ...props }: React$1.ComponentProps<"span">): react_jsx_runtime.JSX.Element;

interface PasswordInputProps extends Omit<React$1.ComponentProps<"input">, "type" | "size">, VariantProps<typeof inputVariants> {
    /**
     * Single-string validation error. When set, the inner input gets
     * `aria-invalid` and a red error message is rendered below.
     */
    error?: string;
}
/**
 * Password input with a built-in show/hide toggle button.
 *
 * Extends native `<input>` (minus `type` which is managed internally).
 * Shares the same size variants as `Input`.
 *
 * @example
 * ```tsx
 * // Default size
 * <PasswordInput placeholder="Enter password" />
 *
 * // Sizes: xs (24px) | sm (28px) | default (32px) | lg (36px) | xl (44px)
 * <PasswordInput size="xl" placeholder="Password" />
 *
 * // Controlled
 * <PasswordInput
 *   value={password}
 *   onChange={(e) => setPassword(e.target.value)}
 *   placeholder="Password"
 * />
 * ```
 */
declare const PasswordInput: React$1.ForwardRefExoticComponent<Omit<PasswordInputProps, "ref"> & React$1.RefAttributes<HTMLInputElement>>;

/**
 * Floating popover component built on Radix UI Popover.
 *
 * Displays rich content in a floating panel anchored to a trigger element.
 * Supports controlled (`open`/`onOpenChange`) and uncontrolled usage.
 * Content is portaled and positioned automatically.
 *
 * @example
 * ```tsx
 * <Popover>
 *   <PopoverTrigger asChild>
 *     <Button variant="outline">Open Popover</Button>
 *   </PopoverTrigger>
 *   <PopoverContent>
 *     <div className="space-y-2">
 *       <h4 className="font-medium text-sm">Dimensions</h4>
 *       <p className="text-sm text-muted-foreground">
 *         Set the dimensions for the layer.
 *       </p>
 *     </div>
 *   </PopoverContent>
 * </Popover>
 * ```
 */
declare function Popover({ ...props }: React$1.ComponentProps<typeof PopoverPrimitive.Root>): react_jsx_runtime.JSX.Element;
/** Element that toggles the popover when clicked. Use `asChild` to merge into your own button. */
declare function PopoverTrigger({ ...props }: React$1.ComponentProps<typeof PopoverPrimitive.Trigger>): react_jsx_runtime.JSX.Element;
/** Floating content panel positioned relative to the trigger. */
declare function PopoverContent({ className, align, sideOffset, ...props }: React$1.ComponentProps<typeof PopoverPrimitive.Content>): react_jsx_runtime.JSX.Element;
/** Custom anchor element for positioning the popover content relative to a different element than the trigger. */
declare function PopoverAnchor({ ...props }: React$1.ComponentProps<typeof PopoverPrimitive.Anchor>): react_jsx_runtime.JSX.Element;

type ProgressProps = React$1.ComponentProps<typeof ProgressPrimitive.Root>;
/**
 * Horizontal bar that indicates the completion progress of a task or operation.
 *
 * @example
 * ```tsx
 * // Basic usage (65% complete)
 * <Progress value={65} />
 *
 * // With custom styling
 * <Progress value={40} className="h-3" />
 * ```
 */
declare function Progress({ className, value, ...props }: ProgressProps): react_jsx_runtime.JSX.Element;

type RadioGroupProps = React$1.ComponentProps<typeof RadioGroupPrimitive.Root>;
/**
 * Container for a set of mutually exclusive radio options.
 * Use with {@link RadioGroupItem} to build single-selection groups.
 *
 * @example
 * ```tsx
 * <RadioGroup defaultValue="option-1">
 *   <div className="flex items-center gap-2">
 *     <RadioGroupItem value="option-1" id="opt1" />
 *     <Label htmlFor="opt1">Option 1</Label>
 *   </div>
 *   <div className="flex items-center gap-2">
 *     <RadioGroupItem value="option-2" id="opt2" />
 *     <Label htmlFor="opt2">Option 2</Label>
 *   </div>
 * </RadioGroup>
 * ```
 */
declare function RadioGroup({ className, ...props }: RadioGroupProps): react_jsx_runtime.JSX.Element;
type RadioGroupItemProps = React$1.ComponentProps<typeof RadioGroupPrimitive.Item>;
/**
 * Individual radio option within a {@link RadioGroup}.
 * Renders as a circular indicator that fills when selected.
 *
 * @example
 * ```tsx
 * <RadioGroupItem value="dark" id="theme-dark" />
 * <Label htmlFor="theme-dark">Dark mode</Label>
 * ```
 */
declare function RadioGroupItem({ className, ...props }: RadioGroupItemProps): react_jsx_runtime.JSX.Element;

interface RatingProps {
    /** Current rating value (e.g., `3` or `3.5` with half stars). */
    value?: number;
    /** Callback fired when the user clicks a star. Receives the new rating number. */
    onChange?: (value: number) => void;
    /** Maximum number of stars. Defaults to `5`. */
    max?: number;
    /** Size of the star icons. Defaults to `"md"`. */
    size?: "sm" | "md" | "lg";
    /** Whether the rating is display-only (non-interactive). Defaults to `false`. */
    readonly?: boolean;
    /** Whether half-star ratings are enabled. Defaults to `false`. */
    allowHalf?: boolean;
    /** Additional CSS class for the outer container. */
    className?: string;
}
/**
 * Star rating component with hover preview and optional half-star support.
 * Shows filled/empty/half star icons and displays the numeric value beside the stars.
 *
 * @example
 * ```tsx
 * const [rating, setRating] = useState(0);
 *
 * <Rating value={rating} onChange={setRating} max={5} />
 *
 * // Read-only display with half stars:
 * <Rating value={3.5} readonly allowHalf />
 * ```
 */
declare function Rating({ value, onChange, max, size, readonly, allowHalf, className, }: RatingProps): react_jsx_runtime.JSX.Element;

/**
 * Resizable panel layout built on `react-resizable-panels`.
 *
 * Groups multiple `ResizablePanel` components separated by `ResizableHandle` drag handles.
 * Supports horizontal (default) and vertical layouts via the `direction` prop.
 *
 * @example
 * ```tsx
 * <ResizablePanelGroup direction="horizontal">
 *   <ResizablePanel defaultSize={50}>
 *     <div className="p-4">Left panel</div>
 *   </ResizablePanel>
 *   <ResizableHandle withHandle />
 *   <ResizablePanel defaultSize={50}>
 *     <div className="p-4">Right panel</div>
 *   </ResizablePanel>
 * </ResizablePanelGroup>
 * ```
 */
declare function ResizablePanelGroup({ className, ...props }: React$1.ComponentProps<typeof ResizablePrimitive.PanelGroup>): react_jsx_runtime.JSX.Element;
/** Individual resizable panel. Use `defaultSize` (percentage) to set the initial width/height. */
declare function ResizablePanel({ ...props }: React$1.ComponentProps<typeof ResizablePrimitive.Panel>): react_jsx_runtime.JSX.Element;
/**
 * Draggable handle between resizable panels.
 *
 * @param withHandle - When true, renders a visible grip icon on the handle for better discoverability.
 */
declare function ResizableHandle({ withHandle, className, ...props }: React$1.ComponentProps<typeof ResizablePrimitive.PanelResizeHandle> & {
    withHandle?: boolean;
}): react_jsx_runtime.JSX.Element;

interface RichTextEditorProps {
    value?: string;
    onChange?: (html: string) => void;
    editable?: boolean;
    className?: string;
}
declare function RichTextEditor({ value, onChange, editable, className, }: RichTextEditorProps): react_jsx_runtime.JSX.Element;

/**
 * Custom scrollable container with styled scrollbar built on Radix ScrollArea.
 *
 * Replaces native browser scrollbars with a thin, themed scrollbar.
 * Includes a vertical `ScrollBar` by default. Add a horizontal `ScrollBar`
 * as a child if needed.
 *
 * @example
 * ```tsx
 * <ScrollArea className="h-72 w-48 rounded-md border">
 *   <div className="p-4">
 *     {items.map((item) => (
 *       <div key={item} className="py-2 text-sm">
 *         {item}
 *       </div>
 *     ))}
 *   </div>
 * </ScrollArea>
 * ```
 */
declare function ScrollArea({ className, children, ...props }: React$1.ComponentProps<typeof ScrollAreaPrimitive.Root>): react_jsx_runtime.JSX.Element;
/** Styled scrollbar track and thumb. Set `orientation` to `"horizontal"` or `"vertical"` (default). */
declare function ScrollBar({ className, orientation, ...props }: React$1.ComponentProps<typeof ScrollAreaPrimitive.ScrollAreaScrollbar>): react_jsx_runtime.JSX.Element;

/**
 * Select dropdown component built on Radix UI Select.
 * Provides a styled, accessible dropdown for selecting a single value from a list.
 *
 * @example
 * ```tsx
 * <Select value={value} onValueChange={setValue}>
 *   <SelectTrigger>
 *     <SelectValue placeholder="Choose..." />
 *   </SelectTrigger>
 *   <SelectContent>
 *     <SelectGroup>
 *       <SelectLabel>Fruits</SelectLabel>
 *       <SelectItem value="apple">Apple</SelectItem>
 *       <SelectItem value="banana">Banana</SelectItem>
 *     </SelectGroup>
 *     <SelectSeparator />
 *     <SelectGroup>
 *       <SelectLabel>Vegetables</SelectLabel>
 *       <SelectItem value="carrot">Carrot</SelectItem>
 *     </SelectGroup>
 *   </SelectContent>
 * </Select>
 * ```
 */
declare function Select({ ...props }: React$1.ComponentProps<typeof SelectPrimitive.Root>): react_jsx_runtime.JSX.Element;
/** Groups related select items under an optional label. */
declare function SelectGroup({ ...props }: React$1.ComponentProps<typeof SelectPrimitive.Group>): react_jsx_runtime.JSX.Element;
/** Displays the currently selected value or a placeholder. */
declare function SelectValue({ ...props }: React$1.ComponentProps<typeof SelectPrimitive.Value>): react_jsx_runtime.JSX.Element;
/** Button that toggles the select dropdown open/closed. */
declare const SelectTrigger: React$1.ForwardRefExoticComponent<Omit<SelectPrimitive.SelectTriggerProps & React$1.RefAttributes<HTMLButtonElement>, "ref"> & {
    size?: "sm" | "default";
} & React$1.RefAttributes<HTMLButtonElement>>;
/** Dropdown content container rendered in a portal. */
declare function SelectContent({ className, children, position, ...props }: React$1.ComponentProps<typeof SelectPrimitive.Content>): react_jsx_runtime.JSX.Element;
/** Non-interactive label rendered inside a SelectGroup. */
declare function SelectLabel({ className, ...props }: React$1.ComponentProps<typeof SelectPrimitive.Label>): react_jsx_runtime.JSX.Element;
/** A selectable option within the dropdown. */
declare function SelectItem({ className, children, ...props }: React$1.ComponentProps<typeof SelectPrimitive.Item>): react_jsx_runtime.JSX.Element;
/** Visual separator between select groups or items. */
declare function SelectSeparator({ className, ...props }: React$1.ComponentProps<typeof SelectPrimitive.Separator>): react_jsx_runtime.JSX.Element;
/** Scroll-up indicator shown when the list is scrollable. */
declare function SelectScrollUpButton({ className, ...props }: React$1.ComponentProps<typeof SelectPrimitive.ScrollUpButton>): react_jsx_runtime.JSX.Element;
/** Scroll-down indicator shown when the list is scrollable. */
declare function SelectScrollDownButton({ className, ...props }: React$1.ComponentProps<typeof SelectPrimitive.ScrollDownButton>): react_jsx_runtime.JSX.Element;

type SeparatorProps = React$1.ComponentProps<typeof SeparatorPrimitive.Root>;
/**
 * Visual divider between content sections, rendered as a horizontal or vertical line.
 *
 * @example
 * ```tsx
 * // Horizontal (default)
 * <Separator />
 *
 * // Vertical divider in a flex row
 * <div className="flex items-center gap-4">
 *   <span>Left</span>
 *   <Separator orientation="vertical" className="h-4" />
 *   <span>Right</span>
 * </div>
 * ```
 */
declare function Separator({ className, orientation, decorative, ...props }: SeparatorProps): react_jsx_runtime.JSX.Element;

/**
 * Slide-out panel component built on Radix UI Dialog.
 *
 * A sheet slides in from the edge of the screen, ideal for navigation,
 * filters, or supplementary content. Supports `top`, `right`, `bottom`,
 * and `left` sides via the `side` prop on `SheetContent`.
 *
 * **When to use:** desktop-first side-rail content — secondary navigation,
 * filter panels, settings drawers, item details. No swipe affordance, click
 * overlay or Escape dismisses. For mobile-first touch UX with swipe use
 * `<Drawer>`. For centered modal dialogs use `<Dialog>`. For destructive
 * confirmations use `<AlertDialog>`.
 *
 * @example
 * ```tsx
 * <Sheet open={open} onOpenChange={setOpen}>
 *   <SheetTrigger asChild>
 *     <Button variant="outline">Open Sheet</Button>
 *   </SheetTrigger>
 *   <SheetContent side="right">
 *     <SheetHeader>
 *       <SheetTitle>Settings</SheetTitle>
 *       <SheetDescription>
 *         Adjust your preferences below.
 *       </SheetDescription>
 *     </SheetHeader>
 *     <div className="p-4">Content here</div>
 *     <SheetFooter>
 *       <Button onClick={() => setOpen(false)}>Done</Button>
 *     </SheetFooter>
 *   </SheetContent>
 * </Sheet>
 * ```
 */
declare function Sheet({ ...props }: React$1.ComponentProps<typeof DialogPrimitive.Root>): react_jsx_runtime.JSX.Element;
/** Element that opens the sheet when clicked. Use `asChild` to merge into your own button. */
declare function SheetTrigger({ ...props }: React$1.ComponentProps<typeof DialogPrimitive.Trigger>): react_jsx_runtime.JSX.Element;
/** Button that closes the sheet. Use `asChild` to merge into your own button. */
declare function SheetClose({ ...props }: React$1.ComponentProps<typeof DialogPrimitive.Close>): react_jsx_runtime.JSX.Element;
/** Sliding content panel. Set `side` to control which edge it slides from (default: `"right"`). */
declare function SheetContent({ className, children, side, ...props }: React$1.ComponentProps<typeof DialogPrimitive.Content> & {
    side?: "top" | "right" | "bottom" | "left";
}): react_jsx_runtime.JSX.Element;
/** Container for SheetTitle and SheetDescription at the top of the sheet. */
declare function SheetHeader({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Container for action buttons at the bottom of the sheet. Pushed to the bottom via `mt-auto`. */
declare function SheetFooter({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
/** Accessible title rendered inside SheetHeader. */
declare function SheetTitle({ className, ...props }: React$1.ComponentProps<typeof DialogPrimitive.Title>): react_jsx_runtime.JSX.Element;
/** Accessible description rendered inside SheetHeader below the title. */
declare function SheetDescription({ className, ...props }: React$1.ComponentProps<typeof DialogPrimitive.Description>): react_jsx_runtime.JSX.Element;

/** Provider that configures shared tooltip settings like delay duration. */
declare function TooltipProvider({ delayDuration, ...props }: React$1.ComponentProps<typeof TooltipPrimitive.Provider>): react_jsx_runtime.JSX.Element;
/**
 * Tooltip component built on Radix UI Tooltip.
 *
 * Displays a short informational label when the user hovers over or focuses
 * an element. Includes a built-in `TooltipProvider` with zero delay.
 * Renders with an arrow pointer for visual anchoring.
 *
 * @example
 * ```tsx
 * <Tooltip>
 *   <TooltipTrigger asChild>
 *     <Button variant="ghost" size="icon">
 *       <InfoIcon className="size-4" />
 *     </Button>
 *   </TooltipTrigger>
 *   <TooltipContent>
 *     <p>This is a helpful tooltip</p>
 *   </TooltipContent>
 * </Tooltip>
 * ```
 */
declare function Tooltip({ ...props }: React$1.ComponentProps<typeof TooltipPrimitive.Root>): react_jsx_runtime.JSX.Element;
/** Element that shows the tooltip on hover/focus. Use `asChild` to merge into your own element. */
declare function TooltipTrigger({ ...props }: React$1.ComponentProps<typeof TooltipPrimitive.Trigger>): react_jsx_runtime.JSX.Element;
/** Floating label that appears near the trigger. Includes an arrow indicator. */
declare function TooltipContent({ className, sideOffset, children, ...props }: React$1.ComponentProps<typeof TooltipPrimitive.Content>): react_jsx_runtime.JSX.Element;

type SidebarContextProps = {
    state: "expanded" | "collapsed";
    open: boolean;
    setOpen: (open: boolean) => void;
    openMobile: boolean;
    setOpenMobile: (open: boolean) => void;
    isMobile: boolean;
    toggleSidebar: () => void;
};
declare function useSidebar(): SidebarContextProps;
declare function SidebarProvider({ defaultOpen, open: openProp, onOpenChange: setOpenProp, className, style, children, ...props }: React$1.ComponentProps<"div"> & {
    defaultOpen?: boolean;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}): react_jsx_runtime.JSX.Element;
declare function Sidebar({ side, variant, collapsible, className, children, ...props }: React$1.ComponentProps<"div"> & {
    side?: "left" | "right";
    variant?: "sidebar" | "floating" | "inset";
    collapsible?: "offcanvas" | "icon" | "none";
}): react_jsx_runtime.JSX.Element;
declare function SidebarTrigger({ className, onClick, ...props }: React$1.ComponentProps<typeof Button>): react_jsx_runtime.JSX.Element;
declare function SidebarRail({ className, ...props }: React$1.ComponentProps<"button">): react_jsx_runtime.JSX.Element;
declare function SidebarInset({ className, ...props }: React$1.ComponentProps<"main">): react_jsx_runtime.JSX.Element;
declare function SidebarInput({ className, ...props }: React$1.ComponentProps<typeof Input>): react_jsx_runtime.JSX.Element;
declare function SidebarHeader({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
declare function SidebarFooter({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
declare function SidebarSeparator({ className, ...props }: React$1.ComponentProps<typeof Separator>): react_jsx_runtime.JSX.Element;
declare function SidebarContent({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
declare function SidebarGroup({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
declare function SidebarGroupLabel({ className, asChild, ...props }: React$1.ComponentProps<"div"> & {
    asChild?: boolean;
}): react_jsx_runtime.JSX.Element;
declare function SidebarGroupAction({ className, asChild, ...props }: React$1.ComponentProps<"button"> & {
    asChild?: boolean;
}): react_jsx_runtime.JSX.Element;
declare function SidebarGroupContent({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
declare function SidebarMenu({ className, ...props }: React$1.ComponentProps<"ul">): react_jsx_runtime.JSX.Element;
declare function SidebarMenuItem({ className, ...props }: React$1.ComponentProps<"li">): react_jsx_runtime.JSX.Element;
declare const sidebarMenuButtonVariants: (props?: ({
    variant?: "default" | "outline" | null | undefined;
    size?: "default" | "sm" | "lg" | null | undefined;
} & class_variance_authority_types.ClassProp) | undefined) => string;
declare function SidebarMenuButton({ asChild, isActive, variant, size, tooltip, className, ...props }: React$1.ComponentProps<"button"> & {
    asChild?: boolean;
    isActive?: boolean;
    tooltip?: string | React$1.ComponentProps<typeof TooltipContent>;
} & VariantProps<typeof sidebarMenuButtonVariants>): react_jsx_runtime.JSX.Element;
declare function SidebarMenuAction({ className, asChild, showOnHover, ...props }: React$1.ComponentProps<"button"> & {
    asChild?: boolean;
    showOnHover?: boolean;
}): react_jsx_runtime.JSX.Element;
declare function SidebarMenuBadge({ className, ...props }: React$1.ComponentProps<"div">): react_jsx_runtime.JSX.Element;
declare function SidebarMenuSkeleton({ className, showIcon, ...props }: React$1.ComponentProps<"div"> & {
    showIcon?: boolean;
}): react_jsx_runtime.JSX.Element;
declare function SidebarMenuSub({ className, ...props }: React$1.ComponentProps<"ul">): react_jsx_runtime.JSX.Element;
declare function SidebarMenuSubItem({ className, ...props }: React$1.ComponentProps<"li">): react_jsx_runtime.JSX.Element;
declare function SidebarMenuSubButton({ asChild, size, isActive, className, ...props }: React$1.ComponentProps<"a"> & {
    asChild?: boolean;
    size?: "sm" | "md";
    isActive?: boolean;
}): react_jsx_runtime.JSX.Element;

type SkeletonProps = React.ComponentProps<"div">;
/**
 * Placeholder loading indicator with a pulse animation, used to represent
 * content that is being fetched or rendered.
 *
 * @example
 * ```tsx
 * // Text placeholder
 * <Skeleton className="h-4 w-48" />
 *
 * // Circular avatar placeholder
 * <Skeleton className="size-10 rounded-full" />
 *
 * // Card skeleton
 * <div className="space-y-2">
 *   <Skeleton className="h-4 w-full" />
 *   <Skeleton className="h-4 w-3/4" />
 * </div>
 * ```
 */
declare function Skeleton({ className, ...props }: SkeletonProps): react_jsx_runtime.JSX.Element;

type SliderProps = React$1.ComponentProps<typeof SliderPrimitive.Root>;
/**
 * Draggable range input for selecting a numeric value or range within a given min/max.
 * Supports single-thumb and multi-thumb modes, as well as vertical orientation.
 *
 * @example
 * ```tsx
 * // Single value
 * <Slider defaultValue={[50]} max={100} step={1} />
 *
 * // Range (two thumbs)
 * <Slider defaultValue={[25, 75]} max={100} step={5} />
 *
 * // Controlled
 * <Slider value={[volume]} onValueChange={([v]) => setVolume(v)} />
 * ```
 */
declare function Slider({ className, defaultValue, value, min, max, ...props }: SliderProps): react_jsx_runtime.JSX.Element;

interface SlugInputLabels {
    /** Label text above the slug input field. */
    slug: string;
    /** Helper text below the slug input. */
    autoGenerated: string;
    /** Placeholder shown inside the slug input. */
    placeholder: string;
}
interface SlugInputProps {
    /** Source title string from which the slug is auto-generated. */
    title: string;
    /** Current slug value. */
    slug: string;
    /** Callback fired when the slug changes (auto-generated or manually edited). */
    onSlugChange: (slug: string) => void;
    /** Whether auto-generation from title is disabled and slug is manually editable only. */
    disabled?: boolean;
    /** Override default label strings for localization. */
    labels?: Partial<SlugInputLabels>;
    /**
     * Single-string validation error. When set, the inner input gets
     * `aria-invalid` and a red error message is rendered below.
     */
    error?: string;
}
/**
 * Generates a URL-friendly slug from a text string.
 * Handles Vietnamese diacritics, Japanese punctuation, and other special characters.
 */
declare function generateSlug(text: string): string;
declare function SlugInput({ title, slug, onSlugChange, disabled, labels: labelOverrides, error, }: SlugInputProps): react_jsx_runtime.JSX.Element;

/**
 * Toast notification container powered by the Sonner library.
 * Renders toast messages at a configurable position on screen.
 * Place this once at the root of your app, then use `toast()` to trigger notifications.
 *
 * @example
 * ```tsx
 * // In your root layout:
 * <Toaster />
 *
 * // Anywhere in your app — import toast from this package, NOT "sonner"
 * // directly, otherwise module duplication can dispatch your toast to a
 * // different queue than the Toaster reads:
 * import { toast } from "@godxjp/ui";
 * toast.success("Changes saved");
 * toast.error("Something went wrong");
 * ```
 */
declare const Toaster: ({ ...props }: ToasterProps) => react_jsx_runtime.JSX.Element;

declare function Spinner({ className, ...props }: React.ComponentProps<"svg">): react_jsx_runtime.JSX.Element;

interface StatusBadgeProps {
    status: string;
    className?: string;
}
declare function StatusBadge({ status, className }: StatusBadgeProps): react_jsx_runtime.JSX.Element;

type SwitchProps = React$1.ComponentProps<typeof SwitchPrimitives.Root>;
/**
 * Toggle switch for boolean on/off states, styled as a sliding pill.
 *
 * @example
 * ```tsx
 * // Uncontrolled
 * <Switch defaultChecked />
 *
 * // Controlled
 * <Switch checked={enabled} onCheckedChange={setEnabled} />
 *
 * // With label
 * <div className="flex items-center gap-2">
 *   <Switch id="notifications" />
 *   <Label htmlFor="notifications">Enable notifications</Label>
 * </div>
 * ```
 */
declare function Switch({ className, ...props }: SwitchProps): react_jsx_runtime.JSX.Element;

/**
 * Data table component with header, body, footer, row, head, cell, and caption sub-components.
 *
 * Renders inside a horizontally scrollable container. Uses density tokens for
 * consistent header height (`h-table-head`) and cell padding.
 *
 * @example
 * ```tsx
 * <Table>
 *   <TableHeader>
 *     <TableRow>
 *       <TableHead>Name</TableHead>
 *       <TableHead>Email</TableHead>
 *       <TableHead>Role</TableHead>
 *     </TableRow>
 *   </TableHeader>
 *   <TableBody>
 *     <TableRow>
 *       <TableCell>Alice</TableCell>
 *       <TableCell>alice@example.com</TableCell>
 *       <TableCell>Admin</TableCell>
 *     </TableRow>
 *     <TableRow>
 *       <TableCell>Bob</TableCell>
 *       <TableCell>bob@example.com</TableCell>
 *       <TableCell>Member</TableCell>
 *     </TableRow>
 *   </TableBody>
 *   <TableFooter>
 *     <TableRow>
 *       <TableCell colSpan={3}>2 users total</TableCell>
 *     </TableRow>
 *   </TableFooter>
 *   <TableCaption>A list of team members.</TableCaption>
 * </Table>
 * ```
 */
declare function Table({ className, ...props }: React$1.ComponentProps<"table">): react_jsx_runtime.JSX.Element;
/** Table header container. Groups `TableRow` elements for column headings. */
declare function TableHeader({ className, ...props }: React$1.ComponentProps<"thead">): react_jsx_runtime.JSX.Element;
/** Table body container. Groups `TableRow` elements for data rows. */
declare function TableBody({ className, ...props }: React$1.ComponentProps<"tbody">): react_jsx_runtime.JSX.Element;
/** Table footer container. Renders with a muted background and top border. */
declare function TableFooter({ className, ...props }: React$1.ComponentProps<"tfoot">): react_jsx_runtime.JSX.Element;
/** Table row with hover highlight and selected state support via `data-state="selected"`. */
declare function TableRow({ className, ...props }: React$1.ComponentProps<"tr">): react_jsx_runtime.JSX.Element;
/** Table head cell. Renders as a `<th>` with density-based height (`h-table-head`). */
declare function TableHead({ className, ...props }: React$1.ComponentProps<"th">): react_jsx_runtime.JSX.Element;
/** Table data cell. Renders as a `<td>` with consistent padding and alignment. */
declare function TableCell({ className, ...props }: React$1.ComponentProps<"td">): react_jsx_runtime.JSX.Element;
/** Table caption displayed below the table in muted text. */
declare function TableCaption({ className, ...props }: React$1.ComponentProps<"caption">): react_jsx_runtime.JSX.Element;

/**
 * Tabbed interface component with list, trigger, and content sub-components.
 *
 * Built on Radix Tabs with density-aware sizing (`h-element` for the tab list).
 * Supports keyboard navigation and focus management out of the box.
 *
 * **Tokens used** (Phase B foundation):
 * - `h-element` → `--density-element` (32 default, 28 compact, 44 comfortable)
 * - `bg-muted` → `--muted` (warm subtle bg per SmartHR)
 * - `data-[state=active]:bg-card` → `--card` (warm off-white surface raise)
 * - `rounded-xl` (10 px) on TabsList for the pill-shape container
 * - `rounded-md` → `--radius-md` = 4 px on individual triggers
 *
 * @example
 * ```tsx
 * <Tabs defaultValue="overview">
 *   <TabsList>
 *     <TabsTrigger value="overview">Overview</TabsTrigger>
 *     <TabsTrigger value="analytics">Analytics</TabsTrigger>
 *     <TabsTrigger value="settings">Settings</TabsTrigger>
 *   </TabsList>
 *   <TabsContent value="overview">
 *     <p>Overview content here.</p>
 *   </TabsContent>
 *   <TabsContent value="analytics">
 *     <p>Analytics content here.</p>
 *   </TabsContent>
 *   <TabsContent value="settings">
 *     <p>Settings content here.</p>
 *   </TabsContent>
 * </Tabs>
 * ```
 */
declare function Tabs({ className, ...props }: React$1.ComponentProps<typeof TabsPrimitive.Root>): react_jsx_runtime.JSX.Element;
/** Container for `TabsTrigger` elements. Renders as a rounded pill with muted background. */
declare function TabsList({ className, ...props }: React$1.ComponentProps<typeof TabsPrimitive.List>): react_jsx_runtime.JSX.Element;
/** Individual tab button. Highlights with a card background when active. Requires a `value` prop matching a `TabsContent`. */
declare function TabsTrigger({ className, ...props }: React$1.ComponentProps<typeof TabsPrimitive.Trigger>): react_jsx_runtime.JSX.Element;
/** Content panel shown when its `value` matches the active tab. */
declare function TabsContent({ className, ...props }: React$1.ComponentProps<typeof TabsPrimitive.Content>): react_jsx_runtime.JSX.Element;

interface TagInputProps {
    /** Array of current tag strings. */
    value?: string[];
    /** Callback fired when the tags array changes. */
    onChange?: (tags: string[]) => void;
    /** Placeholder text shown when there are no tags. */
    placeholder?: string;
    /** Additional CSS class for the outer container. */
    className?: string;
    /** Whether the tag input is disabled. */
    disabled?: boolean;
    /** Maximum number of tags allowed. */
    maxTags?: number;
    /** Whether duplicate tag values are allowed. Defaults to `false`. */
    allowDuplicates?: boolean;
    /** Character or pattern used to split pasted text into tags. Defaults to `","`. */
    delimiter?: string | RegExp;
    /**
     * Single-string validation error. When set, the wrapper gets a destructive
     * border, the inner input gets `aria-invalid`, and the message renders below.
     */
    error?: string;
}
declare function TagInput({ value, onChange, placeholder, className, disabled, maxTags, allowDuplicates, delimiter, error, }: TagInputProps): react_jsx_runtime.JSX.Element;

type NativeTextareaProps = Omit<React$1.ComponentProps<'textarea'>, 'value' | 'onChange'>;
interface StandardTextareaProps extends NativeTextareaProps {
    /** Translatable mode disabled (default). */
    translatable?: never;
    value?: string;
    onChange?: React$1.ChangeEventHandler<HTMLTextAreaElement>;
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
type TextareaProps = StandardTextareaProps | TranslatableTextareaProps;
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
declare const Textarea: React$1.ForwardRefExoticComponent<(Omit<StandardTextareaProps, "ref"> | Omit<TranslatableTextareaProps, "ref">) & React$1.RefAttributes<HTMLTextAreaElement>>;

interface TimePickerProps {
    /** Currently selected time in `"HH:mm"` format (e.g., `"14:30"`). */
    value?: string;
    /** Callback fired when a time is selected. Receives a `"HH:mm"` string. */
    onChange?: (time: string) => void;
    /** Placeholder text shown when no time is selected. */
    placeholder?: string;
    /** Additional CSS class for the trigger button. */
    className?: string;
    /** Whether the time picker is disabled. */
    disabled?: boolean;
    /** Use 24-hour format (0-23). When false, shows 12-hour (1-12). Defaults to `true`. */
    format24h?: boolean;
    /**
     * Single-string validation error. When set, the trigger gets
     * `aria-invalid` and a red error message is rendered below.
     */
    error?: string;
}
/**
 * Time picker with a scrollable hour/minute popover.
 * Opens a two-column dropdown for selecting hours and minutes.
 *
 * @example
 * ```tsx
 * const [time, setTime] = useState<string>();
 *
 * <TimePicker
 *   value={time}
 *   onChange={setTime}
 *   placeholder="Select time"
 *   format24h
 * />
 * ```
 */
declare function TimePicker({ value, onChange, placeholder, className, disabled, format24h, error, }: TimePickerProps): react_jsx_runtime.JSX.Element;
interface TimeInputProps {
    /** Current time value in `"HH:mm"` format. */
    value?: string;
    /** Callback fired on blur with a valid `"HH:mm"` string. */
    onChange?: (time: string) => void;
    /** Additional CSS class for the input wrapper. */
    className?: string;
    /** Whether the input is disabled. */
    disabled?: boolean;
    /**
     * Single-string validation error. When set, the input gets
     * `aria-invalid` and a red error message is rendered below.
     */
    error?: string;
}
/**
 * Inline text input for typing a time value directly.
 * Automatically formats input as `HH:mm` and validates on blur.
 *
 * @example
 * ```tsx
 * const [time, setTime] = useState("09:00");
 *
 * <TimeInput value={time} onChange={setTime} />
 * ```
 */
declare function TimeInput({ value, onChange, className, disabled, error, }: TimeInputProps): react_jsx_runtime.JSX.Element;

declare const toggleVariants: (props?: ({
    variant?: "default" | "outline" | null | undefined;
    size?: "default" | "sm" | "lg" | null | undefined;
} & class_variance_authority_types.ClassProp) | undefined) => string;
interface ToggleProps extends React$1.ComponentProps<typeof TogglePrimitive.Root>, VariantProps<typeof toggleVariants> {
}
/**
 * Two-state button that can be toggled on or off, with variant and size options.
 *
 * @example
 * ```tsx
 * // Basic toggle
 * <Toggle aria-label="Toggle bold">
 *   <BoldIcon className="size-4" />
 * </Toggle>
 *
 * // Outline variant, small size
 * <Toggle variant="outline" size="sm">
 *   <ItalicIcon className="size-4" />
 * </Toggle>
 * ```
 */
declare function Toggle({ className, variant, size, ...props }: ToggleProps): react_jsx_runtime.JSX.Element;

type ToggleGroupProps = React$1.ComponentProps<typeof ToggleGroupPrimitive.Root> & VariantProps<typeof toggleVariants>;
/**
 * Group of toggle buttons where one or multiple items can be active.
 * Provides shared `variant` and `size` context to child {@link ToggleGroupItem} components.
 *
 * @example
 * ```tsx
 * // Single selection
 * <ToggleGroup type="single" defaultValue="center">
 *   <ToggleGroupItem value="left"><AlignLeftIcon /></ToggleGroupItem>
 *   <ToggleGroupItem value="center"><AlignCenterIcon /></ToggleGroupItem>
 *   <ToggleGroupItem value="right"><AlignRightIcon /></ToggleGroupItem>
 * </ToggleGroup>
 *
 * // Multiple selection with outline variant
 * <ToggleGroup type="multiple" variant="outline" size="sm">
 *   <ToggleGroupItem value="bold"><BoldIcon /></ToggleGroupItem>
 *   <ToggleGroupItem value="italic"><ItalicIcon /></ToggleGroupItem>
 * </ToggleGroup>
 * ```
 */
declare function ToggleGroup({ className, variant, size, children, ...props }: ToggleGroupProps): react_jsx_runtime.JSX.Element;
type ToggleGroupItemProps = React$1.ComponentProps<typeof ToggleGroupPrimitive.Item> & VariantProps<typeof toggleVariants>;
/**
 * Individual toggle item within a {@link ToggleGroup}.
 * Inherits `variant` and `size` from the parent group context unless overridden.
 *
 * @example
 * ```tsx
 * <ToggleGroupItem value="bold" aria-label="Toggle bold">
 *   <BoldIcon className="size-4" />
 * </ToggleGroupItem>
 * ```
 */
declare function ToggleGroupItem({ className, children, variant, size, ...props }: ToggleGroupItemProps): react_jsx_runtime.JSX.Element;

interface TranslatableRenderProps {
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
declare function TranslatableField({ config, value, onChange, children, className, errors, }: TranslatableFieldProps): react_jsx_runtime.JSX.Element;

interface TranslatableRichTextProps {
    value: TranslatableValue;
    onChange: (value: TranslatableValue) => void;
    /** Per-locale error map. Truthy string for a locale code marks it invalid. */
    errors?: Partial<Record<string, string>>;
    className?: string;
}
declare function TranslatableRichText({ value, onChange, errors, className, }: TranslatableRichTextProps): react_jsx_runtime.JSX.Element;

interface UIProviderProps {
    children: ReactNode;
    /**
     * Initial theme. Defaults to user's saved localStorage value or `'system'`.
     */
    defaultTheme?: Theme;
    /**
     * Available locales for translatable fields.
     * @example { en: 'English', vi: 'Tiếng Việt', ja: '日本語' }
     */
    locales?: LocaleMap;
    /**
     * Locale shown first in translatable fields.
     * Defaults to the first key in `locales`.
     */
    defaultLocale?: LocaleCode;
    /**
     * Locale used when a field has no value for the active locale.
     * Defaults to `defaultLocale`.
     */
    fallbackLocale?: LocaleCode;
    /**
     * date-fns `Locale` object used by date components (DatePicker, CalendarMini, etc.).
     * Typed as `object` to avoid importing date-fns as a direct dependency.
     *
     * @example
     * ```tsx
     * import { ja } from 'date-fns/locale';
     * <UIProvider dateFnsLocale={ja}>{children}</UIProvider>
     * ```
     */
    dateFnsLocale?: object;
    /**
     * Callback fired when the active locale changes via `setLocale`.
     * Use this to sync with i18n libraries, localStorage, etc.
     */
    onLocaleChange?: (locale: LocaleCode) => void;
    /**
     * IANA timezone string (e.g. `'Asia/Tokyo'`).
     * Defaults to the browser's local timezone.
     */
    timezone?: string;
    /**
     * Callback fired when the timezone changes via `setTimezone`.
     * Use this to sync with backend, localStorage, etc.
     */
    onTimezoneChange?: (timezone: string) => void;
}
/**
 * Root provider for @omnifyjp/ui — handles dark mode and translatable field config.
 *
 * @example
 * ```tsx
 * <UIProvider
 *   locales={{ en: 'English', vi: 'Tiếng Việt', ja: '日本語' }}
 *   defaultLocale="en"
 *   fallbackLocale="en"
 * >
 *   {children}
 * </UIProvider>
 * ```
 */
declare function UIProvider({ children, defaultTheme, locales, defaultLocale, fallbackLocale, dateFnsLocale, onLocaleChange, timezone: timezoneProp, onTimezoneChange, }: UIProviderProps): react_jsx_runtime.JSX.Element;

/** Access theme and setTheme. Must be inside UIProvider. */
declare function useTheme(): {
    theme: Theme;
    setTheme: (t: Theme) => void;
};
/**
 * Returns the locale config from UIProvider.
 * Returns `undefined` when no `locales` prop was passed to UIProvider.
 */
declare function useUILocales(): UILocaleConfig | undefined;
/**
 * Returns the active locale state and locale config from UIProvider.
 * Must be used inside UIProvider.
 */
declare function useLocale(): {
    currentLocale: LocaleCode;
    setLocale: (locale: LocaleCode) => void;
    locales: LocaleMap;
    defaultLocale: LocaleCode;
    fallbackLocale: LocaleCode;
};
/**
 * Returns the active timezone and setter from UIProvider.
 * Must be used inside UIProvider.
 */
declare function useTimezone(): {
    timezone: string;
    setTimezone: (tz: string) => void;
};
/**
 * Resolves the effective UILocaleConfig for a translatable field.
 * Merges inline `TranslatableConfig` with the provider's locale config.
 */
declare function resolveTranslatableConfig(translatable: TranslatableConfig, providerLocales: UILocaleConfig | undefined): UILocaleConfig | undefined;

export { Accordion, AccordionContent, AccordionItem, AccordionTrigger, Alert, AlertDescription, AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogOverlay, AlertDialogPortal, AlertDialogTitle, AlertDialogTrigger, AlertTitle, AspectRatio, Avatar, AvatarFallback, type AvatarFallbackProps, AvatarImage, type AvatarImageProps, type AvatarProps, Badge, type BadgeProps, Breadcrumb, BreadcrumbEllipsis, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator, Button, type ButtonProps, Calendar, Card, CardAction, CardContent, CardDescription, CardFooter, CardHeader, CardMedia, CardTitle, Carousel, type CarouselApi, CarouselContent, CarouselItem, CarouselNext, CarouselPrevious, Checkbox, type CheckboxProps, Collapsible, CollapsibleContent, CollapsibleTrigger, ColorPicker, Combobox, type ComboboxOption, type ComboboxProps, Command, CommandDialog, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList, CommandSeparator, CommandShortcut, ContextMenu, ContextMenuCheckboxItem, ContextMenuContent, ContextMenuGroup, ContextMenuItem, ContextMenuLabel, ContextMenuPortal, ContextMenuRadioGroup, ContextMenuRadioItem, ContextMenuSeparator, ContextMenuShortcut, ContextMenuSub, ContextMenuSubContent, ContextMenuSubTrigger, ContextMenuTrigger, DatePicker, DateRangePicker, Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogOverlay, DialogPortal, DialogTitle, DialogTrigger, Drawer, DrawerBody, DrawerClose, DrawerContent, DrawerDescription, DrawerFooter, DrawerHeader, DrawerOverlay, DrawerPortal, DrawerTitle, DrawerTrigger, DropdownMenu, DropdownMenuCheckboxItem, DropdownMenuContent, DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuPortal, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuSeparator, DropdownMenuShortcut, DropdownMenuSub, DropdownMenuSubContent, DropdownMenuSubTrigger, DropdownMenuTrigger, FileUpload, type FileUploadProps, type FileUploadVariant, Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage, FullWidthPageContainer, HoverCard, HoverCardContent, HoverCardTrigger, Input, InputOTP, InputOTPGroup, InputOTPSeparator, InputOTPSlot, type InputProps, Label, type LabelProps, type LocaleCode, type LocaleMap, Menubar, MenubarCheckboxItem, MenubarContent, MenubarGroup, MenubarItem, MenubarLabel, MenubarMenu, MenubarPortal, MenubarRadioGroup, MenubarRadioItem, MenubarSeparator, MenubarShortcut, MenubarSub, MenubarSubContent, MenubarSubTrigger, MenubarTrigger, MultiCombobox, type MultiComboboxProps, NavigationMenu, NavigationMenuContent, NavigationMenuIndicator, NavigationMenuItem, NavigationMenuLink, NavigationMenuList, NavigationMenuTrigger, NavigationMenuViewport, PageContainer, type PageContainerProps, Pagination, PaginationContent, PaginationEllipsis, PaginationItem, PaginationLink, PaginationNext, PaginationPrevious, PasswordInput, type PasswordInputProps, Popover, PopoverAnchor, PopoverContent, PopoverTrigger, Progress, type ProgressProps, RadioGroup, RadioGroupItem, type RadioGroupItemProps, type RadioGroupProps, Rating, ResizableHandle, ResizablePanel, ResizablePanelGroup, RichTextEditor, type RichTextEditorProps, ScrollArea, ScrollBar, Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectScrollDownButton, SelectScrollUpButton, SelectSeparator, SelectTrigger, SelectValue, Separator, type SeparatorProps, Sheet, SheetClose, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle, SheetTrigger, Sidebar, SidebarContent, SidebarFooter, SidebarGroup, SidebarGroupAction, SidebarGroupContent, SidebarGroupLabel, SidebarHeader, SidebarInput, SidebarInset, SidebarMenu, SidebarMenuAction, SidebarMenuBadge, SidebarMenuButton, SidebarMenuItem, SidebarMenuSkeleton, SidebarMenuSub, SidebarMenuSubButton, SidebarMenuSubItem, SidebarProvider, SidebarRail, SidebarSeparator, SidebarTrigger, Skeleton, type SkeletonProps, Slider, type SliderProps, SlugInput, type SlugInputLabels, type SlugInputProps, Spinner, SplitPageContainer, StandardPageContainer, StatusBadge, type StatusBadgeProps, Switch, type SwitchProps, Table, TableBody, TableCaption, TableCell, TableFooter, TableHead, TableHeader, TableRow, Tabs, TabsContent, TabsList, TabsTrigger, TagInput, type TagInputProps, Textarea, type TextareaProps, type Theme, TimeInput, TimePicker, Toaster, Toggle, ToggleGroup, ToggleGroupItem, type ToggleGroupItemProps, type ToggleGroupProps, type ToggleProps, Tooltip, TooltipContent, TooltipProvider, TooltipTrigger, type TranslatableConfig, TranslatableField, type TranslatableRenderProps, TranslatableRichText, type TranslatableRichTextProps, type TranslatableValue, type UILocaleConfig, UIProvider, badgeVariants, buttonVariants, generateSlug, inputVariants, navigationMenuTriggerStyle, resolveTranslatableConfig, toggleVariants, useFormField, useLocale, useSidebar, useTheme, useTimezone, useUILocales };
