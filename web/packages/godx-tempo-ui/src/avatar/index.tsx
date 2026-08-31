import * as React from "react";
import * as AvatarPrimitive from "@radix-ui/react-avatar";

import { cn } from "@/lib/utils";

type AvatarProps = React.ComponentProps<typeof AvatarPrimitive.Root>;

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
function Avatar({
  className,
  ...props
}: AvatarProps) {
  return (
    <AvatarPrimitive.Root
      data-slot="avatar"
      className={cn(
        "relative flex size-10 shrink-0 overflow-hidden rounded-full",
        className,
      )}
      {...props}
    />
  );
}

type AvatarImageProps = React.ComponentProps<typeof AvatarPrimitive.Image>;

/**
 * Image element rendered inside an {@link Avatar}. Falls back to
 * {@link AvatarFallback} when the image fails to load.
 *
 * @example
 * ```tsx
 * <AvatarImage src="/photo.jpg" alt="Jane Doe" />
 * ```
 */
function AvatarImage({
  className,
  ...props
}: AvatarImageProps) {
  return (
    <AvatarPrimitive.Image
      data-slot="avatar-image"
      className={cn("aspect-square size-full", className)}
      {...props}
    />
  );
}

type AvatarFallbackProps = React.ComponentProps<typeof AvatarPrimitive.Fallback>;

/**
 * Fallback content displayed while the {@link AvatarImage} is loading or
 * if it fails. Typically shows user initials (max 2 characters).
 *
 * @example
 * ```tsx
 * <AvatarFallback>JD</AvatarFallback>
 * ```
 */
function AvatarFallback({
  className,
  ...props
}: AvatarFallbackProps) {
  return (
    <AvatarPrimitive.Fallback
      data-slot="avatar-fallback"
      className={cn(
        "bg-muted flex size-full items-center justify-center rounded-full",
        className,
      )}
      {...props}
    />
  );
}

export { Avatar, AvatarImage, AvatarFallback };
export type { AvatarProps, AvatarImageProps, AvatarFallbackProps };