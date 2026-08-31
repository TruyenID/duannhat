import { Redirect } from "expo-router";
import { ActivityIndicator, StyleSheet, Text, View } from "react-native";
import { useWorkstation } from "@/src/providers/workstation-provider";

export default function BootScreen() {
  const { bootStatus } = useWorkstation();

  if (bootStatus === "loading") {
    return (
      <View style={styles.center}>
        <ActivityIndicator size="large" color="#1a1a1a" />
        <Text style={styles.label}>Connecting…</Text>
      </View>
    );
  }

  if (bootStatus === "ready") {
    return <Redirect href="/pos" />;
  }

  return <Redirect href="/setup" />;
}

const styles = StyleSheet.create({
  center: {
    flex: 1,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "#f5f5f4",
    gap: 12,
  },
  label: {
    fontSize: 16,
    color: "#57534e",
  },
});
