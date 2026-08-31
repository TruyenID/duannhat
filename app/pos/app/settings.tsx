import { useRouter } from "expo-router";
import { useState } from "react";
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  View,
} from "react-native";
import { useWorkstation } from "@/src/providers/workstation-provider";

export default function SettingsScreen() {
  const router = useRouter();
  const { baseUrl, healthy, refreshHealth, clearWorkstation } = useWorkstation();
  const [checking, setChecking] = useState(false);
  const [lastOk, setLastOk] = useState<boolean | null>(healthy);

  const onRefresh = async () => {
    setChecking(true);
    const ok = await refreshHealth();
    setLastOk(ok);
    setChecking(false);
  };

  const onChangeWorkstation = async () => {
    await clearWorkstation();
    router.replace("/setup");
  };

  return (
    <View style={styles.root}>
      <Text style={styles.title}>POS shell settings</Text>
      <Text style={styles.sub}>
        Device pairing and sales stay inside the WebView. This screen only manages the
        workstation connection.
      </Text>

      <View style={styles.card}>
        <Text style={styles.label}>Workstation</Text>
        <Text style={styles.value}>{baseUrl ?? "—"}</Text>
        <Text style={styles.label}>Health</Text>
        <Text style={[styles.value, lastOk === false && styles.bad]}>
          {lastOk == null ? "unknown" : lastOk ? "ok" : "unreachable"}
        </Text>
      </View>

      <Pressable style={styles.button} onPress={onRefresh} disabled={checking}>
        {checking ? (
          <ActivityIndicator color="#fff" />
        ) : (
          <Text style={styles.buttonText}>Recheck health</Text>
        )}
      </Pressable>

      <Pressable style={styles.button} onPress={() => router.replace("/pos")}>
        <Text style={styles.buttonText}>Reload POS</Text>
      </Pressable>

      <Pressable style={[styles.button, styles.secondary]} onPress={onChangeWorkstation}>
        <Text style={[styles.buttonText, styles.secondaryText]}>Change workstation</Text>
      </Pressable>

      <Pressable style={styles.link} onPress={() => router.back()}>
        <Text style={styles.linkText}>Back</Text>
      </Pressable>
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: "#f5f5f4",
    paddingHorizontal: 32,
    paddingVertical: 28,
    gap: 12,
  },
  title: {
    fontSize: 26,
    fontWeight: "700",
    color: "#1c1917",
  },
  sub: {
    fontSize: 14,
    color: "#57534e",
    maxWidth: 560,
    marginBottom: 8,
  },
  card: {
    backgroundColor: "#fff",
    borderRadius: 12,
    padding: 16,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: "#e7e5e4",
    gap: 4,
    maxWidth: 560,
  },
  label: {
    marginTop: 8,
    fontSize: 12,
    fontWeight: "600",
    color: "#a8a29e",
    textTransform: "uppercase",
  },
  value: {
    fontSize: 16,
    color: "#1c1917",
  },
  bad: {
    color: "#b91c1c",
  },
  button: {
    backgroundColor: "#1c1917",
    borderRadius: 10,
    paddingVertical: 14,
    paddingHorizontal: 18,
    alignItems: "center",
    maxWidth: 280,
  },
  buttonText: {
    color: "#fff",
    fontSize: 16,
    fontWeight: "600",
  },
  secondary: {
    backgroundColor: "#fff",
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: "#d6d3d1",
  },
  secondaryText: {
    color: "#1c1917",
  },
  link: {
    paddingVertical: 8,
  },
  linkText: {
    color: "#57534e",
    fontSize: 15,
    textDecorationLine: "underline",
  },
});
