import { useRouter } from "expo-router";
import { useEffect, useState } from "react";
import {
  ActivityIndicator,
  FlatList,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from "react-native";
import { useWorkstation } from "@/src/providers/workstation-provider";
import { normalizeBaseUrl } from "@/src/lib/url";
import { workstationDiscovery } from "@/src/services/workstation/discovery";
import type { WorkstationInfo } from "@/src/services/workstation/types";

export default function SetupScreen() {
  const router = useRouter();
  const { selectWorkstation, baseUrl } = useWorkstation();
  const [found, setFound] = useState<WorkstationInfo[]>([]);
  const [manual, setManual] = useState(baseUrl ?? "");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    workstationDiscovery.start();
    const unsub = workstationDiscovery.onChange(setFound);
    return () => {
      unsub();
      workstationDiscovery.stop();
    };
  }, []);

  const connect = async (url: string) => {
    setBusy(true);
    setError(null);
    const ok = await selectWorkstation(url);
    setBusy(false);
    if (!ok) {
      setError("Workstation unreachable. Check IP:port and that /api/lan/health responds.");
      return;
    }
    router.replace("/pos");
  };

  return (
    <View style={styles.root}>
      <Text style={styles.title}>Connect to Workstation</Text>
      <Text style={styles.sub}>
        POS runs inside the workstation at /pos. Discover via mDNS or enter the LAN URL.
      </Text>

      <Text style={styles.section}>Discovered on LAN</Text>
      {found.length === 0 ? (
        <Text style={styles.hint}>
          Scanning for _ws-app._tcp… (requires a custom/dev client build; Expo Go has no mDNS.)
        </Text>
      ) : (
        <FlatList
          data={found}
          keyExtractor={(item) => item.baseUrl}
          style={styles.list}
          renderItem={({ item }) => (
            <Pressable
              style={styles.row}
              disabled={busy}
              onPress={() => connect(item.baseUrl)}
            >
              <View style={{ flex: 1 }}>
                <Text style={styles.rowTitle}>{item.name}</Text>
                <Text style={styles.rowMeta}>
                  {item.baseUrl}
                  {item.version ? ` · v${item.version}` : ""}
                </Text>
              </View>
              <Text style={styles.chevron}>→</Text>
            </Pressable>
          )}
        />
      )}

      <Text style={styles.section}>Manual URL</Text>
      <TextInput
        style={styles.input}
        autoCapitalize="none"
        autoCorrect={false}
        placeholder="192.168.1.10:8080"
        value={manual}
        onChangeText={setManual}
        editable={!busy}
      />
      <Pressable
        style={[styles.button, busy && styles.buttonDisabled]}
        disabled={busy || !manual.trim()}
        onPress={() => connect(normalizeBaseUrl(manual))}
      >
        {busy ? (
          <ActivityIndicator color="#fff" />
        ) : (
          <Text style={styles.buttonText}>Connect</Text>
        )}
      </Pressable>

      {error ? <Text style={styles.error}>{error}</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: "#f5f5f4",
    paddingHorizontal: 32,
    paddingVertical: 28,
    gap: 10,
  },
  title: {
    fontSize: 28,
    fontWeight: "700",
    color: "#1c1917",
  },
  sub: {
    fontSize: 15,
    color: "#57534e",
    marginBottom: 8,
    maxWidth: 640,
  },
  section: {
    marginTop: 12,
    fontSize: 13,
    fontWeight: "600",
    color: "#78716c",
    textTransform: "uppercase",
    letterSpacing: 0.6,
  },
  hint: {
    fontSize: 14,
    color: "#a8a29e",
    paddingVertical: 8,
  },
  list: {
    maxHeight: 220,
  },
  row: {
    flexDirection: "row",
    alignItems: "center",
    backgroundColor: "#fff",
    borderRadius: 10,
    paddingHorizontal: 16,
    paddingVertical: 14,
    marginBottom: 8,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: "#e7e5e4",
  },
  rowTitle: {
    fontSize: 16,
    fontWeight: "600",
    color: "#1c1917",
  },
  rowMeta: {
    fontSize: 13,
    color: "#78716c",
    marginTop: 2,
  },
  chevron: {
    fontSize: 20,
    color: "#a8a29e",
    marginLeft: 12,
  },
  input: {
    backgroundColor: "#fff",
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: "#d6d3d1",
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 16,
    color: "#1c1917",
  },
  button: {
    marginTop: 4,
    backgroundColor: "#1c1917",
    borderRadius: 10,
    paddingVertical: 14,
    alignItems: "center",
    maxWidth: 280,
  },
  buttonDisabled: {
    opacity: 0.5,
  },
  buttonText: {
    color: "#fff",
    fontSize: 16,
    fontWeight: "600",
  },
  error: {
    marginTop: 8,
    color: "#b91c1c",
    fontSize: 14,
  },
});
