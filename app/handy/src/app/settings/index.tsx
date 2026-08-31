import { Alert, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useRouter } from 'expo-router';
import Feather from '@expo/vector-icons/Feather';

import { Button } from '@/components/ui/button';
import { RadioGroup } from '@/components/ui/radio-group';
import { ThemedText } from '@/components/ThemedText';
import { Layout, Radius, Spacing } from '@/constants/theme';
import { useLocale, useT } from '@/i18n';
import { useTheme } from '@/hooks/use-theme';
import { useColorSchemeOverride } from '@/hooks/use-color-scheme';
import { useSync } from '@/hooks/use-sync';
import { clearToken } from '@/lib/auth';
import type { Locale } from '@/i18n';
import type { ColorSchemeOverride } from '@/hooks/use-color-scheme';

const LOCALES: { label: string; value: Locale }[] = [
  { label: '日本語', value: 'ja' },
  { label: 'Tiếng Việt', value: 'vi' },
];

type ThemeOption = {
  label: string;
  value: ColorSchemeOverride;
  icon: 'sun' | 'moon' | 'smartphone';
};

export default function SettingsScreen() {
  const theme = useTheme();
  const router = useRouter();
  const [locale, setLocale] = useLocale();
  const [colorScheme, setColorScheme] = useColorSchemeOverride();
  const t = useT();
  const { sync, syncing } = useSync();

  const isJa = locale === 'ja';

  const THEMES: ThemeOption[] = [
    { label: isJa ? 'ライト'   : 'Sáng',         value: 'light',  icon: 'sun' },
    { label: isJa ? 'ダーク'   : 'Tối',           value: 'dark',   icon: 'moon' },
    { label: isJa ? 'システム' : 'Theo hệ thống', value: 'system', icon: 'smartphone' },
  ];

  async function handleSync() {
    try {
      await sync();
      Alert.alert('', t.settings.syncSuccess);
    } catch {
      Alert.alert('', t.settings.syncError);
    }
  }

  function handleUnpair() {
    Alert.alert(
      t.settings.unpairConfirmTitle,
      t.settings.unpairConfirmMessage,
      [
        { text: t.cancel, style: 'cancel' },
        {
          text: t.settings.unpairConfirm,
          style: 'destructive',
          onPress: async () => {
            await clearToken();
            router.replace('/pair');
          },
        },
      ],
    );
  }

  return (
    <SafeAreaView style={[styles.container, { backgroundColor: theme.backgroundElement }]} edges={['top', 'bottom']}>
      <View style={[styles.header, { backgroundColor: theme.background, borderBottomColor: theme.borderSoft }]}>
        <Pressable
          onPress={() => router.back()}
          hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
          style={styles.backButton}
        >
          <Feather name="chevron-left" size={24} color={theme.primary} />
        </Pressable>
        <View style={styles.headerTitles}>
          <ThemedText style={[styles.headerTitle, { color: theme.text }]}>
            {t.settings.title}
          </ThemedText>
          <ThemedText style={[styles.headerSubtitle, { color: theme.textSecondary }]}>
            {t.settings.subtitle}
          </ThemedText>
        </View>
      </View>

      <ScrollView contentContainerStyle={styles.content}>
        {/* Language */}
        <ThemedText style={[styles.sectionHeader, { color: theme.textSecondary }]}>
          {isJa ? '言語' : 'NGÔN NGỮ'}
        </ThemedText>
        <RadioGroup<Locale>
          options={LOCALES.map((l) => ({ value: l.value, label: l.label }))}
          value={locale}
          onValueChange={setLocale}
        />

        {/* Theme */}
        <ThemedText style={[styles.sectionHeader, { color: theme.textSecondary, marginTop: Spacing.lg }]}>
          {isJa ? '表示' : 'GIAO DIỆN'}
        </ThemedText>
        <RadioGroup<ColorSchemeOverride>
          options={THEMES.map((th) => ({ value: th.value, label: th.label, icon: th.icon }))}
          value={colorScheme}
          onValueChange={setColorScheme}
        />

        {/* Data */}
        <ThemedText style={[styles.sectionHeader, { color: theme.textSecondary, marginTop: Spacing.lg }]}>
          {t.settings.dataSection}
        </ThemedText>
        <View style={[styles.card, { backgroundColor: theme.card }]}>
          <Button
            variant="ghost"
            label={syncing ? t.settings.syncingButton : t.settings.syncButton}
            onPress={handleSync}
            loading={syncing}
            disabled={syncing}
            fullWidth
            style={styles.actionRow}
            textStyle={{ color: theme.primary }}
          />
        </View>

        {/* Device */}
        <ThemedText style={[styles.sectionHeader, { color: theme.textSecondary, marginTop: Spacing.lg }]}>
          {t.settings.deviceSection}
        </ThemedText>
        <View style={[styles.card, { backgroundColor: theme.card }]}>
          <Button
            variant="ghost"
            label={t.settings.unpairButton}
            onPress={handleUnpair}
            fullWidth
            style={styles.actionRow}
            textStyle={{ color: theme.error }}
          />
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  header: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: Layout.screenPaddingH,
    paddingVertical: Spacing.sm,
    borderBottomWidth: StyleSheet.hairlineWidth,
    gap: Spacing.sm,
  },
  backButton: { paddingRight: Spacing.xs },
  headerTitles: { flex: 1, gap: 2 },
  headerTitle: { fontSize: 17, fontWeight: '700', lineHeight: 22 },
  headerSubtitle: { fontSize: 12, lineHeight: 16, fontWeight: '400' },
  content: {
    paddingHorizontal: Layout.screenPaddingH,
    paddingTop: Spacing.lg,
    paddingBottom: Spacing.xl,
  },
  sectionHeader: {
    fontSize: 11,
    fontWeight: '600',
    textTransform: 'uppercase',
    letterSpacing: 0.6,
    paddingHorizontal: Spacing.xs,
    marginBottom: Spacing.xs,
  },
  card: {
    borderRadius: Radius.lg,
    overflow: 'hidden',
  },
  actionRow: {
    justifyContent: 'flex-start',
    paddingHorizontal: Spacing.lg,
    height: 50,
  },
});
