import * as React from "react";
import { X } from "lucide-react";
import { cn } from "../lib/utils";
import { Badge } from "../badge";

export interface TagInputProps {
  /** Array of current tag strings. */
  value?: string[];
  /** Callback fired when the tags array changes. */
  onChange?: (tags: string[]) => void;
  /** Placeholder text shown when there are no tags. */
  placeholder?: string;
  /** Additional CSS class for the outer container. */
  className?: string;
  /** Whether the tag input is disabled. */
  disabled?: boolean;
  /** Maximum number of tags allowed. */
  maxTags?: number;
  /** Whether duplicate tag values are allowed. Defaults to `false`. */
  allowDuplicates?: boolean;
  /** Character or pattern used to split pasted text into tags. Defaults to `","`. */
  delimiter?: string | RegExp;
  /**
   * Single-string validation error. When set, the wrapper gets a destructive
   * border, the inner input gets `aria-invalid`, and the message renders below.
   */
  error?: string;
}

export function TagInput({
  value = [],
  onChange,
  placeholder = "Type and press Enter...",
  className,
  disabled,
  maxTags,
  allowDuplicates = false,
  delimiter = ",",
  error,
}: TagInputProps) {
  const [inputValue, setInputValue] = React.useState("");
  const inputRef = React.useRef<HTMLInputElement>(null);

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setInputValue(e.target.value);
  };

  const addTag = (tag: string) => {
    const trimmedTag = tag.trim();

    if (!trimmedTag) return;
    if (maxTags && value.length >= maxTags) return;
    if (!allowDuplicates && value.includes(trimmedTag)) return;

    onChange?.([...value, trimmedTag]);
    setInputValue("");
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Enter" || e.key === delimiter) {
      e.preventDefault();
      addTag(inputValue);
    } else if (e.key === "Backspace" && !inputValue && value.length > 0) {
      onChange?.(value.slice(0, -1));
    }
  };

  const handlePaste = (e: React.ClipboardEvent<HTMLInputElement>) => {
    e.preventDefault();
    const pastedText = e.clipboardData.getData("text");
    const tags = pastedText.split(delimiter).map((tag) => tag.trim()).filter(Boolean);

    const newTags = allowDuplicates
      ? tags
      : tags.filter((tag) => !value.includes(tag));

    const tagsToAdd = maxTags
      ? newTags.slice(0, maxTags - value.length)
      : newTags;

    onChange?.([...value, ...tagsToAdd]);
    setInputValue("");
  };

  const removeTag = (index: number) => {
    onChange?.(value.filter((_, i) => i !== index));
  };

  return (
    <div data-slot="tag-input-wrapper">
      <div
        data-slot="tag-input"
        className={cn(
          "flex flex-wrap gap-2 p-2 border rounded-lg bg-background min-h-[42px] cursor-text",
          disabled && "opacity-50 cursor-not-allowed bg-muted",
          error && "border-destructive ring-2 ring-destructive/20",
          className,
        )}
        onClick={() => !disabled && inputRef.current?.focus()}
      >
        {value.map((tag, index) => (
          <Badge
            key={index}
            variant="secondary"
            className="gap-1 pl-2 pr-1 py-1 h-auto"
          >
            <span>{tag}</span>
            {!disabled && (
              <button
                type="button"
                onClick={(e) => {
                  e.stopPropagation();
                  removeTag(index);
                }}
                className="rounded-full hover:bg-muted-foreground/30 p-0.5 transition-colors"
              >
                <X className="h-3 w-3" />
              </button>
            )}
          </Badge>
        ))}

        <input
          ref={inputRef}
          type="text"
          value={inputValue}
          onChange={handleInputChange}
          onKeyDown={handleKeyDown}
          onPaste={handlePaste}
          disabled={disabled || (maxTags ? value.length >= maxTags : false)}
          placeholder={value.length === 0 ? placeholder : ""}
          aria-invalid={error ? true : undefined}
          className="flex-1 outline-none bg-transparent min-w-[120px] text-sm disabled:cursor-not-allowed"
        />
      </div>
      {error ? <p className="text-[11px] text-red-500 mt-1">{error}</p> : null}
    </div>
  );
}
