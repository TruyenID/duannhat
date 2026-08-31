import * as React from "react";
import * as ToggleGroupPrimitive from "@radix-ui/react-toggle-group";
import { type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";
import { toggleVariants } from "../toggle";

const ToggleGroupContext = React.createContext<
  VariantProps<typeof toggleVariants>
>({
  size: "default",
  variant: "default",
});

type ToggleGroupProps = React.ComponentProps<typeof ToggleGroupPrimitive.Root> &
  VariantProps<typeof toggleVariants>;

/**
 * Group of toggle buttons where one or multiple items can be active.
 * Provides shared `variant` and `size` context to child {@link ToggleGroupItem} components.
 *
 * @example
 * ```tsx
 * // Single selection
 * <ToggleGroup type="single" defaultValue="center">
 *   <ToggleGroupItem value="left"><AlignLeftIcon /></ToggleGroupItem>
 *   <ToggleGroupItem value="center"><AlignCenterIcon /></ToggleGroupItem>
 *   <ToggleGroupItem value="right"><AlignRightIcon /></ToggleGroupItem>
 * </ToggleGroup>
 *
 * // Multiple selection with outline variant
 * <ToggleGroup type="multiple" variant="outline" size="sm">
 *   <ToggleGroupItem value="bold"><BoldIcon /></ToggleGroupItem>
 *   <ToggleGroupItem value="italic"><ItalicIcon /></ToggleGroupItem>
 * </ToggleGroup>
 * ```
 */
function ToggleGroup({
  className,
  variant,
  size,
  children,
  ...props
}: ToggleGroupProps) {
  return (
    <ToggleGroupPrimitive.Root
      data-slot="toggle-group"
      data-variant={variant}
      data-size={size}
      className={cn(
        "group/toggle-group flex w-fit items-center rounded-md data-[variant=outline]:shadow-xs",
        className,
      )}
      {...props}
    >
      <ToggleGroupContext.Provider value={{ variant, size }}>
        {children}
      </ToggleGroupContext.Provider>
    </ToggleGroupPrimitive.Root>
  );
}

type ToggleGroupItemProps = React.ComponentProps<typeof ToggleGroupPrimitive.Item> &
  VariantProps<typeof toggleVariants>;

/**
 * Individual toggle item within a {@link ToggleGroup}.
 * Inherits `variant` and `size` from the parent group context unless overridden.
 *
 * @example
 * ```tsx
 * <ToggleGroupItem value="bold" aria-label="Toggle bold">
 *   <BoldIcon className="size-4" />
 * </ToggleGroupItem>
 * ```
 */
function ToggleGroupItem({
  className,
  children,
  variant,
  size,
  ...props
}: ToggleGroupItemProps) {
  const context = React.useContext(ToggleGroupContext);

  return (
    <ToggleGroupPrimitive.Item
      data-slot="toggle-group-item"
      data-variant={context.variant || variant}
      data-size={context.size || size}
      className={cn(
        toggleVariants({
          variant: context.variant || variant,
          size: context.size || size,
        }),
        "min-w-0 flex-1 shrink-0 rounded-none shadow-none first:rounded-l-md last:rounded-r-md focus:z-10 focus-visible:z-10 data-[variant=outline]:border-l-0 data-[variant=outline]:first:border-l",
        className,
      )}
      {...props}
    >
      {children}
    </ToggleGroupPrimitive.Item>
  );
}

export { ToggleGroup, ToggleGroupItem };
export type { ToggleGroupProps, ToggleGroupItemProps };