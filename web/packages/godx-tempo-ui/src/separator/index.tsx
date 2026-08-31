import * as React from "react";
import * as SeparatorPrimitive from "@radix-ui/react-separator";

import { cn } from "@/lib/utils";

type SeparatorProps = React.ComponentProps<typeof SeparatorPrimitive.Root>;

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
function Separator({
  className,
  orientation = "horizontal",
  decorative = true,
  ...props
}: SeparatorProps) {
  return (
    <SeparatorPrimitive.Root
      data-slot="separator-root"
      decorative={decorative}
      orientation={orientation}
      className={cn(
        "bg-border shrink-0 data-[orientation=horizontal]:h-px data-[orientation=horizontal]:w-full data-[orientation=vertical]:h-full data-[orientation=vertical]:w-px",
        className,
      )}
      {...props}
    />
  );
}

export { Separator };
export type { SeparatorProps };