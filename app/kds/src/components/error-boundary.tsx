import React, { type ReactNode } from "react";
import { captureException } from "@/lib/sentry";

interface State {
  error: Error | null;
}

interface Props {
  children: ReactNode;
}

/**
 * Global error boundary catching uncaught render errors. Renders a
 * recovery UI with reload button instead of a blank white screen.
 *
 * Hard-coded English copy here because:
 *   - I18nProvider is INSIDE the boundary; if i18n itself errored,
 *     we couldn't translate the error message anyway
 *   - Recovery copy needs to be guaranteed renderable even when the
 *     whole React tree below failed
 *
 * Errors flow to:
 *   - Sentry via captureException (no-op when VITE_SENTRY_DSN unset)
 *   - console.error as a fallback so dev still sees the stack
 */
export class ErrorBoundary extends React.Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: React.ErrorInfo) {
    captureException(error, {
      tags: { boundary: "global" },
      extra: { componentStack: info.componentStack },
    });
    console.error("[ErrorBoundary]", error, info);
  }

  render() {
    if (this.state.error) {
      return (
        <div
          role="alert"
          className="min-h-screen grid place-items-center p-4 bg-background text-foreground"
        >
          <div className="text-center max-w-md space-y-4">
            <h1 className="text-2xl font-semibold">Something went wrong</h1>
            <p className="text-sm text-muted-foreground break-words">
              {this.state.error.message || "Unknown error"}
            </p>
            <button
              type="button"
              onClick={() => location.reload()}
              className="px-6 py-3 bg-primary text-primary-foreground rounded-lg min-h-12 font-medium"
            >
              Reload
            </button>
          </div>
        </div>
      );
    }
    return this.props.children;
  }
}
