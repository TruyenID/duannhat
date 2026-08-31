// app/_layout.tsx
import "../global.css";
import { View, useWindowDimensions } from "react-native";
import { Stack } from "expo-router";
import { StatusBar } from "expo-status-bar";
import { SafeAreaProvider } from "react-native-safe-area-context";
import { AppProvider } from "../src/providers/app-provider";
import { AuthProvider } from "../src/providers/auth-provider";
import { QueryProvider } from "../src/providers/query-provider";
import { PaymentFlowProvider } from "../src/providers/payment-flow-provider";
import { ErrorBoundary } from "../src/components/error-boundary";
import { IdleTimer } from "../src/components/idle-timer";
import { TerminalProvider } from "../src/providers/terminal-provider";
import { WorkstationProvider } from "../src/providers/workstation-provider";
import { WorkstationStatusBanner } from "../src/components/workstation-status-banner";
import { computeCanvas } from "../src/lib/ui-scale";
import { initSentry } from "../src/lib/sentry";

// Initialise error tracking BEFORE the React tree mounts so a crash
// during the first provider's setup is captured. Silent no-op when
// EXPO_PUBLIC_SENTRY_DSN is unset (dev / tests / unwired deploys).
initSentry();

// Phép toán canvas sống ở `src/lib/ui-scale.ts` — nó chi phối MỌI màn, nên nó
// phải là hàm thuần có test ghim, chứ không phải mấy dòng số học lẫn trong một
// component có JSX (nơi không test đơn vị nào chạm tới được). Xem docblock ở đó
// để biết BASE_WIDTH / UI_ZOOM nghĩa là gì và khi nào được chỉnh.
function ScaledRoot({ children }: { children: React.ReactNode }) {
  const { width, height } = useWindowDimensions();

  const { scale, width: boxWidth, height: boxHeight } = computeCanvas(width, height);

  return (
    <View style={{ flex: 1, backgroundColor: "#000" }}>
      <View
        style={{
          width: boxWidth,
          height: boxHeight,
          transform: [{ scale }],
          transformOrigin: "top left",
          overflow: "hidden",
        }}
      >
        {children}
      </View>
    </View>
  );
}

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <ErrorBoundary>
        <AppProvider>
          <QueryProvider>
            <AuthProvider>
              <WorkstationProvider>
                <TerminalProvider>
                  <PaymentFlowProvider>
                    <IdleTimer>
                      <ScaledRoot>
                        <StatusBar hidden />
                        <View style={{ flex: 1 }}>
                          <WorkstationStatusBanner />
                          <View style={{ flex: 1 }}>
                            <Stack screenOptions={{ headerShown: false, animation: "fade" }} />
                          </View>
                        </View>
                      </ScaledRoot>
                    </IdleTimer>
                  </PaymentFlowProvider>
                </TerminalProvider>
              </WorkstationProvider>
            </AuthProvider>
          </QueryProvider>
        </AppProvider>
      </ErrorBoundary>
    </SafeAreaProvider>
  );
}
