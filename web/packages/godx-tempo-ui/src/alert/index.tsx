import * as React from "react";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";
import type { UIColor } from "../internal/ui-types";

const alertVariants = cva(
  "relative w-full rounded-lg border px-4 py-3 text-sm grid has-[>svg]:grid-cols-[calc(var(--spacing)*4)_1fr] grid-cols-[0_1fr] has-[>svg]:gap-x-3 gap-y-0.5 items-start [&>svg]:size-4 [&>svg]:translate-y-0.5 [&>svg]:text-current",
  {
    variants: {
      variant: {
        // Bordered card style — color applied via compoundVariants
        default: "",
        // Legacy — maps to default + destructive color (backward compatible)
        destructive: "",
        // Filled background style — color applied via compoundVariants
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
      // ── Default (bordered) × color ──
      { variant: "default", color: "primary", className: "bg-card text-card-foreground" },
      { variant: "default", color: "destructive", className: "text-destructive bg-card [&>svg]:text-current *:data-[slot=alert-description]:text-destructive/90" },
      { variant: "default", color: "success", className: "text-success bg-card [&>svg]:text-current *:data-[slot=alert-description]:text-success/90" },
      { variant: "default", color: "warning", className: "text-warning bg-card [&>svg]:text-current *:data-[slot=alert-description]:text-warning/90" },
      { variant: "default", color: "info", className: "text-info bg-card [&>svg]:text-current *:data-[slot=alert-description]:text-info/90" },

      // ── Legacy destructive variant (backward compat) ──
      { variant: "destructive", className: "text-destructive bg-card [&>svg]:text-current *:data-[slot=alert-description]:text-destructive/90" },

      // ── Soft (filled bg) × color ──
      { variant: "soft", color: "primary", className: "bg-primary/10 text-primary border-primary/20 [&>svg]:text-current *:data-[slot=alert-description]:text-primary/90" },
      { variant: "soft", color: "destructive", className: "bg-destructive/10 text-destructive border-destructive/20 [&>svg]:text-current *:data-[slot=alert-description]:text-destructive/90" },
      { variant: "soft", color: "success", className: "bg-success/10 text-success border-success/20 [&>svg]:text-current *:data-[slot=alert-description]:text-success/90" },
      { variant: "soft", color: "warning", className: "bg-warning/10 text-warning border-warning/20 [&>svg]:text-current *:data-[slot=alert-description]:text-warning/90" },
      { variant: "soft", color: "info", className: "bg-info/10 text-info border-info/20 [&>svg]:text-current *:data-[slot=alert-description]:text-info/90" },
    ],
    defaultVariants: {
      variant: "default",
      color: "primary",
    },
  },
);

interface AlertProps
  extends React.ComponentProps<"div">,
    Omit<VariantProps<typeof alertVariants>, "color"> {
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
function Alert({
  className,
  variant,
  color,
  ...props
}: AlertProps) {
  return (
    <div
      data-slot="alert"
      role="alert"
      className={cn(alertVariants({ variant, color }), className)}
      {...props}
    />
  );
}

/** Bold title text within an Alert. Rendered in the second grid column when an icon is present. */
function AlertTitle({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-title"
      className={cn(
        "col-start-2 line-clamp-1 min-h-4 font-medium tracking-tight",
        className,
      )}
      {...props}
    />
  );
}

/** Descriptive body text within an Alert, rendered below the title. */
function AlertDescription({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-description"
      className={cn(
        "text-muted-foreground col-start-2 grid justify-items-start gap-1 text-sm [&_p]:leading-relaxed",
        className,
      )}
      {...props}
    />
  );
}

export { Alert, AlertTitle, AlertDescription };
