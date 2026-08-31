import { Component, type ErrorInfo, type ReactNode } from "react";
import { View, Text, Pressable } from "react-native";
import { reportErrorWithContext } from "../lib/error-reporter";
import { auditCrash } from "../lib/audit-log";
import { DEFAULT_LOCALE, getTranslations } from "../i18n";

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

/**
 * Chuỗi cho màn fallback, lấy ở `DEFAULT_LOCALE` — KHÔNG theo ngôn ngữ người dùng.
 *
 * Không phải bỏ sót: `ErrorBoundary` bọc NGOÀI `AppProvider` (`app/_layout.tsx`)
 * để một lỗi ném ra từ chính provider vẫn hiện được màn này. Ở vị trí đó không có
 * context nào để đọc locale, và class component cũng không gọi hook được.
 *
 * Đọc thẳng `getTranslations(DEFAULT_LOCALE)` thay vì hardcode tiếng Anh: máy
 * kiosk đứng ở quán Nhật và mặc định của app là `ja`. Một khách nói tiếng Việt
 * gặp màn này sẽ thấy tiếng Nhật — chấp nhận được, vì lựa chọn còn lại là MỌI
 * người đều thấy tiếng Anh, thứ không nhóm nào trong ba nhóm người dùng chọn.
 *
 * Muốn theo đúng locale thì phải tách một boundary thứ hai nằm TRONG provider —
 * đó là thay đổi kiến trúc, không phải sửa chuỗi (#8).
 */
function fallbackText(key: string): string {
  return getTranslations(DEFAULT_LOCALE)[key] ?? key;
}

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
            {fallbackText("error_boundary.title")}
          </Text>
          <Text style={{ fontSize: 14, color: "#717182", textAlign: "center", marginBottom: 24 }}>
            {this.state.error?.message ?? fallbackText("error_boundary.unknown")}
          </Text>
          <Pressable
            onPress={this.handleReset}
            style={{ paddingHorizontal: 24, paddingVertical: 10, backgroundColor: "#030213", borderRadius: 8 }}
          >
            <Text style={{ color: "#fff", fontSize: 14, fontWeight: "600" }}>{fallbackText("error_boundary.retry")}</Text>
          </Pressable>
        </View>
      );
    }

    return this.props.children;
  }
}
