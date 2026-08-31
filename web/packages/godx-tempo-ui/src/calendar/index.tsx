import * as React from "react";
import { ChevronLeftIcon, ChevronRightIcon, ChevronDownIcon } from "lucide-react";
import { DayPicker, getDefaultClassNames } from "react-day-picker";

import { cn } from "@/lib/utils";
import { buttonVariants } from "../button";

/**
 * Date picker calendar built on `react-day-picker` v9. Supports single, multiple,
 * and range selection modes. Styled with Shadcn UI conventions.
 *
 * @param showOutsideDays - Whether to show days from adjacent months. Defaults to `true`.
 *
 * @example
 * ```tsx
 * const [date, setDate] = useState<Date | undefined>();
 * <Calendar mode="single" selected={date} onSelect={setDate} />
 * ```
 */
function Calendar({
  className,
  classNames,
  showOutsideDays = true,
  ...props
}: React.ComponentProps<typeof DayPicker>) {
  const defaultClassNames = getDefaultClassNames();

  return (
    <DayPicker
      showOutsideDays={showOutsideDays}
      className={cn("p-3", className)}
      classNames={{
        root: cn("w-fit", defaultClassNames.root),
        months: cn("flex flex-col sm:flex-row gap-2", defaultClassNames.months),
        month: cn("flex flex-col gap-4 w-full", defaultClassNames.month),
        month_caption: cn(
          "flex justify-center pt-1 relative items-center w-full",
          defaultClassNames.month_caption,
        ),
        caption_label: cn("text-sm font-medium select-none", defaultClassNames.caption_label),
        dropdowns: cn(
          "flex items-center text-sm font-medium justify-center gap-1.5",
          defaultClassNames.dropdowns,
        ),
        dropdown_root: cn(
          "relative border border-input rounded-md",
          defaultClassNames.dropdown_root,
        ),
        dropdown: cn("absolute inset-0 opacity-0", defaultClassNames.dropdown),
        nav: cn(
          "flex items-center gap-1 w-full absolute top-0 inset-x-0 justify-between",
          defaultClassNames.nav,
        ),
        button_previous: cn(
          buttonVariants({ variant: "outline" }),
          "size-7 bg-transparent p-0 opacity-50 hover:opacity-100 select-none",
          defaultClassNames.button_previous,
        ),
        button_next: cn(
          buttonVariants({ variant: "outline" }),
          "size-7 bg-transparent p-0 opacity-50 hover:opacity-100 select-none",
          defaultClassNames.button_next,
        ),
        table: "w-full border-collapse",
        weekdays: cn("flex", defaultClassNames.weekdays),
        weekday: cn(
          "text-muted-foreground rounded-md w-8 font-normal text-[0.8rem] select-none",
          defaultClassNames.weekday,
        ),
        week: cn("flex w-full mt-2", defaultClassNames.week),
        day: cn(
          "relative p-0 text-center text-sm focus-within:relative focus-within:z-20 select-none",
          "[&:has([aria-selected])]:bg-accent",
          "[&:has([aria-selected].rdp-day_range_end)]:rounded-r-md",
          props.mode === "range"
            ? "[&:has(>.rdp-day_range_end)]:rounded-r-md [&:has(>.rdp-day_range_start)]:rounded-l-md first:[&:has([aria-selected])]:rounded-l-md last:[&:has([aria-selected])]:rounded-r-md"
            : "[&:has([aria-selected])]:rounded-md",
          defaultClassNames.day,
        ),
        day_button: cn(
          buttonVariants({ variant: "ghost" }),
          "size-8 p-0 font-normal aria-selected:opacity-100",
        ),
        range_start:
          "rdp-day_range_start aria-selected:bg-primary aria-selected:text-primary-foreground rounded-l-md",
        range_end:
          "rdp-day_range_end aria-selected:bg-primary aria-selected:text-primary-foreground rounded-r-md",
        selected:
          "bg-primary text-primary-foreground hover:bg-primary hover:text-primary-foreground focus:bg-primary focus:text-primary-foreground rounded-md",
        today: "bg-accent text-accent-foreground rounded-md",
        outside: "text-muted-foreground aria-selected:text-muted-foreground",
        disabled: "text-muted-foreground opacity-50",
        range_middle:
          "aria-selected:bg-accent aria-selected:text-accent-foreground rounded-none",
        hidden: "invisible",
        ...classNames,
      }}
      components={{
        Chevron: ({ className, orientation, ...chevronProps }) => {
          const Icon =
            orientation === "left"
              ? ChevronLeftIcon
              : orientation === "right"
                ? ChevronRightIcon
                : ChevronDownIcon;
          return <Icon className={cn("size-4", className)} {...chevronProps} />;
        },
      }}
      {...props}
    />
  );
}

export { Calendar };
