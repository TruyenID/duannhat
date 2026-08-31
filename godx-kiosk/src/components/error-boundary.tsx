import { Component, type ErrorInfo, type ReactNode } from "react";
import { View, Text, Pressable } from "react-native";
import { reportErrorWithContext } from "../lib/error-reporter";
import { auditCrash } from "../lib/audit-log";

interface Props {
  children: ReactNode;
  fallback?: ReactNode;
}

interface State {
  hasError: boolean;
  error: Error | null;
}

/**
 * React Error Boundary for React Native.
 *
 * Catches unhandled JavaScript errors in the component tree and
 * renders a fallback UI instead of crashing the app.
 *
 * @example
 * ```tsx
 * <ErrorBoundary>
 *   <App />
 * </ErrorBoundary>
 * ```
 */
export class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    // Sentry + console fallback.
    reportErrorWithContext("error-boundary", error, {
      componentStack: info.componentStack ?? "",
    });
    // Best-effort cloud audit so reconciliation knows the kiosk
    // crashed. Fire-and-forget — never blocks the recovery UI.
    auditCrash({
      error_message: error.message,
      error_stack: error.stack?.slice(0, 1000),
    });
  }

  handleReset = () => {
    this.setState({ hasError: false, error: null });
  };

  render() {
    if (this.state.hasError) {
      if (this.props.fallback) return this.props.fallback;

      return (
        <View style={{ flex: 1, justifyContent: "center", alignItems: "center", padding: 24, backgroundColor: "#fff" }}>
          <Text style={{ fontSize: 18, fontWeight: "700", marginBottom: 8, color: "#0a0a0a" }}>
            Something went wrong
          </Text>
          <Text style={{ fontSize: 14, color: "#717182", textAlign: "center", marginBottom: 24 }}>
            {this.state.error?.message ?? "An unexpected error occurred."}
          </Text>
          <Pressable
            onPress={this.handleReset}
            style={{ paddingHorizontal: 24, paddingVertical: 10, backgroundColor: "#030213", borderRadius: 8 }}
          >
            <Text style={{ color: "#fff", fontSize: 14, fontWeight: "600" }}>Try Again</Text>
          </Pressable>
        </View>
      );
    }

    return this.props.children;
  }
}
