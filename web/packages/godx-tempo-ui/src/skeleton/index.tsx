import { cn } from "@/lib/utils";

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
function Skeleton({ className, ...props }: SkeletonProps) {
  return (
    <div
      data-slot="skeleton"
      className={cn("bg-accent animate-pulse rounded-md", className)}
      {...props}
    />
  );
}

export { Skeleton };
export type { SkeletonProps };
