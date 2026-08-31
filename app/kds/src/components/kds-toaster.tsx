import { Toaster as SonnerToaster, type ToasterProps } from "sonner";
import { useTheme } from "@/providers/use-theme";

/**
 * Theme-aware Sonner Toaster.
 *
 * `@godxjp/ui` exports a Toaster wrapper but hardcodes `theme: "light"`, so
 * toasts ignore AppProvider's dark/light/system switch. We import Sonner
 * directly here and bind its theme to `useTheme()`. Sonner already understands
 * "system" (it watches `prefers-color-scheme`), so the mapping is 1:1.
 *
 * CSS variables match @godxjp/ui's wrapper so the design system stays consistent
 * with pos-web / admin-web once they adopt the same pattern.
 */
export function KdsToaster(props: Omit<ToasterProps, "theme">) {
  const { theme } = useTheme();
  return (
    <SonnerToaster
      theme={theme}
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
}
