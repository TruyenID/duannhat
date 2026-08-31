import * as React from "react";
import { Check } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "../button";
import { Popover, PopoverContent, PopoverTrigger } from "../popover";
import { Input } from "../input";

const PRESET_COLORS = [
  "#EF4444", // Red
  "#F97316", // Orange
  "#F59E0B", // Amber
  "#EAB308", // Yellow
  "#84CC16", // Lime
  "#22C55E", // Green
  "#10B981", // Emerald
  "#14B8A6", // Teal
  "#06B6D4", // Cyan
  "#0EA5E9", // Sky
  "#3B82F6", // Blue
  "#6366F1", // Indigo
  "#8B5CF6", // Purple
  "#A855F7", // Violet
  "#D946EF", // Fuchsia
  "#EC4899", // Pink
  "#F43F5E", // Rose
  "#64748B", // Slate
  "#6B7280", // Gray
  "#000000", // Black
];

interface ColorPickerProps {
  /** Currently selected color as a hex string (e.g., `"#3B82F6"`). */
  value?: string;
  /** Callback fired when a color is selected. Receives a hex string. */
  onChange?: (color: string) => void;
  /** Additional CSS class for the trigger button. */
  className?: string;
  /** Whether the color picker is disabled. */
  disabled?: boolean;
  /** Whether to show the preset color grid. Defaults to `true`. */
  showPresets?: boolean;
  /** Whether to show the custom hex input with native color picker. Defaults to `true`. */
  showInput?: boolean;
}

/**
 * Color picker with a popover containing preset color swatches and an optional custom hex input.
 * The trigger button shows the currently selected color swatch and its hex value.
 *
 * @example
 * ```tsx
 * const [color, setColor] = useState("#3B82F6");
 *
 * <ColorPicker
 *   value={color}
 *   onChange={setColor}
 *   showPresets
 *   showInput
 * />
 * ```
 */
export function ColorPicker({
  value = "#3B82F6",
  onChange,
  className,
  disabled,
  showPresets = true,
  showInput = true,
}: ColorPickerProps) {
  const [customColor, setCustomColor] = React.useState(value);

  const handleColorChange = (color: string) => {
    setCustomColor(color);
    onChange?.(color);
  };

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button
          variant="outline"
          disabled={disabled}
          className={cn(
            "w-full justify-start gap-2",
            className
          )}
        >
          <div
            className="h-4 w-4 rounded border border-border"
            style={{ backgroundColor: value }}
          />
          <span className="flex-1 text-left">{value}</span>
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-64 p-3" align="start">
        <div className="space-y-3">
          {showPresets && (
            <div>
              <div className="text-xs font-medium mb-2 text-foreground">
                Màu mặc định
              </div>
              <div className="grid grid-cols-10 gap-1.5">
                {PRESET_COLORS.map((color) => (
                  <button
                    key={color}
                    type="button"
                    className={cn(
                      "h-6 w-6 rounded border-2 transition-all hover:scale-110",
                      value === color
                        ? "border-foreground ring-2 ring-foreground ring-offset-1"
                        : "border-border"
                    )}
                    style={{ backgroundColor: color }}
                    onClick={() => handleColorChange(color)}
                  >
                    {value === color && (
                      <Check className="w-3 h-3 text-white mx-auto drop-shadow" />
                    )}
                  </button>
                ))}
              </div>
            </div>
          )}

          {showInput && (
            <div>
              <div className="text-xs font-medium mb-2 text-foreground">
                Màu tùy chỉnh
              </div>
              <div className="flex gap-2">
                <div className="relative flex-1">
                  <Input
                    value={customColor}
                    onChange={(e: React.ChangeEvent<HTMLInputElement>) => setCustomColor(e.target.value)}
                    onBlur={() => {
                      // Validate hex color
                      if (/^#[0-9A-F]{6}$/i.test(customColor)) {
                        handleColorChange(customColor);
                      } else {
                        setCustomColor(value);
                      }
                    }}
                    placeholder="#000000"
                    className="pr-10"
                  />
                  <input
                    type="color"
                    value={customColor}
                    onChange={(e) => {
                      setCustomColor(e.target.value);
                      handleColorChange(e.target.value);
                    }}
                    className="absolute right-2 top-1/2 -translate-y-1/2 h-6 w-6 rounded border border-border cursor-pointer"
                  />
                </div>
              </div>
            </div>
          )}
        </div>
      </PopoverContent>
    </Popover>
  );
}
