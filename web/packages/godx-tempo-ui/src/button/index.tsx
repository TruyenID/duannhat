import * as React from "react";
import { Slot } from "@radix-ui/react-slot";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";
import type { UIColor } from "../internal/ui-types";

const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        // Solid — color applied via compoundVariants
        default: "",
        // Legacy — maps to solid + destructive color (backward compatible)
        destructive: "",
        // Color-independent
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-secondary/80",
        // Color-aware — applied via compoundVariants
        outline: "",
        soft: "",
        ghost: "",
        link: "",
      },
      color: {
        primary: "",
        destructive: "",
        success: "",
        warning: "",
        info: "",
      },
      size: {
        xs: "h-element-xs rounded-md gap-1 px-2 text-xs has-[>svg]:px-1.5",
        sm: "h-element-sm rounded-md gap-1.5 px-3 has-[>svg]:px-2.5",
        default: "h-element px-4 py-2 has-[>svg]:px-3",
        lg: "h-element-lg rounded-md px-6 has-[>svg]:px-4",
        xl: "h-element-xl rounded-md px-8 text-base font-semibold has-[>svg]:px-5",
        icon: "size-element rounded-md",
      },
    },
    compoundVariants: [
      // ── Solid (default variant) × color ──
      { variant: "default", color: "primary", className: "bg-primary text-primary-foreground hover:bg-primary/90" },
      { variant: "default", color: "destructive", className: "bg-destructive text-destructive-foreground hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40" },
      { variant: "default", color: "success", className: "bg-success text-success-foreground hover:bg-success/90 focus-visible:ring-success/20 dark:focus-visible:ring-success/40" },
      { variant: "default", color: "warning", className: "bg-warning text-warning-foreground hover:bg-warning/90 focus-visible:ring-warning/20 dark:focus-visible:ring-warning/40" },
      { variant: "default", color: "info", className: "bg-info text-info-foreground hover:bg-info/90 focus-visible:ring-info/20 dark:focus-visible:ring-info/40" },

      // ── Legacy destructive variant (backward compat) ──
      { variant: "destructive", className: "bg-destructive text-destructive-foreground hover:bg-destructive/90 focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40" },

      // ── Outline × color ──
      { variant: "outline", color: "primary", className: "border border-input bg-background text-foreground hover:bg-accent hover:text-accent-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50" },
      { variant: "outline", color: "destructive", className: "border border-destructive/50 text-destructive bg-background hover:bg-destructive/10 focus-visible:ring-destructive/20" },
      { variant: "outline", color: "success", className: "border border-success/50 text-success bg-background hover:bg-success/10 focus-visible:ring-success/20" },
      { variant: "outline", color: "warning", className: "border border-warning/50 text-warning bg-background hover:bg-warning/10 focus-visible:ring-warning/20" },
      { variant: "outline", color: "info", className: "border border-info/50 text-info bg-background hover:bg-info/10 focus-visible:ring-info/20" },

      // ── Soft × color ──
      { variant: "soft", color: "primary", className: "bg-primary/10 text-primary hover:bg-primary/20" },
      { variant: "soft", color: "destructive", className: "bg-destructive/10 text-destructive hover:bg-destructive/20 focus-visible:ring-destructive/20" },
      { variant: "soft", color: "success", className: "bg-success/10 text-success hover:bg-success/20 focus-visible:ring-success/20" },
      { variant: "soft", color: "warning", className: "bg-warning/10 text-warning hover:bg-warning/20 focus-visible:ring-warning/20" },
      { variant: "soft", color: "info", className: "bg-info/10 text-info hover:bg-info/20 focus-visible:ring-info/20" },

      // ── Ghost × color ──
      { variant: "ghost", color: "primary", className: "hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/50" },
      { variant: "ghost", color: "destructive", className: "text-destructive hover:bg-destructive/10" },
      { variant: "ghost", color: "success", className: "text-success hover:bg-success/10" },
      { variant: "ghost", color: "warning", className: "text-warning hover:bg-warning/10" },
      { variant: "ghost", color: "info", className: "text-info hover:bg-info/10" },

      // ── Link × color ──
      { variant: "link", color: "primary", className: "text-primary underline-offset-4 hover:underline" },
      { variant: "link", color: "destructive", className: "text-destructive underline-offset-4 hover:underline" },
      { variant: "link", color: "success", className: "text-success underline-offset-4 hover:underline" },
      { variant: "link", color: "warning", className: "text-warning underline-offset-4 hover:underline" },
      { variant: "link", color: "info", className: "text-info underline-offset-4 hover:underline" },
    ],
    defaultVariants: {
      variant: "default",
      color: "primary",
      size: "default",
    },
  },
);

interface ButtonProps
  extends React.ComponentProps<"button">,
    Omit<VariantProps<typeof buttonVariants>, "color"> {
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
const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, color, size, asChild = false, block = false, ...props }, ref) => {
    const Comp = asChild ? Slot : "button";

    return (
      <Comp
        ref={ref}
        data-slot="button"
        className={cn(buttonVariants({ variant, color, size }), block && "w-full", className)}
        {...props}
      />
    );
  },
);

Button.displayName = "Button";

export { Button, buttonVariants };
export type { ButtonProps };
