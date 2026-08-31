import React, { createContext, useContext, useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, useColorScheme, View } from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import { DarkTheme, DefaultTheme, ThemeProvider } from 'expo-router';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { Stack } from 'expo-router';

import { Colors } from '@/constants/colors';
import { Radius, Spacing } from '@/constants/theme';
import { ja } from '@/i18n/ja';
import { setLocale } from '@/i18n';
import { onForbidden, onUnauthorized } from '@/lib/api';
import { clearToken, getToken } from '@/lib/auth';
import { DeviceProvider, useDevice } from '@/lib/device-context';
import { useWorkstationSocket } from '@/hooks/use-workstation-socket';
import { setColorSchemeOverride } from '@/hooks/use-color-scheme';
import { loadAllPreferences } from '@/lib/preferences';

// Minimal context so hooks (useOpenOrders) can read WS connection state
// without prop-drilling. Avoid Zustand for a single boolean.
const SocketContext = createContext(false);
export function useSocketConnected() { return useContext(SocketContext); }

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, staleTime: 30_000 },
  },
});

interface ErrorBoundaryState {
  hasError: boolean;
  error: Error | null;
}

class ErrorBoundary extends React.Component<{ children: React.ReactNode }, ErrorBoundaryState> {
  constructor(props: { children: React.ReactNode }) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { hasError: true, error };
  }

  handleRetry = () => { this.setState({ hasError: false, error: null }); };

  render() {
    if (this.state.hasError) {
      return (
        <SafeAreaView style={styles.errorContainer}>
          <Text style={styles.errorTitle}>{ja.errorBoundary.title}</Text>
          <Pressable style={styles.retryBtn} onPress={this.handleRetry}>
            <Text style={styles.retryBtnText}>{ja.errorBoundary.retry}</Text>
          </Pressable>
        </SafeAreaView>
      );
    }
    return this.props.children;
  }
}

function SocketBridge({ onConnected }: { onConnected: (v: boolean) => void }) {
  const { shopSlug } = useDevice();
  const { isConnected } = useWorkstationSocket(shopSlug);
  useEffect(() => { onConnected(isConnected); }, [isConnected, onConnected]);
  return null;
}

function AuthGate({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const colorScheme = useColorScheme();
  const [checking, setChecking] = useState(true);
  const [authed, setAuthed] = useState(false);
  const [wsConnected, setWsConnected] = useState(false);
  const theme = colorScheme === 'dark' ? Colors.dark : Colors.light;

  useEffect(() => {
    let cancelled = false;
    getToken().then((token) => {
      if (cancelled) return;
      setChecking(false);
      if (!token) router.replace('/pair');
      else setAuthed(true);
    });
    const unsubscribe = onUnauthorized(async () => {
      setAuthed(false);
      setWsConnected(false);
      await clearToken();
      router.replace('/pair');
    });
    // Wrong device type paired (403 DEVICE_TYPE_NOT_ALLOWED) → same recovery as
    // 401, but flag the reason so /pair can explain why the token was dropped.
    const unsubscribeForbidden = onForbidden(async () => {
      setAuthed(false);
      setWsConnected(false);
      await clearToken();
      router.replace({ pathname: '/pair', params: { reason: 'device_type' } });
    });
    return () => { cancelled = true; unsubscribe(); unsubscribeForbidden(); };
  }, [router]);

  if (checking) {
    return (
      <View style={[styles.loadingContainer, { backgroundColor: theme.background }]}>
        <ActivityIndicator color={theme.primary} size="large" />
      </View>
    );
  }

  return (
    <SocketContext.Provider value={wsConnected}>
      {authed && <SocketBridge onConnected={setWsConnected} />}
      {children}
    </SocketContext.Provider>
  );
}

export default function RootLayout() {
  const colorScheme = useColorScheme();
  const [prefsLoaded, setPrefsLoaded] = useState(false);

  useEffect(() => {
    loadAllPreferences().then(({ theme, locale }) => {
      setColorSchemeOverride(theme, false);
      setLocale(locale, false);
      setPrefsLoaded(true);
    });
  }, []);

  if (!prefsLoaded) {
    return (
      <View style={styles.loadingContainer}>
        <ActivityIndicator size="large" />
      </View>
    );
  }

  return (
    <SafeAreaProvider>
      <ThemeProvider value={colorScheme === 'dark' ? DarkTheme : DefaultTheme}>
        <ErrorBoundary>
          <QueryClientProvider client={queryClient}>
            <DeviceProvider>
              <AuthGate>
                <Stack screenOptions={{ headerShown: false }} />
              </AuthGate>
            </DeviceProvider>
          </QueryClientProvider>
        </ErrorBoundary>
      </ThemeProvider>
    </SafeAreaProvider>
  );
}

const styles = StyleSheet.create({
  loadingContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
  },
  errorContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    padding: Spacing.xl,
    gap: Spacing.md,
  },
  errorTitle: {
    fontSize: 16,
    fontWeight: '600',
  },
  retryBtn: {
    marginTop: Spacing.sm,
    paddingHorizontal: Spacing.xl,
    paddingVertical: Spacing.sm,
    borderRadius: Radius.md,
    backgroundColor: Colors.light.primary,
  },
  retryBtnText: {
    color: Colors.light.background,
    fontWeight: '600',
    fontSize: 14,
  },
});
