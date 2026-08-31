import * as React from "react";
import { Check, ChevronsUpDown, X } from "lucide-react";

import { cn } from "@/lib/utils";
import { Button } from "../button";
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "../command";
import { Popover, PopoverContent, PopoverTrigger } from "../popover";

/** A single option in the Combobox dropdown. */
export interface ComboboxOption {
  /** Unique value for this option. */
  value: string;
  /** Display label shown in the dropdown list. */
  label: string;
  /** Whether this option is non-selectable. */
  disabled?: boolean;
}

export interface ComboboxProps {
  /** Available options to display in the dropdown. */
  options: ComboboxOption[];
  /** Currently selected value. */
  value?: string;
  /** Callback fired when the selected value changes. */
  onChange?: (value: string) => void;
  /** Placeholder text shown when no value is selected. */
  placeholder?: string;
  /** Placeholder text for the search input inside the dropdown. */
  searchPlaceholder?: string;
  /** Text shown when no options match the search query. */
  emptyText?: string;
  /** Additional CSS class for the trigger button. */
  className?: string;
  /** Whether the combobox is disabled. */
  disabled?: boolean;
  /** Whether to show a clear button when a value is selected. */
  clearable?: boolean;
  /**
   * Single-string validation error. When set, the trigger gets
   * `aria-invalid` and a red error message is rendered below.
   */
  error?: string;
}

/**
 * Searchable single-select combobox built on cmdk and Radix Popover.
 * Combines a text search input with a selectable option list.
 *
 * @example
 * ```tsx
 * const [value, setValue] = useState("");
 *
 * <Combobox
 *   options={[
 *     { value: "react", label: "React" },
 *     { value: "vue", label: "Vue" },
 *     { value: "svelte", label: "Svelte" },
 *   ]}
 *   value={value}
 *   onChange={setValue}
 *   placeholder="Select framework..."
 *   searchPlaceholder="Search..."
 *   clearable
 * />
 * ```
 */
export function Combobox({
  options,
  value,
  onChange,
  placeholder = "Chọn...",
  searchPlaceholder = "Tìm kiếm...",
  emptyText = "Không tìm thấy kết quả.",
  className,
  disabled,
  clearable = false,
  error,
}: ComboboxProps) {
  const [open, setOpen] = React.useState(false);

  const selectedOption = options.find((option) => option.value === value);

  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation();
    onChange?.("");
  };

  return (
    <>
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          data-slot="combobox"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          aria-invalid={error ? true : undefined}
          disabled={disabled}
          className={cn(
            "w-full justify-between",
            !value && "text-muted-foreground",
            className
          )}
        >
          <span className="truncate">
            {selectedOption ? selectedOption.label : placeholder}
          </span>
          <div className="flex items-center gap-1 ml-2">
            {clearable && value && (
              <X
                className="h-4 w-4 opacity-50 hover:opacity-100"
                onClick={handleClear}
              />
            )}
            <ChevronsUpDown className="h-4 w-4 shrink-0 opacity-50" />
          </div>
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
        <Command>
          <CommandInput placeholder={searchPlaceholder} />
          <CommandList>
            <CommandEmpty>{emptyText}</CommandEmpty>
            <CommandGroup>
              {options.map((option) => (
                <CommandItem
                  key={option.value}
                  value={option.value}
                  disabled={option.disabled}
                  onSelect={(currentValue) => {
                    onChange?.(currentValue === value ? "" : currentValue);
                    setOpen(false);
                  }}
                >
                  <Check
                    className={cn(
                      "mr-2 h-4 w-4",
                      value === option.value ? "opacity-100" : "opacity-0"
                    )}
                  />
                  {option.label}
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
    {error ? (
      <p data-slot="combobox-error" className="mt-1 text-sm text-destructive">
        {error}
      </p>
    ) : null}
    </>
  );
}

export interface MultiComboboxProps {
  /** Available options to display in the dropdown. */
  options: ComboboxOption[];
  /** Array of currently selected values. */
  value?: string[];
  /** Callback fired when the selection changes. */
  onChange?: (value: string[]) => void;
  /** Placeholder text shown when no values are selected. */
  placeholder?: string;
  /** Placeholder text for the search input inside the dropdown. */
  searchPlaceholder?: string;
  /** Text shown when no options match the search query. */
  emptyText?: string;
  /** Additional CSS class for the trigger button. */
  className?: string;
  /** Whether the combobox is disabled. */
  disabled?: boolean;
  /** Maximum number of items that can be selected. */
  maxSelected?: number;
  /**
   * Single-string validation error. When set, the trigger gets
   * `aria-invalid` and a red error message is rendered below.
   */
  error?: string;
}

/**
 * Searchable multi-select combobox that allows selecting multiple values.
 * Selected items are shown as a count in the trigger button.
 *
 * @example
 * ```tsx
 * const [selected, setSelected] = useState<string[]>([]);
 *
 * <MultiCombobox
 *   options={[
 *     { value: "react", label: "React" },
 *     { value: "vue", label: "Vue" },
 *     { value: "svelte", label: "Svelte" },
 *   ]}
 *   value={selected}
 *   onChange={setSelected}
 *   placeholder="Select frameworks..."
 *   maxSelected={3}
 * />
 * ```
 */
export function MultiCombobox({
  options,
  value = [],
  onChange,
  placeholder = "Chọn...",
  searchPlaceholder = "Tìm kiếm...",
  emptyText = "Không tìm thấy kết quả.",
  className,
  disabled,
  maxSelected,
  error,
}: MultiComboboxProps) {
  const [open, setOpen] = React.useState(false);

  const selectedLabels = value
    .map((v) => options.find((opt) => opt.value === v)?.label)
    .filter(Boolean);

  const handleSelect = (selectedValue: string) => {
    const newValue = value.includes(selectedValue)
      ? value.filter((v) => v !== selectedValue)
      : maxSelected && value.length >= maxSelected
      ? value
      : [...value, selectedValue];

    onChange?.(newValue);
  };

  const handleClearAll = (e: React.MouseEvent) => {
    e.stopPropagation();
    onChange?.([]);
  };

  return (
    <>
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          data-slot="multi-combobox"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          aria-invalid={error ? true : undefined}
          disabled={disabled}
          className={cn(
            "w-full justify-between",
            !value.length && "text-muted-foreground",
            className
          )}
        >
          <span className="truncate">
            {selectedLabels.length > 0
              ? selectedLabels.length === 1
                ? selectedLabels[0]
                : `${selectedLabels.length} mục đã chọn`
              : placeholder}
          </span>
          <div className="flex items-center gap-1 ml-2">
            {value.length > 0 && (
              <X
                className="h-4 w-4 opacity-50 hover:opacity-100"
                onClick={handleClearAll}
              />
            )}
            <ChevronsUpDown className="h-4 w-4 shrink-0 opacity-50" />
          </div>
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
        <Command>
          <CommandInput placeholder={searchPlaceholder} />
          <CommandList>
            <CommandEmpty>{emptyText}</CommandEmpty>
            <CommandGroup>
              {options.map((option) => {
                const isSelected = value.includes(option.value);
                const isDisabled =
                  option.disabled ||
                  (!isSelected && maxSelected && value.length >= maxSelected);

                return (
                  <CommandItem
                    key={option.value}
                    value={option.value}
                    disabled={!!isDisabled}
                    onSelect={() => handleSelect(option.value)}
                  >
                    <Check
                      className={cn(
                        "mr-2 h-4 w-4",
                        isSelected ? "opacity-100" : "opacity-0"
                      )}
                    />
                    {option.label}
                  </CommandItem>
                );
              })}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
    {error ? (
      <p data-slot="multi-combobox-error" className="mt-1 text-sm text-destructive">
        {error}
      </p>
    ) : null}
    </>
  );
}
