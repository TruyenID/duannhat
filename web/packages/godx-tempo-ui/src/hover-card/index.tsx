import * as React from "react";
import * as HoverCardPrimitive from "@radix-ui/react-hover-card";

import { cn } from "@/lib/utils";

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
function HoverCard({
  ...props
}: React.ComponentProps<typeof HoverCardPrimitive.Root>) {
  return <HoverCardPrimitive.Root data-slot="hover-card" {...props} />;
}

/** Element that shows the hover card on mouse enter. Use `asChild` to merge into your own element. */
function HoverCardTrigger({
  ...props
}: React.ComponentProps<typeof HoverCardPrimitive.Trigger>) {
  return (
    <HoverCardPrimitive.Trigger data-slot="hover-card-trigger" {...props} />
  );
}

/** Floating content panel that appears on hover. */
function HoverCardContent({
  className,
  align = "center",
  sideOffset = 4,
  ...props
}: React.ComponentProps<typeof HoverCardPrimitive.Content>) {
  return (
    <HoverCardPrimitive.Portal data-slot="hover-card-portal">
      <HoverCardPrimitive.Content
        data-slot="hover-card-content"
        align={align}
        sideOffset={sideOffset}
        className={cn(
          "bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 w-64 origin-(--radix-hover-card-content-transform-origin) rounded-md border p-4 shadow-md outline-hidden",
          className,
        )}
        {...props}
      />
    </HoverCardPrimitive.Portal>
  );
}

export { HoverCard, HoverCardTrigger, HoverCardContent };