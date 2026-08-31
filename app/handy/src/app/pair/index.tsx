import { useCallback, useEffect, useRef, useState } from 'react';
import { StyleSheet, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useLocalSearchParams, useRouter } from 'expo-router';

import Constants from 'expo-constants';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { ThemedText } from '@/components/ThemedText';
import { Layout, Radius, Spacing } from '@/constants/theme';
import { useT } from '@/i18n';
import { useTheme } from '@/hooks/use-theme';
import { setToken } from '@/lib/auth';
import { HANDY_DEVICE_TYPES, pair, type PairError422 } from '@/services/pairing-service';

const CODE_LENGTH = 6;

export default function PairScreen() {
  const t = useT();
  const theme = useTheme();
  const router = useRouter();
  const { reason } = useLocalSearchParams<{ reason?: string }>();

  const [cells, setCells] = useState<string[]>(Array(CODE_LENGTH).fill(''));
  const [focused, setFocused] = useState(0);
  const [loading, setLoading] = useState(false);
  // Seed the error from the redirect reason: a wrong-type device bounced from
  // /api/v1/handy/* lands here with reason=device_type and should see why.
  const [error, setError] = useState<string | null>(
    reason === 'device_type' ? t.pair.wrongDeviceType : null,
  );
  const inputRefs = useRef<(TextInput | null)[]>([]);
  const submittingRef = useRef(false);

  useEffect(() => {
    setTimeout(() => inputRefs.current[0]?.focus(), 200);
  }, []);

  const handleSubmit = useCallback(
    async (code: string) => {
      if (code.length !== CODE_LENGTH) return;
      if (submittingRef.current) return;
      submittingRef.current = true;
      setLoading(true);
      setError(null);
      try {
        const result = await pair(code, { app_version: Constants.expoConfig?.version ?? '0.0.0' });
        // Defence-in-depth: a current backend already rejects a wrong type with 422,
        // but guard the success path too so an older backend can't pair us into an
        // app where every screen 403s. Reset the cells (finally only clears loading).
        if (!(HANDY_DEVICE_TYPES as readonly string[]).includes(result.device.type)) {
          setError(t.pair.wrongDeviceType);
          setCells(Array(CODE_LENGTH).fill(''));
          setFocused(0);
          setTimeout(() => inputRefs.current[0]?.focus(), 50);
          return;
        }
        const workstationUrl = process.env.EXPO_PUBLIC_WS_URL?.trim() || undefined;
        await setToken(result.device_token, {
          id: result.device.id,
          name: result.device.name,
          type: result.device.type,
          branch_id: result.device.branch_id,
          branch: result.device.branch,
          shopSlug: result.device.branch?.slug ?? result.device.branch_id,
        }, workstationUrl);
        router.replace('/');
      } catch (err: unknown) {
        const apiErr = err as { status?: number; body?: unknown };
        if (apiErr.status === 422) {
          const body = apiErr.body as PairError422;
          setError(body?.errors?.pairing_code?.[0] ?? body?.message ?? t.pair.genericError);
        } else {
          setError(t.pair.genericError);
        }
        setCells(Array(CODE_LENGTH).fill(''));
        setFocused(0);
        setTimeout(() => inputRefs.current[0]?.focus(), 50);
      } finally {
        submittingRef.current = false;
        setLoading(false);
      }
    },
    [router, t],
  );

  const handleChange = useCallback(
    (index: number, value: string) => {
      if (submittingRef.current) return;
      const cleaned = value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
      if (cleaned.length > 1) {
        const filled = cleaned.slice(0, CODE_LENGTH).split('');
        const next = Array(CODE_LENGTH).fill('').map((_, i) => filled[i] ?? '');
        setCells(next);
        const nextFocus = Math.min(filled.length, CODE_LENGTH - 1);
        setFocused(nextFocus);
        inputRefs.current[nextFocus]?.focus();
        return;
      }
      const char = cleaned.slice(-1);
      const next = [...cells];
      next[index] = char;
      setCells(next);
      if (char && index < CODE_LENGTH - 1) {
        const nextIdx = index + 1;
        setFocused(nextIdx);
        inputRefs.current[nextIdx]?.focus();
      }
    },
    [cells],
  );

  const handleKeyPress = useCallback(
    (index: number, key: string) => {
      if (key === 'Backspace' && !cells[index] && index > 0) {
        const prevIdx = index - 1;
        const next = [...cells];
        next[prevIdx] = '';
        setCells(next);
        setFocused(prevIdx);
        inputRefs.current[prevIdx]?.focus();
      }
    },
    [cells],
  );

  const code = cells.join('');

  return (
    <SafeAreaView style={[styles.container, { backgroundColor: theme.background }]}>
      <View style={styles.inner}>
        <ThemedText type="title">{t.pair.title}</ThemedText>
        <ThemedText type="small" themeColor="textSecondary" style={styles.instructions}>
          {t.pair.instructions}
        </ThemedText>

        <View style={styles.codeRow}>
          {cells.map((cell, i) => (
            <TextInput
              key={i}
              ref={(ref) => { inputRefs.current[i] = ref; }}
              style={[
                styles.cell,
                { borderColor: theme.border, backgroundColor: theme.card, color: theme.text },
                focused === i && { borderColor: theme.primary, borderWidth: 2 },
                error !== null && { borderColor: theme.error },
              ]}
              value={cell}
              onChangeText={(v) => handleChange(i, v)}
              onKeyPress={({ nativeEvent }) => handleKeyPress(i, nativeEvent.key)}
              onFocus={() => setFocused(i)}
              maxLength={1}
              autoCapitalize="characters"
              autoCorrect={false}
              keyboardType="default"
              returnKeyType="done"
              editable={!loading}
              selectTextOnFocus
              caretHidden
            />
          ))}
        </View>

        {error !== null && (
          <Alert variant="error" message={error} style={styles.errorAlert} />
        )}

        <Button
          variant="primary"
          size="lg"
          label={t.pair.submit}
          onPress={() => handleSubmit(code)}
          loading={loading}
          disabled={code.length < CODE_LENGTH}
          fullWidth
          style={styles.button}
        />
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  inner: {
    flex: 1,
    paddingHorizontal: Layout.screenPaddingH,
    justifyContent: 'center',
    alignItems: 'stretch',
    gap: Spacing.lg,
  },
  instructions: { textAlign: 'center', lineHeight: 20 },
  codeRow: { flexDirection: 'row', gap: 8, marginTop: Spacing.sm, justifyContent: 'center' },
  cell: {
    width: 44,
    height: 52,
    borderWidth: 1.5,
    borderRadius: Radius.md,
    fontSize: 22,
    fontWeight: '700',
    textAlign: 'center',
  },
  errorAlert: { width: '100%' },
  button: { marginTop: Spacing.sm },
});
