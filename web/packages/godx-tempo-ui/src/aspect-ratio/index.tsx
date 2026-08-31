import * as AspectRatioPrimitive from "@radix-ui/react-aspect-ratio";

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
function AspectRatio({
  ...props
}: React.ComponentProps<typeof AspectRatioPrimitive.Root>) {
  return <AspectRatioPrimitive.Root data-slot="aspect-ratio" {...props} />;
}

export { AspectRatio };
