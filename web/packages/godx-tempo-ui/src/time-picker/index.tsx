import * as React from "react";
import { Clock } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "../button";
import { Input } from "../input";
import { Popover, PopoverContent, PopoverTrigger } from "../popover";
import { ScrollArea } from "../scroll-area";

interface TimePickerProps {
  /** Currently selected time in `"HH:mm"` format (e.g., `"14:30"`). */
  value?: string;
  /** Callback fired when a time is selected. Receives a `"HH:mm"` string. */
  onChange?: (time: string) => void;
  /** Placeholder text shown when no time is selected. */
  placeholder?: string;
  /** Additional CSS class for the trigger button. */
  className?: string;
  /** Whether the time picker is disabled. */
  disabled?: boolean;
  /** Use 24-hour format (0-23). When false, shows 12-hour (1-12). Defaults to `true`. */
  format24h?: boolean;
  /**
   * Single-string validation error. When set, the trigger gets
   * `aria-invalid` and a red error message is rendered below.
   */
  error?: string;
}

/**
 * Time picker with a scrollable hour/minute popover.
 * Opens a two-column dropdown for selecting hours and minutes.
 *
 * @example
 * ```tsx
 * const [time, setTime] = useState<string>();
 *
 * <TimePicker
 *   value={time}
 *   onChange={setTime}
 *   placeholder="Select time"
 *   format24h
 * />
 * ```
 */
export function TimePicker({
  value,
  onChange,
  placeholder = "Chọn giờ",
  className,
  disabled,
  format24h = true,
  error,
}: TimePickerProps) {
  const [open, setOpen] = React.useState(false);

  const hours = format24h
    ? Array.from({ length: 24 }, (_, i) => i.toString().padStart(2, "0"))
    : Array.from({ length: 12 }, (_, i) => (i + 1).toString().padStart(2, "0"));

  const minutes = Array.from({ length: 60 }, (_, i) =>
    i.toString().padStart(2, "0")
  );

  const [selectedHour, selectedMinute] = value?.split(":") || ["", ""];

  const handleHourSelect = (hour: string) => {
    const newTime = `${hour}:${selectedMinute || "00"}`;
    onChange?.(newTime);
  };

  const handleMinuteSelect = (minute: string) => {
    const newTime = `${selectedHour || "00"}:${minute}`;
    onChange?.(newTime);
    setOpen(false);
  };

  return (
    <>
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          disabled={disabled}
          aria-invalid={error ? true : undefined}
          className={cn(
            "w-full justify-start",
            !value && "text-muted-foreground",
            className
          )}
        >
          <Clock className="mr-2 h-4 w-4" />
          {value || placeholder}
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-auto p-0" align="start">
        <div className="flex">
          {/* Hours */}
          <ScrollArea className="h-60 w-20 border-r">
            <div className="p-1">
              {hours.map((hour) => (
                <button
                  key={hour}
                  type="button"
                  onClick={() => handleHourSelect(hour)}
                  className={cn(
                    "w-full px-3 py-2 text-sm rounded hover:bg-accent transition-colors text-center",
                    selectedHour === hour && "bg-primary/10 text-primary font-semibold"
                  )}
                >
                  {hour}
                </button>
              ))}
            </div>
          </ScrollArea>

          {/* Minutes */}
          <ScrollArea className="h-60 w-20">
            <div className="p-1">
              {minutes.map((minute) => (
                <button
                  key={minute}
                  type="button"
                  onClick={() => handleMinuteSelect(minute)}
                  className={cn(
                    "w-full px-3 py-2 text-sm rounded hover:bg-accent transition-colors text-center",
                    selectedMinute === minute && "bg-primary/10 text-primary font-semibold"
                  )}
                >
                  {minute}
                </button>
              ))}
            </div>
          </ScrollArea>
        </div>
      </PopoverContent>
    </Popover>
    {error ? (
      <p data-slot="time-picker-error" className="mt-1 text-sm text-destructive">
        {error}
      </p>
    ) : null}
    </>
  );
}

interface TimeInputProps {
  /** Current time value in `"HH:mm"` format. */
  value?: string;
  /** Callback fired on blur with a valid `"HH:mm"` string. */
  onChange?: (time: string) => void;
  /** Additional CSS class for the input wrapper. */
  className?: string;
  /** Whether the input is disabled. */
  disabled?: boolean;
  /**
   * Single-string validation error. When set, the input gets
   * `aria-invalid` and a red error message is rendered below.
   */
  error?: string;
}

/**
 * Inline text input for typing a time value directly.
 * Automatically formats input as `HH:mm` and validates on blur.
 *
 * @example
 * ```tsx
 * const [time, setTime] = useState("09:00");
 *
 * <TimeInput value={time} onChange={setTime} />
 * ```
 */
export function TimeInput({
  value = "",
  onChange,
  className,
  disabled,
  error,
}: TimeInputProps) {
  const [localValue, setLocalValue] = React.useState(value);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    let input = e.target.value.replace(/\D/g, ""); // Remove non-digits

    if (input.length >= 2) {
      const hours = parseInt(input.substring(0, 2));
      if (hours > 23) input = "23" + input.substring(2);
    }

    if (input.length >= 4) {
      const minutes = parseInt(input.substring(2, 4));
      if (minutes > 59) input = input.substring(0, 2) + "59";
    }

    // Format as HH:mm
    if (input.length >= 2) {
      input = input.substring(0, 2) + ":" + input.substring(2, 4);
    }

    setLocalValue(input);
  };

  const handleBlur = () => {
    // Validate and format
    const parts = localValue.split(":");
    if (parts.length === 2 && parts[0].length === 2 && parts[1].length === 2) {
      onChange?.(localValue);
    } else {
      setLocalValue(value);
    }
  };

  return (
    <>
    <div className="relative">
      <Input
        type="text"
        value={localValue}
        onChange={handleChange}
        onBlur={handleBlur}
        placeholder="00:00"
        maxLength={5}
        disabled={disabled}
        aria-invalid={error ? true : undefined}
        className={cn("pr-10", className)}
      />
      <Clock className="absolute right-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
    </div>
    {error ? (
      <p data-slot="time-input-error" className="mt-1 text-sm text-destructive">
        {error}
      </p>
    ) : null}
    </>
  );
}
