import { Stack } from "expo-router";
import * as SplashScreen from "expo-splash-screen";
import { StatusBar } from "expo-status-bar";
import { useEffect } from "react";
import { WorkstationProvider } from "@/src/providers/workstation-provider";

export { ErrorBoundary } from "expo-router";

SplashScreen.preventAutoHideAsync();

export default function RootLayout() {
  useEffect(() => {
    SplashScreen.hideAsync();
  }, []);

  return (
    <WorkstationProvider>
      <StatusBar hidden />
      <Stack screenOptions={{ headerShown: false, animation: "fade" }}>
        <Stack.Screen name="index" />
        <Stack.Screen name="setup" />
        <Stack.Screen name="pos" />
        <Stack.Screen name="settings" />
      </Stack>
    </WorkstationProvider>
  );
}
