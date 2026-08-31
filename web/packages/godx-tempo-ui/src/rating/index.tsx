import * as React from "react";
import { Star } from "lucide-react";
import { cn } from "@/lib/utils";

interface RatingProps {
  /** Current rating value (e.g., `3` or `3.5` with half stars). */
  value?: number;
  /** Callback fired when the user clicks a star. Receives the new rating number. */
  onChange?: (value: number) => void;
  /** Maximum number of stars. Defaults to `5`. */
  max?: number;
  /** Size of the star icons. Defaults to `"md"`. */
  size?: "sm" | "md" | "lg";
  /** Whether the rating is display-only (non-interactive). Defaults to `false`. */
  readonly?: boolean;
  /** Whether half-star ratings are enabled. Defaults to `false`. */
  allowHalf?: boolean;
  /** Additional CSS class for the outer container. */
  className?: string;
}

/**
 * Star rating component with hover preview and optional half-star support.
 * Shows filled/empty/half star icons and displays the numeric value beside the stars.
 *
 * @example
 * ```tsx
 * const [rating, setRating] = useState(0);
 *
 * <Rating value={rating} onChange={setRating} max={5} />
 *
 * // Read-only display with half stars:
 * <Rating value={3.5} readonly allowHalf />
 * ```
 */
export function Rating({
  value = 0,
  onChange,
  max = 5,
  size = "md",
  readonly = false,
  allowHalf = false,
  className,
}: RatingProps) {
  const [hoverValue, setHoverValue] = React.useState<number | null>(null);

  const sizeClasses = {
    sm: "w-4 h-4",
    md: "w-5 h-5",
    lg: "w-6 h-6",
  };

  const handleClick = (index: number, isHalf: boolean) => {
    if (readonly) return;
    const newValue = isHalf ? index + 0.5 : index + 1;
    onChange?.(newValue);
  };

  const handleMouseMove = (index: number, e: React.MouseEvent<HTMLButtonElement>) => {
    if (readonly || !allowHalf) return;

    const rect = e.currentTarget.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const isHalf = x < rect.width / 2;

    setHoverValue(isHalf ? index + 0.5 : index + 1);
  };

  const handleMouseEnter = (index: number) => {
    if (readonly) return;
    if (!allowHalf) {
      setHoverValue(index + 1);
    }
  };

  const handleMouseLeave = () => {
    setHoverValue(null);
  };

  const getStarFill = (index: number) => {
    const currentValue = hoverValue !== null ? hoverValue : value;

    if (currentValue >= index + 1) {
      return "full";
    } else if (allowHalf && currentValue >= index + 0.5) {
      return "half";
    } else {
      return "empty";
    }
  };

  return (
    <div className={cn("flex items-center gap-1", className)}>
      {Array.from({ length: max }).map((_, index) => {
        const fill = getStarFill(index);

        return (
          <button
            key={index}
            type="button"
            onClick={(e) => {
              if (!allowHalf) {
                handleClick(index, false);
                return;
              }
              const rect = e.currentTarget.getBoundingClientRect();
              const x = e.clientX - rect.left;
              const isHalf = x < rect.width / 2;
              handleClick(index, isHalf);
            }}
            onMouseMove={(e) => handleMouseMove(index, e)}
            onMouseEnter={() => handleMouseEnter(index)}
            onMouseLeave={handleMouseLeave}
            disabled={readonly}
            className={cn(
              "relative transition-transform hover:scale-110",
              !readonly && "cursor-pointer",
              readonly && "cursor-default"
            )}
          >
            {fill === "half" ? (
              <div className="relative">
                <Star
                  className={cn(
                    sizeClasses[size],
                    "text-muted-foreground/40"
                  )}
                />
                <div className="absolute inset-0 overflow-hidden w-1/2">
                  <Star
                    className={cn(
                      sizeClasses[size],
                      "text-yellow-400 fill-yellow-400"
                    )}
                  />
                </div>
              </div>
            ) : (
              <Star
                className={cn(
                  sizeClasses[size],
                  fill === "full"
                    ? "text-yellow-400 fill-yellow-400"
                    : "text-muted-foreground/40"
                )}
              />
            )}
          </button>
        );
      })}

      {value > 0 && (
        <span className="ml-2 text-sm text-muted-foreground">
          {value.toFixed(allowHalf ? 1 : 0)}
        </span>
      )}
    </div>
  );
}
