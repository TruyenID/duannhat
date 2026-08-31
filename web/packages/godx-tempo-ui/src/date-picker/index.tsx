import { format } from "date-fns";
import { Calendar as CalendarIcon } from "lucide-react";
import type { Locale } from "date-fns";

import { cn } from "@/lib/utils";
import { toZonedTime } from "../internal/timezone";
import { useDateFnsLocale, useTimezone } from "../internal/ui-hooks";
import { Button } from "../button";
import { Calendar } from "../calendar";
import { Popover, PopoverContent, PopoverTrigger } from "../popover";

interface DatePickerProps {
  /** Currently selected date. */
  value?: Date;
  /** Callback fired when a date is selected or cleared. */
  onChange?: (date: Date | undefined) => void;
  /** Placeholder text shown when no date is selected. */
  placeholder?: string;
  /** Additional CSS class for the trigger button. */
  className?: string;
  /** Whether the date picker is disabled. */
  disabled?: boolean;
  /** date-fns Locale object for date formatting (e.g., `ja` for Japanese). */
  locale?: Locale;
  /**
   * Single-string validation error. When set, the trigger gets
   * `aria-invalid` and a red error message is rendered below.
   */
  error?: string;
}

/**
 * Single date picker with a calendar popover.
 * Displays the selected date formatted with date-fns and opens a calendar on click.
 *
 * @example
 * ```tsx
 * const [date, setDate] = useState<Date>();
 *
 * <DatePicker
 *   value={date}
 *   onChange={setDate}
 *   placeholder="Pick a date"
 * />
 * ```
 */
export function DatePicker({
  value,
  onChange,
  placeholder = "Select date",
  className,
  disabled,
  locale: localeProp,
  error,
}: DatePickerProps) {
  const contextLocale = useDateFnsLocale() as Locale | undefined;
  const locale = localeProp ?? contextLocale;
  const { timezone } = useTimezone();

  return (
    <>
    <Popover>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          disabled={disabled}
          aria-invalid={error ? true : undefined}
          className={cn(
            "w-full justify-start text-left font-normal",
            !value && "text-muted-foreground",
            className
          )}
        >
          <CalendarIcon className="mr-2 h-4 w-4" />
          {value ? format(toZonedTime(value, timezone), "PPP", locale ? { locale } : undefined) : <span>{placeholder}</span>}
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-auto p-0" align="start">
        <Calendar
          mode="single"
          selected={value}
          onSelect={onChange}
          autoFocus
          locale={locale}
        />
      </PopoverContent>
    </Popover>
    {error ? (
      <p data-slot="date-picker-error" className="mt-1 text-sm text-destructive">
        {error}
      </p>
    ) : null}
    </>
  );
}

interface DateRangePickerProps {
  /** Currently selected date range with `from` and optional `to`. */
  value?: { from: Date | undefined; to?: Date | undefined };
  /** Callback fired when the date range changes. */
  onChange?: (range: { from: Date | undefined; to?: Date | undefined } | undefined) => void;
  /** Placeholder text shown when no range is selected. */
  placeholder?: string;
  /** Additional CSS class for the trigger button. */
  className?: string;
  /** Whether the date range picker is disabled. */
  disabled?: boolean;
  /** date-fns Locale object for date formatting (e.g., `ja` for Japanese). */
  locale?: Locale;
  /**
   * Single-string validation error. When set, the trigger gets
   * `aria-invalid` and a red error message is rendered below.
   */
  error?: string;
}

/**
 * Date range picker with a two-month calendar popover.
 * Allows selecting a start and end date displayed as a range string.
 *
 * @example
 * ```tsx
 * const [range, setRange] = useState<{ from: Date | undefined; to?: Date }>();
 *
 * <DateRangePicker
 *   value={range}
 *   onChange={setRange}
 *   placeholder="Select date range"
 * />
 * ```
 */
export function DateRangePicker({
  value,
  onChange,
  placeholder = "Select date range",
  className,
  disabled,
  locale: localeProp,
  error,
}: DateRangePickerProps) {
  const contextLocale = useDateFnsLocale() as Locale | undefined;
  const locale = localeProp ?? contextLocale;
  const { timezone } = useTimezone();

  return (
    <>
    <Popover>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          disabled={disabled}
          aria-invalid={error ? true : undefined}
          className={cn(
            "w-full justify-start text-left font-normal",
            !value && "text-muted-foreground",
            className
          )}
        >
          <CalendarIcon className="mr-2 h-4 w-4" />
          {value?.from ? (
            value.to ? (
              <>
                {format(toZonedTime(value.from, timezone), "PPP", locale ? { locale } : undefined)} -{" "}
                {format(toZonedTime(value.to, timezone), "PPP", locale ? { locale } : undefined)}
              </>
            ) : (
              format(toZonedTime(value.from, timezone), "PPP", locale ? { locale } : undefined)
            )
          ) : (
            <span>{placeholder}</span>
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-auto p-0" align="start">
        <Calendar
          mode="range"
          selected={value}
          onSelect={onChange}
          numberOfMonths={2}
          autoFocus
          locale={locale}
        />
      </PopoverContent>
    </Popover>
    {error ? (
      <p data-slot="date-range-picker-error" className="mt-1 text-sm text-destructive">
        {error}
      </p>
    ) : null}
    </>
  );
}
