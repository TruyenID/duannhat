import * as React from "react";

import { cn } from "@/lib/utils";

/**
 * Styled card container with header, title, description, action, content, and footer sub-components.
 *
 * **Tokens used** (Phase B foundation — `plans/design-foundations-japanese.md`):
 * - `gap-card` → `--spacing-card` = 16 px (24 px on `[data-density="comfortable"]`, 12 px on compact)
 * - `px-card` / `pt-card` / `pb-card` → same `--spacing-card` token
 * - `bg-card` / `text-card-foreground` → semantic role tokens (warm off-white / off-black per SmartHR)
 * - `rounded-lg` → `--radius-lg` = 6 px (SmartHR card radius — JP enterprise subtle)
 * - `border` = 1 px hairline (border > shadow per JP enterprise convention)
 *
 * The Card automatically adopts the active density mode via the density tokens
 * — wrap a subtree in `<div data-density="compact">` or `"comfortable"` to shift.
 *
 * @example
 * ```tsx
 * <Card>
 *   <CardHeader>
 *     <CardTitle>Notifications</CardTitle>
 *     <CardDescription>You have 3 unread messages.</CardDescription>
 *     <CardAction>
 *       <Button variant="outline">Mark all read</Button>
 *     </CardAction>
 *   </CardHeader>
 *   <CardContent>
 *     <p>Your recent activity will appear here.</p>
 *   </CardContent>
 *   <CardFooter>
 *     <Button>View all</Button>
 *   </CardFooter>
 * </Card>
 * ```
 */
function Card({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card"
      className={cn(
        "bg-card text-card-foreground flex flex-col gap-card rounded-lg border",
        className,
      )}
      {...props}
    />
  );
}

/**
 * Card header section. Lays out title, description, and optional action in a grid.
 *
 * **Tokens used:**
 * - `px-card` / `pt-card` / `pb-card` → `--spacing-card`
 * - `gap-2` (8 px = `--spacing-2`) — title-to-description gap inside the header.
 *   Sits between the related-items "tight" yohaku step (4 px) and the inside-card
 *   "default" (16 px) — see Foundations / Spacing for the 1:1.5:3 ratio.
 */
function CardHeader({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-header"
      className={cn(
        "@container/card-header grid auto-rows-min grid-rows-[auto_auto] items-start gap-2 px-card pt-card has-data-[slot=card-action]:grid-cols-[1fr_auto] [.border-b]:pb-card",
        className,
      )}
      {...props}
    />
  );
}

/** Card title rendered as an `<h4>` element. */
function CardTitle({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <h4
      data-slot="card-title"
      className={cn("leading-none", className)}
      {...props}
    />
  );
}

/** Card description text displayed in muted foreground color. */
function CardDescription({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <p
      data-slot="card-description"
      className={cn("text-muted-foreground", className)}
      {...props}
    />
  );
}

/** Card action slot positioned at the top-right of `CardHeader`. Place buttons or menus here. */
function CardAction({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-action"
      className={cn(
        "col-start-2 row-span-2 row-start-1 self-start justify-self-end",
        className,
      )}
      {...props}
    />
  );
}

/** Card content area with horizontal padding. Bottom padding applied when last child. */
function CardContent({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-content"
      className={cn("px-card [&:last-child]:pb-card", className)}
      {...props}
    />
  );
}

/**
 * Edge-to-edge media slot for image / video / illustration cards (Pinterest,
 * product gallery, blog preview, etc).
 *
 * Unlike the other Card sub-components, `CardMedia` has **no horizontal
 * padding** — the child media fills the full Card width. When `CardMedia` is
 * the first child of `Card` it rounds its top corners to match the Card's
 * border radius; when it's the last child it rounds its bottom corners.
 *
 * The default has no aspect-ratio constraint — pass `aspectRatio` (any
 * Tailwind aspect-ratio class string fragment, e.g. `"16/9"`, `"4/3"`,
 * `"square"`) for a consistent gallery layout.
 *
 * Place an `<img>`, `<video>`, or Next.js `<Image fill>` inside.
 *
 * @example
 * ```tsx
 * <Card className="w-72 overflow-hidden">
 *   <CardMedia aspectRatio="16/9">
 *     <img src="/cover.jpg" alt="" className="size-full object-cover" />
 *   </CardMedia>
 *   <CardHeader>
 *     <CardTitle>Yakiniku platter</CardTitle>
 *     <CardDescription>From the spring menu</CardDescription>
 *   </CardHeader>
 * </Card>
 * ```
 */
function CardMedia({
  className,
  aspectRatio,
  children,
  ...props
}: React.ComponentProps<"div"> & { aspectRatio?: string }) {
  const aspectClass = aspectRatio
    ? aspectRatio === "square"
      ? "aspect-square"
      : `aspect-[${aspectRatio}]`
    : undefined;

  return (
    <div
      data-slot="card-media"
      className={cn(
        "relative overflow-hidden bg-muted",
        // Round top corners when this is the first child of the Card
        "[&:first-child]:rounded-t-lg",
        // Round bottom corners when this is the last child of the Card
        "[&:last-child]:rounded-b-lg",
        // Drop the parent flex-col gap-card spacing when CardMedia is followed
        // by another Card sub-component (image flush against the next slot).
        "[&:not(:last-child)]:-mb-card",
        aspectClass,
        className,
      )}
      {...props}
    >
      {children}
    </div>
  );
}

/** Card footer with horizontal layout. Typically used for action buttons. */
function CardFooter({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-footer"
      className={cn("flex items-center px-card pb-card [.border-t]:pt-card", className)}
      {...props}
    />
  );
}

export {
  Card,
  CardHeader,
  CardFooter,
  CardTitle,
  CardAction,
  CardDescription,
  CardContent,
  CardMedia,
};
