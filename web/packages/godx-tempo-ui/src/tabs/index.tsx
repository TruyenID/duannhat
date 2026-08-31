import * as React from "react";
import * as TabsPrimitive from "@radix-ui/react-tabs";

import { cn } from "@/lib/utils";

/**
 * Tabbed interface component with list, trigger, and content sub-components.
 *
 * Built on Radix Tabs with density-aware sizing (`h-element` for the tab list).
 * Supports keyboard navigation and focus management out of the box.
 *
 * **Tokens used** (Phase B foundation):
 * - `h-element` → `--density-element` (32 default, 28 compact, 44 comfortable)
 * - `bg-muted` → `--muted` (warm subtle bg per SmartHR)
 * - `data-[state=active]:bg-card` → `--card` (warm off-white surface raise)
 * - `rounded-xl` (10 px) on TabsList for the pill-shape container
 * - `rounded-md` → `--radius-md` = 4 px on individual triggers
 *
 * @example
 * ```tsx
 * <Tabs defaultValue="overview">
 *   <TabsList>
 *     <TabsTrigger value="overview">Overview</TabsTrigger>
 *     <TabsTrigger value="analytics">Analytics</TabsTrigger>
 *     <TabsTrigger value="settings">Settings</TabsTrigger>
 *   </TabsList>
 *   <TabsContent value="overview">
 *     <p>Overview content here.</p>
 *   </TabsContent>
 *   <TabsContent value="analytics">
 *     <p>Analytics content here.</p>
 *   </TabsContent>
 *   <TabsContent value="settings">
 *     <p>Settings content here.</p>
 *   </TabsContent>
 * </Tabs>
 * ```
 */
function Tabs({
  className,
  ...props
}: React.ComponentProps<typeof TabsPrimitive.Root>) {
  return (
    <TabsPrimitive.Root
      data-slot="tabs"
      className={cn("flex flex-col gap-2", className)}
      {...props}
    />
  );
}

/** Container for `TabsTrigger` elements. Renders as a rounded pill with muted background. */
function TabsList({
  className,
  ...props
}: React.ComponentProps<typeof TabsPrimitive.List>) {
  return (
    <TabsPrimitive.List
      data-slot="tabs-list"
      className={cn(
        "bg-muted text-muted-foreground inline-flex h-element w-fit items-center justify-center rounded-xl p-[3px] flex",
        className,
      )}
      {...props}
    />
  );
}

/** Individual tab button. Highlights with a card background when active. Requires a `value` prop matching a `TabsContent`. */
function TabsTrigger({
  className,
  ...props
}: React.ComponentProps<typeof TabsPrimitive.Trigger>) {
  return (
    <TabsPrimitive.Trigger
      data-slot="tabs-trigger"
      className={cn(
        "data-[state=active]:bg-card dark:data-[state=active]:text-foreground focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:outline-ring dark:data-[state=active]:border-input dark:data-[state=active]:bg-input/30 text-foreground dark:text-muted-foreground inline-flex h-[calc(100%-1px)] flex-1 items-center justify-center gap-1.5 rounded-xl border border-transparent px-2 py-1 text-sm font-medium whitespace-nowrap transition-[color,box-shadow] focus-visible:ring-[3px] focus-visible:outline-1 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
        className,
      )}
      {...props}
    />
  );
}

/** Content panel shown when its `value` matches the active tab. */
function TabsContent({
  className,
  ...props
}: React.ComponentProps<typeof TabsPrimitive.Content>) {
  return (
    <TabsPrimitive.Content
      data-slot="tabs-content"
      className={cn("flex-1 outline-none", className)}
      {...props}
    />
  );
}

export { Tabs, TabsList, TabsTrigger, TabsContent };