import * as React from "react";
import { CircleIcon } from "lucide-react";
import * as RadioGroupPrimitive from "@radix-ui/react-radio-group";

import { cn } from "@/lib/utils";

type RadioGroupProps = React.ComponentProps<typeof RadioGroupPrimitive.Root>;

/**
 * Container for a set of mutually exclusive radio options.
 * Use with {@link RadioGroupItem} to build single-selection groups.
 *
 * @example
 * ```tsx
 * <RadioGroup defaultValue="option-1">
 *   <div className="flex items-center gap-2">
 *     <RadioGroupItem value="option-1" id="opt1" />
 *     <Label htmlFor="opt1">Option 1</Label>
 *   </div>
 *   <div className="flex items-center gap-2">
 *     <RadioGroupItem value="option-2" id="opt2" />
 *     <Label htmlFor="opt2">Option 2</Label>
 *   </div>
 * </RadioGroup>
 * ```
 */
function RadioGroup({
  className,
  ...props
}: RadioGroupProps) {
  return (
    <RadioGroupPrimitive.Root
      data-slot="radio-group"
      className={cn("grid gap-3", className)}
      {...props}
    />
  );
}

type RadioGroupItemProps = React.ComponentProps<typeof RadioGroupPrimitive.Item>;

/**
 * Individual radio option within a {@link RadioGroup}.
 * Renders as a circular indicator that fills when selected.
 *
 * @example
 * ```tsx
 * <RadioGroupItem value="dark" id="theme-dark" />
 * <Label htmlFor="theme-dark">Dark mode</Label>
 * ```
 */
function RadioGroupItem({
  className,
  ...props
}: RadioGroupItemProps) {
  return (
    <RadioGroupPrimitive.Item
      data-slot="radio-group-item"
      className={cn(
        "border-input text-primary focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive dark:bg-input/30 aspect-square size-4 shrink-0 rounded-full border shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50",
        className,
      )}
      {...props}
    >
      <RadioGroupPrimitive.Indicator
        data-slot="radio-group-indicator"
        className="relative flex items-center justify-center"
      >
        <CircleIcon className="fill-primary absolute top-1/2 left-1/2 size-2 -translate-x-1/2 -translate-y-1/2" />
      </RadioGroupPrimitive.Indicator>
    </RadioGroupPrimitive.Item>
  );
}

export { RadioGroup, RadioGroupItem };
export type { RadioGroupProps, RadioGroupItemProps };