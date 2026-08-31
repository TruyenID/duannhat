import { Redirect } from "expo-router";
import { View } from "react-native";
import { useAuth } from "../src/providers/auth-provider";
import { Text } from "@godxjp/ui-native";

export default function Index() {
  const { isLoading, isAuthenticated } = useAuth();

  if (isLoading) {
    return (
      <View className="flex-1 bg-background items-center justify-center">
        <Text variant="muted">Loading...</Text>
      </View>
    );
  }

  if (isAuthenticated) {
    return <Redirect href="/home" />;
  }

  return <Redirect href="/login" />;
}
