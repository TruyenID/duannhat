import * as React from "react";
import { Toaster as Sonner, toast, type ToasterProps } from "sonner";

/**
 * Toast notification container powered by the Sonner library.
 * Renders toast messages at a configurable position on screen.
 * Place this once at the root of your app, then use `toast()` to trigger notifications.
 *
 * @example
 * ```tsx
 * // In your root layout:
 * <Toaster />
 *
 * // Anywhere in your app — import toast from this package, NOT "sonner"
 * // directly, otherwise module duplication can dispatch your toast to a
 * // different queue than the Toaster reads:
 * import { toast } from "@godxjp/ui";
 * toast.success("Changes saved");
 * toast.error("Something went wrong");
 * ```
 */
const Toaster = ({ ...props }: ToasterProps) => {
  return (
    <Sonner
      theme="light"
      className="toaster group"
      style={
        {
          "--normal-bg": "var(--popover)",
          "--normal-text": "var(--popover-foreground)",
          "--normal-border": "var(--border)",
        } as React.CSSProperties
      }
      {...props}
    />
  );
};

// Re-export `toast` so consumers can call it from the same sonner instance
// this Toaster is wired to. Sonner holds its queue as module-level state,
// so if a consumer installs sonner directly AND @godxjp/ui bundles its own
// copy, toast() and <Toaster> end up reading/writing to different stores
// and no visual toast ever appears. That's the root cause of the admin-web
// product save "no toast" bug (dxs-product).
//
// Paired with sonner moved to peerDependencies (package.json) — peer
// resolution guarantees a single module instance even if the consumer
// keeps importing from "sonner" directly. Belt and suspenders.
export { Toaster, toast };