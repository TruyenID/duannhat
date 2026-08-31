import * as React from "react";
import * as LabelPrimitive from "@radix-ui/react-label";

import { cn } from "@/lib/utils";

type LabelProps = React.ComponentPropsWithoutRef<typeof LabelPrimitive.Root>;

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
const Label = React.forwardRef<
  React.ElementRef<typeof LabelPrimitive.Root>,
  LabelProps
>(({ className, ...props }, ref) => {
  return (
    <LabelPrimitive.Root
      ref={ref}
      data-slot="label"
      className={cn(
        "flex items-center gap-2 text-sm leading-none font-medium select-none group-data-[disabled=true]:pointer-events-none group-data-[disabled=true]:opacity-50 peer-disabled:cursor-not-allowed peer-disabled:opacity-50",
        className,
      )}
      {...props}
    />
  );
});
Label.displayName = LabelPrimitive.Root.displayName;

export { Label };
export type { LabelProps };
