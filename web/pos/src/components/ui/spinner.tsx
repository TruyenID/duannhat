import { Loader2Icon } from "lucide-react";
import { cn } from "@/lib/utils";

export type SpinnerProps = React.ComponentProps<"svg">;

/**
 * The only loading affordance we use across the app.
 * Never import Loader2 directly — use this component so a11y attrs and
 * animation stay consistent and globally restyleable.
 */
export function Spinner({ className, ...props }: SpinnerProps) {
  return (
    <Loader2Icon
      data-slot="spinner"
      role="status"
      aria-label="Loading"
      className={cn("size-4 animate-spin", className)}
      {...props}
    />
  );
}
