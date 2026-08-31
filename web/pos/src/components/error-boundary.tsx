import { Component, type ErrorInfo, type ReactNode } from "react";
import { captureException } from "@/lib/sentry";

interface Props {
  children: ReactNode;
  fallback: (error: Error, reset: () => void) => ReactNode;
  onError?: (error: Error, info: ErrorInfo) => void;
}

interface State {
  error: Error | null;
}

export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // Sentry first so an operator-side console-write that throws
    // doesn't lose the report.
    captureException(error, {
      tags: { boundary: "global" },
      extra: { componentStack: info.componentStack ?? "" },
    });
    console.error("[ErrorBoundary] caught render error", error, info);
    this.props.onError?.(error, info);
  }

  reset = (): void => {
    this.setState({ error: null });
  };

  render() {
    if (this.state.error) {
      return this.props.fallback(this.state.error, this.reset);
    }
    return this.props.children;
  }
}
