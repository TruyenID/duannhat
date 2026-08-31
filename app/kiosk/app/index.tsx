// app/index.tsx
import { Redirect } from "expo-router";
import { View } from "react-native";
import { useAuth } from "../src/providers/auth-provider";
import { usePasscode } from "../src/hooks/use-passcode";
import { resolveKioskEntry } from "../src/lib/passcode-flow";
import { Text } from "@godxjp/ui-native";

export default function Index() {
  const { isLoading: authLoading, isAuthenticated } = useAuth();
  const { isLoading: passcodeLoading, isConfigured } = usePasscode();

  if (authLoading || passcodeLoading) {
    return (
      <View className="flex-1 bg-background items-center justify-center">
        <Text variant="muted">{/* loading handled by auth provider */}</Text>
      </View>
    );
  }

  return <Redirect href={resolveKioskEntry(isAuthenticated, isConfigured)} />;
}
