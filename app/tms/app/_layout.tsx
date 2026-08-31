import "../global.css";

import { Stack } from "expo-router";
import { StatusBar } from "expo-status-bar";
import { SafeAreaProvider } from "react-native-safe-area-context";
import { AppProvider } from "../src/providers/app-provider";
import { AuthProvider } from "../src/providers/auth-provider";
import { QueryProvider } from "../src/providers/query-provider";
import { ErrorBoundary } from "../src/components/error-boundary";

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <ErrorBoundary>
        <AppProvider>
          <QueryProvider>
            <AuthProvider>
            <StatusBar style="dark" />
            <Stack
              screenOptions={{
                headerShown: false,
                animation: "fade",
              }}
            />
            </AuthProvider>
          </QueryProvider>
        </AppProvider>
      </ErrorBoundary>
    </SafeAreaProvider>
  );
}
