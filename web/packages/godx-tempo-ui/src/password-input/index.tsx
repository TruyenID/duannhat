import * as React from "react";
import { type VariantProps } from "class-variance-authority";
import { Eye, EyeOff } from "lucide-react";

import { cn } from "@/lib/utils";
import { inputVariants } from "../input";

interface PasswordInputProps
  extends Omit<React.ComponentProps<"input">, "type" | "size">,
    VariantProps<typeof inputVariants> {
  /**
   * Single-string validation error. When set, the inner input gets
   * `aria-invalid` and a red error message is rendered below.
   */
  error?: string;
}

/**
 * Password input with a built-in show/hide toggle button.
 *
 * Extends native `<input>` (minus `type` which is managed internally).
 * Shares the same size variants as `Input`.
 *
 * @example
 * ```tsx
 * // Default size
 * <PasswordInput placeholder="Enter password" />
 *
 * // Sizes: xs (24px) | sm (28px) | default (32px) | lg (36px) | xl (44px)
 * <PasswordInput size="xl" placeholder="Password" />
 *
 * // Controlled
 * <PasswordInput
 *   value={password}
 *   onChange={(e) => setPassword(e.target.value)}
 *   placeholder="Password"
 * />
 * ```
 */
const PasswordInput = React.forwardRef<HTMLInputElement, PasswordInputProps>(
  ({ className, size, error, ...props }, ref) => {
    const [visible, setVisible] = React.useState(false);

    return (
      <div data-slot="password-input">
        <div className="relative">
          <input
            type={visible ? "text" : "password"}
            ref={ref}
            data-slot="input"
            aria-invalid={error ? true : undefined}
            className={cn(inputVariants({ size, className: cn("pr-10 [&::-ms-reveal]:hidden [&::-webkit-credentials-auto-fill-button]:hidden", className) }))}
            {...props}
          />
          <button
            type="button"
            tabIndex={-1}
            className="absolute right-0 top-0 flex h-full w-10 items-center justify-center text-muted-foreground hover:text-foreground transition-colors"
            onClick={() => setVisible((v) => !v)}
            aria-label={visible ? "Hide password" : "Show password"}
          >
            {visible ? (
              <EyeOff className="h-4 w-4" />
            ) : (
              <Eye className="h-4 w-4" />
            )}
          </button>
        </div>
        {error ? <p className="text-[11px] text-red-500 mt-1">{error}</p> : null}
      </div>
    );
  },
);
PasswordInput.displayName = "PasswordInput";

export { PasswordInput };
export type { PasswordInputProps };
