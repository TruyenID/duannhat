import * as React from "react";
import { Slot } from "@radix-ui/react-slot";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";
import type { UIColor } from "../internal/ui-types";

const badgeVariants = cva(
  "inline-flex items-center justify-center rounded-md border px-2 py-0.5 text-xs font-medium w-fit whitespace-nowrap shrink-0 [&>svg]:size-3 gap-1 [&>svg]:pointer-events-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive transition-[color,box-shadow] overflow-hidden",
  {
    variants: {
      variant: {
        // Solid — color applied via compoundVariants
        default: "",
        // Legacy — maps to solid + destructive color (backward compatible)
        destructive: "",
        // Color-independent
        secondary:
          "border-transparent bg-secondary text-secondary-foreground [a&]:hover:bg-secondary/90",
        // Color-aware — applied via compoundVariants
        outline: "",
        soft: "",
      },
      color: {
        primary: "",
        destructive: "",
        success: "",
        warning: "",
        info: "",
      },
    },
    compoundVariants: [
      // ── Solid (default variant) × color ──
      { variant: "default", color: "primary", className: "border-transparent bg-primary text-primary-foreground [a&]:hover:bg-primary/90" },
      { variant: "default", color: "destructive", className: "border-transparent bg-destructive text-destructive-foreground [a&]:hover:bg-destructive/90" },
      { variant: "default", color: "success", className: "border-transparent bg-success text-success-foreground [a&]:hover:bg-success/90" },
      { variant: "default", color: "warning", className: "border-transparent bg-warning text-warning-foreground [a&]:hover:bg-warning/90" },
      { variant: "default", color: "info", className: "border-transparent bg-info text-info-foreground [a&]:hover:bg-info/90" },

      // ── Legacy destructive variant (backward compat) ──
      { variant: "destructive", className: "border-transparent bg-destructive text-destructive-foreground [a&]:hover:bg-destructive/90" },

      // ── Outline × color ──
      { variant: "outline", color: "primary", className: "text-foreground [a&]:hover:bg-accent [a&]:hover:text-accent-foreground" },
      { variant: "outline", color: "destructive", className: "border-destructive/50 text-destructive [a&]:hover:bg-destructive/10" },
      { variant: "outline", color: "success", className: "border-success/50 text-success [a&]:hover:bg-success/10" },
      { variant: "outline", color: "warning", className: "border-warning/50 text-warning [a&]:hover:bg-warning/10" },
      { variant: "outline", color: "info", className: "border-info/50 text-info [a&]:hover:bg-info/10" },

      // ── Soft × color ──
      { variant: "soft", color: "primary", className: "border-transparent bg-primary/10 text-primary [a&]:hover:bg-primary/20" },
      { variant: "soft", color: "destructive", className: "border-transparent bg-destructive/10 text-destructive [a&]:hover:bg-destructive/20" },
      { variant: "soft", color: "success", className: "border-transparent bg-success/10 text-success [a&]:hover:bg-success/20" },
      { variant: "soft", color: "warning", className: "border-transparent bg-warning/10 text-warning [a&]:hover:bg-warning/20" },
      { variant: "soft", color: "info", className: "border-transparent bg-info/10 text-info [a&]:hover:bg-info/20" },
    ],
    defaultVariants: {
      variant: "default",
      color: "primary",
    },
  },
);

interface BadgeProps
  extends React.ComponentProps<"span">,
    Omit<VariantProps<typeof badgeVariants>, "color"> {
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
function Badge({
  className,
  variant,
  color,
  asChild = false,
  ...props
}: BadgeProps) {
  const Comp = asChild ? Slot : "span";

  return (
    <Comp
      data-slot="badge"
      className={cn(badgeVariants({ variant, color }), className)}
      {...props}
    />
  );
}

export { Badge, badgeVariants };
export type { BadgeProps };
