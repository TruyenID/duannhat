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
import { initSentry } from "../src/lib/sentry";

// Initialise error tracking BEFORE the React tree mounts so a crash
// during the first provider's setup is captured. Silent no-op when
// EXPO_PUBLIC_SENTRY_DSN is unset (dev / tests / unwired deploys).
initSentry();

const BASE_WIDTH = 1366;

// UI zoom knob — makes the WHOLE interface (fonts + components) bigger or
// smaller, uniformly and without distortion.
//   1.0  = design rendered at its authored size for the device width.
//   >1.0 = zoom in  (bigger fonts/components, less fits on screen).
//   <1.0 = zoom out (smaller, more fits on screen).
// Tuned for iPad 11"/Air (~1194pt) kiosk viewing distance. Bump toward 1.3 for
// even larger text; lower it if fixed-height content starts clipping at the
// bottom (zooming in trades vertical room for size).
const UI_ZOOM = 1.2;

// Scale the landscape design to fill the whole tablet screen.
//
// The design is authored in fixed points (Tailwind `text-2xl`, `w-[340px]`…),
// so we lay it out on a virtual canvas and uniformly scale that canvas to the
// physical screen. Anchoring to a single scalar keeps both axes in lock-step —
// no distortion — and deriving the canvas size from that scale means it always
// spans edge-to-edge with no letterbox bars. Dividing the canvas width by
// UI_ZOOM shrinks the canvas, which zooms the content up.
function ScaledRoot({ children }: { children: React.ReactNode }) {
  const { width, height } = useWindowDimensions();

  const scale = (width / BASE_WIDTH) * UI_ZOOM;
  // Canvas dimensions in design units that, once scaled, exactly fill the screen.
  const boxWidth = width / scale;
  const boxHeight = height / scale;

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
