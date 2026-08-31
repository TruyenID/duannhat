import { useRouter } from "expo-router";
import { useCallback, useRef, useState } from "react";
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  Text,
  View,
} from "react-native";
import { WebView } from "react-native-webview";
import * as WebBrowser from "expo-web-browser";
import { useWorkstation } from "@/src/providers/workstation-provider";
import { isWithinBase, posUrlFromBase } from "@/src/lib/url";

const TAP_WINDOW_MS = 2500;
const TAPS_TO_OPEN_SETTINGS = 5;

export default function PosWebViewScreen() {
  const router = useRouter();
  const { baseUrl, healthy, refreshHealth } = useWorkstation();
  const [loading, setLoading] = useState(true);
  const [webError, setWebError] = useState<string | null>(null);
  const tapCount = useRef(0);
  const tapTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const uri = baseUrl ? posUrlFromBase(baseUrl) : null;

  const onCornerTap = useCallback(() => {
    tapCount.current += 1;
    if (tapTimer.current) clearTimeout(tapTimer.current);
    tapTimer.current = setTimeout(() => {
      tapCount.current = 0;
    }, TAP_WINDOW_MS);
    if (tapCount.current >= TAPS_TO_OPEN_SETTINGS) {
      tapCount.current = 0;
      router.push("/settings");
    }
  }, [router]);

  /**
   * Origin lock. This tablet has no address bar and no back gesture, so a
   * navigation off the workstation is a dead end the cashier cannot leave —
   * their only escape is the 5-tap settings corner. Anything http(s) outside
   * the workstation opens in a dismissible in-app browser instead, so a stray
   * link is still followable but never replaces the POS. Non-http schemes
   * (about:blank, data:) are the WebView's own internals and pass through.
   */
  const onShouldStartLoad = useCallback(
    (request: { url: string }) => {
      const target = request.url;
      if (!baseUrl || !/^https?:/i.test(target)) return true;
      if (isWithinBase(target, baseUrl)) return true;
      console.warn("[pos-shell] blocked navigation off the workstation", target);
      WebBrowser.openBrowserAsync(target).catch(() => {
        /* no browser available — the block itself is the point */
      });
      return false;
    },
    [baseUrl],
  );

  const retry = useCallback(async () => {
    setWebError(null);
    setLoading(true);
    const ok = await refreshHealth();
    if (!ok) {
      setLoading(false);
      setWebError("Workstation health check failed.");
    }
  }, [refreshHealth]);

  if (!uri) {
    return (
      <View style={styles.center}>
        <Text style={styles.error}>No workstation configured.</Text>
        <Pressable style={styles.button} onPress={() => router.replace("/setup")}>
          <Text style={styles.buttonText}>Open setup</Text>
        </Pressable>
      </View>
    );
  }

  if (webError) {
    return (
      <View style={styles.center}>
        <Text style={styles.error}>{webError}</Text>
        {!healthy ? (
          <Text style={styles.meta}>Last health check: unreachable</Text>
        ) : null}
        <Pressable style={styles.button} onPress={retry}>
          <Text style={styles.buttonText}>Retry</Text>
        </Pressable>
        <Pressable style={styles.link} onPress={() => router.replace("/setup")}>
          <Text style={styles.linkText}>Change workstation</Text>
        </Pressable>
      </View>
    );
  }

  return (
    <View style={styles.root}>
      <WebView
        source={{ uri }}
        style={styles.webview}
        onLoadStart={() => {
          setLoading(true);
          setWebError(null);
        }}
        onLoadEnd={() => setLoading(false)}
        onError={() => {
          setLoading(false);
          setWebError("Failed to load POS. Is the workstation online?");
        }}
        onHttpError={(e) => {
          if (e.nativeEvent.statusCode >= 400) {
            setLoading(false);
            setWebError(`POS returned HTTP ${e.nativeEvent.statusCode}`);
          }
        }}
        onShouldStartLoadWithRequest={onShouldStartLoad}
        allowsBackForwardNavigationGestures={false}
        setSupportMultipleWindows={false}
        javaScriptEnabled
        domStorageEnabled
        sharedCookiesEnabled
        thirdPartyCookiesEnabled
        // The workstation serves /pos over plain http on the LAN by design
        // (#1169), so the page itself is never https and there is no mixed
        // content to permit. "never" keeps that true if the base ever becomes
        // https — an https POS pulling http subresources is a downgrade the
        // shell should not silently allow.
        originWhitelist={["http://*", "https://*"]}
        mixedContentMode="never"
        allowFileAccess={false}
      />

      {loading ? (
        <View style={styles.overlay} pointerEvents="none">
          <ActivityIndicator size="large" color="#1c1917" />
        </View>
      ) : null}

      {/* Secret corner: 5 taps → settings */}
      <Pressable
        accessibilityLabel="Open shell settings"
        style={styles.secretCorner}
        onPress={onCornerTap}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  root: {
    flex: 1,
    backgroundColor: "#fff",
  },
  webview: {
    flex: 1,
  },
  overlay: {
    ...StyleSheet.absoluteFill,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "rgba(245,245,244,0.7)",
  },
  secretCorner: {
    position: "absolute",
    top: 0,
    right: 0,
    width: 48,
    height: 48,
  },
  center: {
    flex: 1,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "#f5f5f4",
    gap: 12,
    padding: 24,
  },
  error: {
    fontSize: 16,
    color: "#b91c1c",
    textAlign: "center",
  },
  meta: {
    fontSize: 14,
    color: "#78716c",
  },
  button: {
    backgroundColor: "#1c1917",
    borderRadius: 10,
    paddingHorizontal: 20,
    paddingVertical: 12,
  },
  buttonText: {
    color: "#fff",
    fontWeight: "600",
    fontSize: 16,
  },
  link: {
    padding: 8,
  },
  linkText: {
    color: "#57534e",
    fontSize: 15,
    textDecorationLine: "underline",
  },
});
